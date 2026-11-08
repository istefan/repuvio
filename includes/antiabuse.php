<?php
/**
 * DEV vs. PROD environment switch and the public funnel anti-abuse engine.
 *
 * PROD (default): per-IP rate limits, view/redirect de-duplication per session and a review
 * cooldown per company: one counted review per device cookie, or per IP + browser
 * (User-Agent) pair, inside `review_cooldown_hours` (default 24). IP alone is not enough,
 * so customers sharing a venue Wi-Fi are not blocked. Honeypot, signed form timer and CSRF
 * are always on.
 *
 * DEV: rate limits, session de-duplication and the review cooldown are disabled so the
 * Super Admin can simulate unlimited reviews from one browser. Honeypot, timer and CSRF stay on.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

const FUNNEL_DEVICE_COOKIE = 'rm_device';

/**
 * Current environment mode: 'dev' or 'prod'.
 *
 * @return string
 */
function get_app_environment() {
	return 'dev' === get_setting( 'app_environment', 'prod' ) ? 'dev' : 'prod';
}

/**
 * Whether the platform runs in DEV (testing) mode.
 *
 * @return bool
 */
function is_dev_environment() {
	return 'dev' === get_app_environment();
}

/**
 * Review cooldown window in hours (PROD mode).
 *
 * @return int
 */
function get_review_cooldown_hours() {
	return max( 1, min( 720, get_setting_int( 'review_cooldown_hours', 24 ) ) );
}

/**
 * Anonymous funnel device ID stored in a first-party cookie (random, no personal data).
 *
 * @return string 32 hex characters.
 */
function funnel_device_id() {
	static $device_id = null;

	if ( null !== $device_id ) {
		return $device_id;
	}

	$cookie = (string) ( $_COOKIE[ FUNNEL_DEVICE_COOKIE ] ?? '' );

	if ( 1 === preg_match( '/^[a-f0-9]{32}$/', $cookie ) ) {
		$device_id = $cookie;

		return $device_id;
	}

	$device_id = bin2hex( random_bytes( 16 ) );

	if ( 'cli' !== PHP_SAPI && ! headers_sent() ) {
		setcookie(
			FUNNEL_DEVICE_COOKIE,
			$device_id,
			[
				'expires'  => time() + 365 * 86400,
				'path'     => '/',
				'secure'   => is_https(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
	}

	return $device_id;
}

/**
 * Keyed hash of the funnel device ID (stored on reviews).
 *
 * @return string
 */
function funnel_device_hash() {
	return hash_hmac( 'sha256', 'device|' . funnel_device_id(), APP_KEY );
}

/**
 * Whether this device / IP+browser already left a counted review for the company inside the cooldown.
 * Always false in DEV mode.
 *
 * @param int $company_id Company ID.
 * @return bool
 */
function is_duplicate_review( $company_id ) {
	if ( is_dev_environment() ) {
		return false;
	}

	return 0 < (int) db_value(
		'SELECT COUNT(*) FROM reviews
		WHERE company_id = :company_id
			AND created_at >= NOW() - INTERVAL :hours HOUR
			AND (device_hash = :device_hash OR (ip_hash = :ip_hash AND user_agent = :user_agent))',
		[
			'company_id'  => (int) $company_id,
			'hours'       => get_review_cooldown_hours(),
			'device_hash' => funnel_device_hash(),
			'ip_hash'     => client_ip_hash(),
			'user_agent'  => client_user_agent(),
		]
	);
}

/**
 * Funnel rate limit check (never locked in DEV mode).
 *
 * @param string $action Action key.
 * @return bool
 */
function funnel_rate_limit_is_locked( $action ) {
	return is_dev_environment() ? false : rate_limit_is_locked( $action );
}

/**
 * Funnel rate limit hit (no-op in DEV mode).
 *
 * @param string $action          Action key.
 * @param int    $max             Max attempts.
 * @param int    $window_seconds  Window.
 * @param int    $lockout_seconds Lockout.
 * @return bool
 */
function funnel_rate_limit_hit( $action, $max, $window_seconds, $lockout_seconds ) {
	return is_dev_environment() ? false : rate_limit_hit( $action, $max, $window_seconds, $lockout_seconds );
}

/**
 * Session de-duplication window in seconds (0 in DEV mode).
 *
 * @param int $seconds PROD window.
 * @return int
 */
function funnel_dedupe_window( $seconds ) {
	return is_dev_environment() ? 0 : (int) $seconds;
}
