<?php
/**
 * Hardened session management: secure cookie flags, fingerprinting,
 * idle timeout and periodic ID rotation.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

/**
 * Starts the session with secure settings.
 *
 * @return void
 */
function start_secure_session() {
	if ( PHP_SESSION_ACTIVE === session_status() ) {
		return;
	}

	ini_set( 'session.use_strict_mode', '1' );
	ini_set( 'session.use_only_cookies', '1' );
	ini_set( 'session.use_trans_sid', '0' );
	ini_set( 'session.cookie_httponly', '1' );
	ini_set( 'session.gc_maxlifetime', (string) max( 1800, (int) SESSION_IDLE_TIMEOUT ) );

	$path = (string) parse_url( APP_URL, PHP_URL_PATH );

	session_name( 'repuvio_sid' );
	session_set_cookie_params(
		[
			'lifetime' => 0,
			'path'     => '' === $path ? '/' : rtrim( $path, '/' ) . '/',
			'domain'   => '',
			'secure'   => is_https(),
			'httponly' => true,
			'samesite' => 'Lax',
		]
	);

	session_start();

	enforce_session_integrity();
}

/**
 * Builds the session fingerprint from User-Agent and IP subnet (/24 IPv4, /64 IPv6).
 *
 * @return string
 */
function session_fingerprint() {
	$ip = client_ip();

	if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
		$subnet = implode( '.', array_slice( explode( '.', $ip ), 0, 3 ) );
	} else {
		$packed = inet_pton( $ip );
		$subnet = false === $packed ? $ip : bin2hex( substr( $packed, 0, 8 ) );
	}

	return hash( 'sha256', ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) . '|' . $subnet );
}

/**
 * Validates fingerprint and idle timeout; rotates the ID periodically.
 *
 * @return void
 */
function enforce_session_integrity() {
	$now         = time();
	$fingerprint = session_fingerprint();

	if ( ! isset( $_SESSION['fingerprint'] ) ) {
		$_SESSION['fingerprint']   = $fingerprint;
		$_SESSION['created_at']    = $now;
		$_SESSION['last_activity'] = $now;
		$_SESSION['rotated_at']    = $now;

		return;
	}

	$expired_idle = isset( $_SESSION['last_activity'] ) && (int) SESSION_IDLE_TIMEOUT < ( $now - (int) $_SESSION['last_activity'] );

	if ( ! hash_equals( (string) $_SESSION['fingerprint'], $fingerprint ) || $expired_idle ) {
		$was_logged_in = ! empty( $_SESSION['user_id'] );
		destroy_session();
		start_secure_session();

		if ( $was_logged_in ) {
			flash( 'warning', 'Sesiunea ta a expirat. Te rugăm să te autentifici din nou.' );
		}

		return;
	}

	$_SESSION['last_activity'] = $now;

	// Periodic rotation (every 15 minutes).
	if ( 900 < ( $now - (int) ( $_SESSION['rotated_at'] ?? 0 ) ) ) {
		regenerate_session();
	}
}

/**
 * Regenerates the session ID, deleting the old session.
 *
 * @return void
 */
function regenerate_session() {
	if ( PHP_SESSION_ACTIVE === session_status() ) {
		session_regenerate_id( true );
		$_SESSION['rotated_at'] = time();
	}
}

/**
 * Destroys the session and its cookie.
 *
 * @return void
 */
function destroy_session() {
	if ( PHP_SESSION_ACTIVE !== session_status() ) {
		return;
	}

	$_SESSION = [];

	$params = session_get_cookie_params();
	setcookie(
		session_name(),
		'',
		[
			'expires'  => time() - 42000,
			'path'     => $params['path'],
			'domain'   => $params['domain'],
			'secure'   => $params['secure'],
			'httponly' => $params['httponly'],
			'samesite' => $params['samesite'],
		]
	);

	session_destroy();
}
