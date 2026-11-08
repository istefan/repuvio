<?php
/**
 * RepuVio configuration template.
 *
 * Copy this file to config/config.php and fill in real values.
 * config/config.php holds secrets and must never be committed or web-accessible.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

// Full public URL of the application, without trailing slash.
define( 'APP_URL', 'https://repuvio.ro' );

// Show PHP errors in the browser. MUST be false in production.
define( 'APP_DEBUG', false );

// Application timezone.
define( 'APP_TIMEZONE', 'Europe/Bucharest' );

// 32 random bytes, base64 encoded: php -r "echo base64_encode(random_bytes(32));"
// Used to encrypt secrets at rest (AES-256-CBC + HMAC). Do not change after secrets were stored.
define( 'APP_KEY', 'REPLACE_WITH_BASE64_32_BYTES' );

// Token required to run cron.php over HTTP (?token=...).
define( 'CRON_TOKEN', 'REPLACE_WITH_LONG_RANDOM_STRING' );

// Use pretty public links (/r/{slug}). Set to false if mod_rewrite is unavailable (review.php?b={slug}).
define( 'PRETTY_URLS', true );

// Session idle timeout in seconds.
define( 'SESSION_IDLE_TIMEOUT', 1800 );

// Database (MySQL / MariaDB, InnoDB, utf8mb4).
define( 'DB_HOST', 'localhost' );
define( 'DB_PORT', 3306 );
define( 'DB_NAME', 'repuvio' );
define( 'DB_USER', 'repuvio_user' );
define( 'DB_PASS', 'change-me' );
