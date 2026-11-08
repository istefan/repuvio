<?php
/**
 * Security layer: HTTP headers + CSP nonce, CSRF, rate limiting, password hashing,
 * secret encryption at rest and audit logging.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

/*
 * -----------------------------------------------------------------------------
 * HTTP security headers
 * -----------------------------------------------------------------------------
 */

/**
 * Per-request CSP nonce for inline scripts.
 *
 * @return string
 */
function csp_nonce() {
	static $nonce = null;

	if ( null === $nonce ) {
		$nonce = base64_encode( random_bytes( 18 ) );
	}

	return $nonce;
}

/**
 * Sends security headers (CSP, framing, sniffing, referrer, permissions).
 *
 * @return void
 */
function send_security_headers() {
	if ( headers_sent() ) {
		return;
	}

	$nonce = csp_nonce();
	$csp   = [
		"default-src 'self'",
		"script-src 'self' 'nonce-" . $nonce . "' https://cdn.jsdelivr.net",
		"style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com",
		"font-src 'self' data: https://cdn.jsdelivr.net https://fonts.gstatic.com",
		"img-src 'self' data: blob:",
		"connect-src 'self'",
		"form-action 'self' https://accounts.google.com",
		"frame-ancestors 'self'",
		"base-uri 'self'",
		"object-src 'none'",
	];

	header( 'Content-Security-Policy: ' . implode( '; ', $csp ) );
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()' );
	header( 'Cross-Origin-Opener-Policy: same-origin' );

	if ( is_https() ) {
		header( 'Strict-Transport-Security: max-age=31536000' );
	}

	header_remove( 'X-Powered-By' );
}

/**
 * Prevents caching of private pages.
 *
 * @return void
 */
function send_no_cache_headers() {
	if ( ! headers_sent() ) {
		header( 'Cache-Control: no-store, no-cache, must-revalidate, private' );
		header( 'Pragma: no-cache' );
	}
}

/**
 * Whether the current request is served over HTTPS.
 *
 * @return bool
 */
function is_https() {
	return ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( (string) $_SERVER['HTTPS'] ) )
		|| ( isset( $_SERVER['SERVER_PORT'] ) && 443 === (int) $_SERVER['SERVER_PORT'] );
}

/*
 * -----------------------------------------------------------------------------
 * CSRF
 * -----------------------------------------------------------------------------
 */

/**
 * Returns the session CSRF token (creates one if missing).
 *
 * @return string
 */
function csrf_token() {
	if ( empty( $_SESSION['csrf_token'] ) || ! is_string( $_SESSION['csrf_token'] ) ) {
		$_SESSION['csrf_token'] = bin2hex( random_bytes( 32 ) );
	}

	return $_SESSION['csrf_token'];
}

/**
 * Hidden CSRF input for forms.
 *
 * @return string HTML.
 */
function csrf_field() {
	return '<input type="hidden" name="_csrf" value="' . esc_attr( csrf_token() ) . '">';
}

/**
 * Verifies a CSRF token.
 *
 * @param mixed $token Submitted token.
 * @return bool
 */
function csrf_verify( $token ) {
	return is_string( $token )
		&& ! empty( $_SESSION['csrf_token'] )
		&& hash_equals( (string) $_SESSION['csrf_token'], $token );
}

/**
 * Verifies CSRF for every POST request (called from bootstrap). Stops with 403 on failure.
 *
 * @return void
 */
function csrf_verify_request() {
	if ( ! is_post() ) {
		return;
	}

	$token = $_POST['_csrf'] ?? ( $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '' );

	if ( ! csrf_verify( $token ) ) {
		if ( ! empty( $_SERVER['HTTP_X_REQUESTED_WITH'] ) ) {
			send_json( [ 'error' => 'csrf' ], 403 );
		}

		render_error_page( 403, 'Sesiune expirată', 'Formularul a expirat sau nu este valid. Reîncarcă pagina și încearcă din nou.' );
	}
}

/**
 * Creates a single-use, purpose-bound token stored in the session (e.g. OAuth state).
 *
 * @param string $purpose Purpose key.
 * @return string
 */
function create_onetime_token( $purpose ) {
	$token                                  = bin2hex( random_bytes( 32 ) );
	$_SESSION['onetime_tokens'][ $purpose ] = $token;

	return $token;
}

/**
 * Consumes and verifies a single-use token.
 *
 * @param string $purpose Purpose key.
 * @param mixed  $token   Submitted token.
 * @return bool
 */
function consume_onetime_token( $purpose, $token ) {
	$expected = $_SESSION['onetime_tokens'][ $purpose ] ?? '';
	unset( $_SESSION['onetime_tokens'][ $purpose ] );

	return is_string( $token ) && '' !== $expected && hash_equals( $expected, $token );
}

/*
 * -----------------------------------------------------------------------------
 * Rate limiting (IP + action)
 * -----------------------------------------------------------------------------
 */

/**
 * Whether the action is currently locked for this IP.
 *
 * @param string $action Action key.
 * @return bool
 */
function rate_limit_is_locked( $action ) {
	$locked_until = db_value(
		'SELECT locked_until FROM rate_limits WHERE ip_address = ? AND action = ? AND locked_until > NOW()',
		[ client_ip(), $action ]
	);

	return null !== $locked_until;
}

/**
 * Seconds remaining on a lock (0 when not locked).
 *
 * @param string $action Action key.
 * @return int
 */
function rate_limit_retry_after( $action ) {
	$seconds = db_value(
		'SELECT TIMESTAMPDIFF(SECOND, NOW(), locked_until) FROM rate_limits WHERE ip_address = ? AND action = ? AND locked_until > NOW()',
		[ client_ip(), $action ]
	);

	return max( 0, (int) $seconds );
}

/**
 * Records an attempt. Locks the action when $max attempts happen inside $window seconds.
 *
 * @param string $action           Action key.
 * @param int    $max              Max attempts per window.
 * @param int    $window_seconds   Window length.
 * @param int    $lockout_seconds  Lock duration.
 * @return bool True when the action is now locked.
 */
function rate_limit_hit( $action, $max, $window_seconds, $lockout_seconds ) {
	$ip = client_ip();

	// Reset the counter when the window expired, then increment atomically.
	db_query(
		'INSERT INTO rate_limits (ip_address, action, attempts, window_start) VALUES (?, ?, 1, NOW())
		ON DUPLICATE KEY UPDATE
			attempts = IF(window_start < NOW() - INTERVAL ? SECOND, 1, attempts + 1),
			window_start = IF(window_start < NOW() - INTERVAL ? SECOND, NOW(), window_start)',
		[ $ip, $action, (int) $window_seconds, (int) $window_seconds ]
	);

	$attempts = (int) db_value( 'SELECT attempts FROM rate_limits WHERE ip_address = ? AND action = ?', [ $ip, $action ] );

	if ( $attempts >= $max ) {
		db_query(
			'UPDATE rate_limits SET locked_until = NOW() + INTERVAL ? SECOND, attempts = 0, window_start = NOW() WHERE ip_address = ? AND action = ?',
			[ (int) $lockout_seconds, $ip, $action ]
		);

		return true;
	}

	return false;
}

/**
 * Clears attempts for an action (e.g. after a successful login).
 *
 * @param string $action Action key.
 * @return void
 */
function rate_limit_clear( $action ) {
	db_query( 'DELETE FROM rate_limits WHERE ip_address = ? AND action = ?', [ client_ip(), $action ] );
}

/*
 * -----------------------------------------------------------------------------
 * Passwords
 * -----------------------------------------------------------------------------
 */

/**
 * Preferred password algorithm and options (Argon2id, fallback Bcrypt cost 12).
 *
 * @return array{0:string|int,1:array}
 */
function password_algorithm() {
	if ( defined( 'PASSWORD_ARGON2ID' ) ) {
		return [ PASSWORD_ARGON2ID, [] ];
	}

	return [ PASSWORD_BCRYPT, [ 'cost' => 12 ] ];
}

/**
 * Hashes a password.
 *
 * @param string $password Plain password.
 * @return string
 */
function hash_user_password( $password ) {
	list( $algo, $options ) = password_algorithm();

	return password_hash( $password, $algo, $options );
}

/**
 * Whether a stored hash should be upgraded.
 *
 * @param string $hash Stored hash.
 * @return bool
 */
function password_hash_needs_upgrade( $hash ) {
	list( $algo, $options ) = password_algorithm();

	return password_needs_rehash( $hash, $algo, $options );
}

/**
 * Validates password strength. Returns an error message or empty string.
 *
 * @param string $password Password.
 * @param string $confirm  Confirmation.
 * @return string
 */
function validate_password_strength( $password, $confirm ) {
	if ( 10 > mb_strlen( $password, 'UTF-8' ) ) {
		return 'Parola trebuie să aibă cel puțin 10 caractere.';
	}
	if ( 200 < strlen( $password ) ) {
		return 'Parola este prea lungă.';
	}
	if ( ! preg_match( '/[A-Za-z]/', $password ) || ! preg_match( '/[0-9\W_]/', $password ) ) {
		return 'Parola trebuie să conțină litere și cel puțin o cifră sau un simbol.';
	}
	if ( $password !== $confirm ) {
		return 'Parolele nu coincid.';
	}

	return '';
}

/*
 * -----------------------------------------------------------------------------
 * Secret encryption at rest (AES-256-CBC + HMAC-SHA256, encrypt-then-MAC)
 * -----------------------------------------------------------------------------
 */

/**
 * Derives encryption and MAC keys from APP_KEY.
 *
 * The derivation labels are brand-independent ("app-secret") so renaming the product never
 * invalidates stored secrets. Secrets saved before schema v6 used the legacy brand labels and are
 * re-encrypted once by migrate_to_v6().
 *
 * @param string $label Derivation label prefix.
 * @return array{0:string,1:string}
 */
function secret_keys( $label = 'app-secret' ) {
	$master = base64_decode( APP_KEY, true );

	if ( false === $master || 32 > strlen( $master ) ) {
		$master = hash( 'sha256', APP_KEY, true );
	}

	return [
		hash_hmac( 'sha256', $label . '-enc', $master, true ),
		hash_hmac( 'sha256', $label . '-mac', $master, true ),
	];
}

/**
 * Encrypts a secret string.
 *
 * @param string $plaintext Plain text.
 * @return string Base64 payload (iv|mac|ciphertext), empty for empty input.
 */
function encrypt_secret( $plaintext ) {
	$plaintext = (string) $plaintext;

	if ( '' === $plaintext ) {
		return '';
	}

	list( $enc_key, $mac_key ) = secret_keys();

	$iv         = random_bytes( 16 );
	$ciphertext = openssl_encrypt( $plaintext, 'aes-256-cbc', $enc_key, OPENSSL_RAW_DATA, $iv );
	$mac        = hash_hmac( 'sha256', $iv . $ciphertext, $mac_key, true );

	return base64_encode( $iv . $mac . $ciphertext );
}

/**
 * Decrypts a secret produced by encrypt_secret().
 *
 * @param string $payload Base64 payload.
 * @param string $label   Derivation label prefix (legacy label only during migration).
 * @return string Plain text or empty string when invalid/tampered.
 */
function decrypt_secret( $payload, $label = 'app-secret' ) {
	$raw = base64_decode( (string) $payload, true );

	if ( false === $raw || 49 > strlen( $raw ) ) {
		return '';
	}

	list( $enc_key, $mac_key ) = secret_keys( $label );

	$iv         = substr( $raw, 0, 16 );
	$mac        = substr( $raw, 16, 32 );
	$ciphertext = substr( $raw, 48 );

	if ( ! hash_equals( hash_hmac( 'sha256', $iv . $ciphertext, $mac_key, true ), $mac ) ) {
		return '';
	}

	$plaintext = openssl_decrypt( $ciphertext, 'aes-256-cbc', $enc_key, OPENSSL_RAW_DATA, $iv );

	return false === $plaintext ? '' : $plaintext;
}

/*
 * -----------------------------------------------------------------------------
 * Audit log
 * -----------------------------------------------------------------------------
 */

/**
 * Writes a security audit entry. Never throws.
 *
 * @param string   $action         Action key.
 * @param string   $details        Details (no secrets).
 * @param int|null $target_user_id Affected user.
 * @param int|null $actor_id       Actor (defaults to the real logged-in user).
 * @return void
 */
function audit_log( $action, $details = '', $target_user_id = null, $actor_id = null ) {
	if ( null === $actor_id ) {
		$actor_id = $_SESSION['impersonator_id'] ?? ( $_SESSION['user_id'] ?? null );
	}

	try {
		db_query(
			'INSERT INTO audit_log (actor_id, target_user_id, action, details, ip_address) VALUES (?, ?, ?, ?, ?)',
			[
				null === $actor_id ? null : (int) $actor_id,
				null === $target_user_id ? null : (int) $target_user_id,
				substr( $action, 0, 60 ),
				mb_substr( $details, 0, 500 ),
				client_ip(),
			]
		);
	} catch ( Throwable $e ) {
		write_log( 'php-error.log', 'audit_log failed: ' . $e->getMessage() );
	}
}
