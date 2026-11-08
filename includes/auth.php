<?php
/**
 * Authentication, 3-tier role guards (super_admin / user / operator) and Super Admin impersonation.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

const LOGIN_MAX_ATTEMPTS    = 5;
const LOGIN_WINDOW_SECONDS  = 900;
const LOGIN_LOCKOUT_SECONDS = 900;

/**
 * Returns the logged-in (or impersonated) user, validated against the DB on every request.
 *
 * @param bool $refresh Reload from DB.
 * @return array|null
 */
function current_user( $refresh = false ) {
	static $user   = null;
	static $loaded = false;

	if ( $loaded && ! $refresh ) {
		return $user;
	}

	$loaded = true;
	$user   = null;

	if ( empty( $_SESSION['user_id'] ) ) {
		return null;
	}

	$row = get_user_by_id( (int) $_SESSION['user_id'] );

	$valid = $row
		&& (int) $row['session_version'] === (int) ( $_SESSION['session_version'] ?? -1 )
		&& ( 'active' === $row['status'] || is_impersonating() );

	// Operators live only as long as their business owner account is active.
	if ( $valid && 'operator' === $row['role'] ) {
		$parent = empty( $row['parent_id'] ) ? null : get_user_by_id( (int) $row['parent_id'] );
		$valid  = $parent && 'user' === $parent['role'] && 'active' === $parent['status'];
	}

	if ( ! $valid ) {
		logout_user();
		flash( 'warning', 'Sesiunea nu mai este validă. Te rugăm să te autentifici din nou.' );

		return null;
	}

	// Privilege change detection (e.g. role edited by an admin) forces a new session ID.
	if ( isset( $_SESSION['role'] ) && $_SESSION['role'] !== $row['role'] ) {
		$_SESSION['role'] = $row['role'];
		regenerate_session();
	}

	if ( 'user' === $row['role'] ) {
		refresh_subscription_status( $row );
		roll_quota_cycle( $row );
	}

	$user = $row;

	return $user;
}

/**
 * Whether someone is logged in.
 *
 * @return bool
 */
function is_logged_in() {
	return null !== current_user();
}

/**
 * Attempts a login with rate limiting and enumeration-safe responses.
 *
 * @param string $email    Email.
 * @param string $password Password.
 * @return array{success:bool,error:string}
 */
function attempt_login( $email, $password ) {
	$generic = 'Email sau parolă incorectă.';

	if ( rate_limit_is_locked( 'login' ) ) {
		$minutes = (int) ceil( rate_limit_retry_after( 'login' ) / 60 );

		return [
			'success' => false,
			'error'   => 'Prea multe încercări eșuate. Încearcă din nou peste ' . max( 1, $minutes ) . ' minute.',
		];
	}

	$email = sanitize_email_address( $email );
	$user  = '' !== $email ? db_row( 'SELECT * FROM users WHERE email = ?', [ $email ] ) : null;

	// Verify against a dummy hash when the user does not exist to equalize timing.
	$hash  = $user ? $user['password_hash'] : '$2y$12$Ce1B2n7RJQz6JAT6TgD53.J5.O.BIuryB75OZHIVhq0AzDYk0iKRG';
	$valid = password_verify( (string) $password, $hash ) && null !== $user;

	if ( ! $valid ) {
		$locked = rate_limit_hit( 'login', LOGIN_MAX_ATTEMPTS, LOGIN_WINDOW_SECONDS, LOGIN_LOCKOUT_SECONDS );
		audit_log( 'login_failed', 'email=' . mb_substr( $email, 0, 120 ), $user ? (int) $user['id'] : null, 0 );

		return [
			'success' => false,
			'error'   => $locked ? 'Prea multe încercări eșuate. Contul este blocat temporar pentru 15 minute de pe această adresă IP.' : $generic,
		];
	}

	if ( 'active' !== $user['status'] ) {
		return [
			'success' => false,
			'error'   => 'Contul este suspendat. Contactează suportul.',
		];
	}

	if ( 'operator' === $user['role'] ) {
		$parent = empty( $user['parent_id'] ) ? null : get_user_by_id( (int) $user['parent_id'] );

		if ( ! $parent || 'active' !== $parent['status'] ) {
			return [
				'success' => false,
				'error'   => 'Contul de operator nu este activ. Contactează administratorul firmei.',
			];
		}
	}

	rate_limit_clear( 'login' );

	if ( password_hash_needs_upgrade( $user['password_hash'] ) ) {
		db_query( 'UPDATE users SET password_hash = ? WHERE id = ?', [ hash_user_password( $password ), (int) $user['id'] ] );
	}

	login_user( $user );
	audit_log( 'login', '', (int) $user['id'], (int) $user['id'] );

	return [
		'success' => true,
		'error'   => '',
	];
}

/**
 * Establishes an authenticated session.
 *
 * @param array $user User row.
 * @return void
 */
function login_user( array $user ) {
	regenerate_session();

	$_SESSION['user_id']            = (int) $user['id'];
	$_SESSION['role']               = $user['role'];
	$_SESSION['session_version']    = (int) $user['session_version'];
	$_SESSION['current_company_id'] = 0;
	$_SESSION['csrf_token']         = bin2hex( random_bytes( 32 ) );
	unset( $_SESSION['impersonator_id'], $_SESSION['impersonator_version'] );

	db_query( 'UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?', [ client_ip(), (int) $user['id'] ] );

	current_user( true );
}

/**
 * Logs out and destroys the session.
 *
 * @return void
 */
function logout_user() {
	destroy_session();
	start_secure_session();
}

/**
 * URL of the home screen for a role.
 *
 * @param array $user User row.
 * @return string
 */
function get_home_url( array $user ) {
	// Owners and operators share the dashboard; operators see only their assigned companies.
	return 'super_admin' === $user['role'] ? app_url( 'admin/index.php' ) : app_url( 'dashboard/index.php' );
}

/**
 * Current request URL (for post-login redirects).
 *
 * @return string
 */
function current_request_url() {
	$scheme = is_https() ? 'https' : 'http';
	$host   = (string) parse_url( APP_URL, PHP_URL_HOST );
	$port   = parse_url( APP_URL, PHP_URL_PORT );

	return $scheme . '://' . $host . ( $port ? ':' . $port : '' ) . ( $_SERVER['REQUEST_URI'] ?? '/' );
}

/**
 * Requires a logged-in user. Enforces forced password change.
 *
 * @return array User.
 */
function require_login() {
	send_no_cache_headers();

	$user = current_user();

	if ( ! $user ) {
		$_SESSION['login_redirect'] = 'GET' === request_method() ? current_request_url() : '';
		redirect( app_url( 'auth/login.php' ) );
	}

	$script = basename( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ) );

	if ( 1 === (int) $user['must_change_password'] && ! in_array( $script, [ 'profile.php', 'logout.php', 'stop-impersonation.php' ], true ) ) {
		flash( 'warning', 'Din motive de securitate trebuie să îți schimbi parola înainte de a continua.' );
		redirect( app_url( 'dashboard/profile.php' ) );
	}

	return $user;
}

/**
 * Requires a business owner (or a super admin impersonating one). Operators get 403.
 *
 * @return array User.
 */
function require_owner() {
	$user = require_login();

	if ( 'super_admin' === $user['role'] ) {
		redirect( app_url( 'admin/index.php' ) );
	}

	if ( 'user' !== $user['role'] ) {
		render_error_page( 403, 'Acces interzis', 'Contul de operator are acces doar la Panou și la Feedback & Recenzii.' );
	}

	return $user;
}

/**
 * Requires a business owner or an operator (dashboard metrics and the feedback CRM).
 *
 * @return array User.
 */
function require_panel_access() {
	$user = require_login();

	if ( 'super_admin' === $user['role'] ) {
		redirect( app_url( 'admin/index.php' ) );
	}

	if ( ! in_array( $user['role'], [ 'user', 'operator' ], true ) ) {
		render_error_page( 403, 'Acces interzis', 'Nu ai permisiunea de a accesa această pagină.' );
	}

	return $user;
}

/**
 * Requires a (non-impersonating) super admin.
 *
 * @return array User.
 */
function require_super_admin() {
	$user = require_login();

	if ( 'super_admin' !== $user['role'] ) {
		render_error_page( 403, 'Acces interzis', 'Nu ai permisiunea de a accesa această pagină.' );
	}

	return $user;
}

/**
 * Whether a super admin is impersonating a user.
 *
 * @return bool
 */
function is_impersonating() {
	return ! empty( $_SESSION['impersonator_id'] );
}

/**
 * Starts impersonating a business owner.
 *
 * @param array $admin  Super admin row.
 * @param array $target Target user row.
 * @return bool
 */
function start_impersonation( array $admin, array $target ) {
	if ( 'super_admin' !== $admin['role'] || 'user' !== $target['role'] || is_impersonating() ) {
		return false;
	}

	regenerate_session();

	$_SESSION['impersonator_id']      = (int) $admin['id'];
	$_SESSION['impersonator_version'] = (int) $admin['session_version'];
	$_SESSION['user_id']              = (int) $target['id'];
	$_SESSION['role']                 = $target['role'];
	$_SESSION['session_version']      = (int) $target['session_version'];
	$_SESSION['current_company_id']   = 0;

	audit_log( 'impersonation_start', 'admin=' . (int) $admin['id'], (int) $target['id'], (int) $admin['id'] );
	current_user( true );

	return true;
}

/**
 * Ends impersonation and restores the super admin session.
 *
 * @return bool
 */
function stop_impersonation() {
	if ( ! is_impersonating() ) {
		return false;
	}

	$admin_id  = (int) $_SESSION['impersonator_id'];
	$target_id = (int) ( $_SESSION['user_id'] ?? 0 );
	$admin     = get_user_by_id( $admin_id );

	if ( ! $admin || 'super_admin' !== $admin['role'] || 'active' !== $admin['status'] || (int) $admin['session_version'] !== (int) ( $_SESSION['impersonator_version'] ?? -1 ) ) {
		logout_user();

		return false;
	}

	regenerate_session();

	$_SESSION['user_id']            = $admin_id;
	$_SESSION['role']               = $admin['role'];
	$_SESSION['session_version']    = (int) $admin['session_version'];
	$_SESSION['current_company_id'] = 0;
	unset( $_SESSION['impersonator_id'], $_SESSION['impersonator_version'] );

	audit_log( 'impersonation_stop', '', $target_id, $admin_id );
	current_user( true );

	return true;
}

/**
 * Invalidates all sessions of a user (password change, suspension).
 *
 * @param int $user_id User ID.
 * @return void
 */
function bump_session_version( $user_id ) {
	db_query( 'UPDATE users SET session_version = session_version + 1 WHERE id = ?', [ (int) $user_id ] );
}
