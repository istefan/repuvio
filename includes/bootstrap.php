<?php
/**
 * Application bootstrap: config, error handling, libraries, automatic DB migrations,
 * security headers, hardened session and global CSRF verification for every POST.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

define( 'REPUVIO', true );
define( 'REPUVIO_ROOT', dirname( __DIR__ ) );
define( 'REPUVIO_VERSION', '2.0.0' );

if ( 80100 > PHP_VERSION_ID ) {
	http_response_code( 500 );
	exit( 'This application requires PHP 8.1 or newer.' );
}

if ( ! is_file( REPUVIO_ROOT . '/config/config.php' ) ) {
	http_response_code( 500 );
	exit( 'Configuration missing: copy config/config.sample.php to config/config.php.' );
}

require_once REPUVIO_ROOT . '/config/config.php';

error_reporting( E_ALL );
ini_set( 'display_errors', APP_DEBUG ? '1' : '0' );
ini_set( 'log_errors', '1' );
ini_set( 'error_log', REPUVIO_ROOT . '/storage/logs/php-error.log' );

date_default_timezone_set( APP_TIMEZONE );
mb_internal_encoding( 'UTF-8' );

require_once REPUVIO_ROOT . '/includes/db.php';
require_once REPUVIO_ROOT . '/includes/formatting.php';
require_once REPUVIO_ROOT . '/includes/helpers.php';
require_once REPUVIO_ROOT . '/includes/security.php';
require_once REPUVIO_ROOT . '/includes/session.php';
require_once REPUVIO_ROOT . '/includes/settings.php';
require_once REPUVIO_ROOT . '/includes/mailer.php';
require_once REPUVIO_ROOT . '/includes/email-templates.php';
require_once REPUVIO_ROOT . '/includes/emails.php';
require_once REPUVIO_ROOT . '/includes/subscription.php';
require_once REPUVIO_ROOT . '/includes/auth.php';
require_once REPUVIO_ROOT . '/includes/companies.php';
require_once REPUVIO_ROOT . '/includes/reviews.php';
require_once REPUVIO_ROOT . '/includes/uploads.php';
require_once REPUVIO_ROOT . '/includes/operators.php';
require_once REPUVIO_ROOT . '/includes/antiabuse.php';
require_once REPUVIO_ROOT . '/includes/reports.php';
require_once REPUVIO_ROOT . '/includes/print-shop.php';
require_once REPUVIO_ROOT . '/includes/promote.php';
require_once REPUVIO_ROOT . '/includes/onboarding.php';
require_once REPUVIO_ROOT . '/includes/legal.php';
require_once REPUVIO_ROOT . '/includes/showcase.php';
require_once REPUVIO_ROOT . '/includes/google-drive-backup.php';
require_once REPUVIO_ROOT . '/includes/migrations.php';

set_exception_handler(
	function ( $exception ) {
		error_log( 'Uncaught ' . get_class( $exception ) . ': ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine() );

		if ( 'cli' === PHP_SAPI ) {
			fwrite( STDERR, $exception->getMessage() . PHP_EOL );
			exit( 1 );
		}

		if ( APP_DEBUG ) {
			http_response_code( 500 );
			echo '<pre>' . esc_html( (string) $exception ) . '</pre>';
			exit;
		}

		render_error_page( 500, 'Eroare internă', 'A apărut o eroare neașteptată. Te rugăm să încerci din nou în câteva momente.' );
	}
);

// Upgrade older databases in place (no-op once the schema version is current).
maybe_run_migrations();

if ( 'cli' !== PHP_SAPI ) {
	send_security_headers();

	if ( ! defined( 'REPUVIO_NO_SESSION' ) ) {
		start_secure_session();
		csrf_verify_request();
	}
}
