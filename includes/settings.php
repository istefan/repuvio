<?php
/**
 * Global settings stored in the `settings` table (secrets encrypted at rest).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

/**
 * Setting keys that are always encrypted at rest.
 *
 * @return array
 */
function get_secret_setting_keys() {
	return [
		'smtp_password',
		'google_client_secret',
		'google_refresh_token',
		'google_access_token',
		'google_app_password',
		'gdrive_client_secret',
		'gdrive_refresh_token',
		'gdrive_access_token',
	];
}

/**
 * Loads all settings once per request.
 *
 * @param bool $refresh Force reload.
 * @return array key => decrypted value
 */
function load_settings( $refresh = false ) {
	static $cache = null;

	if ( null !== $cache && ! $refresh ) {
		return $cache;
	}

	$cache = [];

	try {
		$rows = db_all( 'SELECT setting_key, setting_value, is_encrypted FROM settings' );
	} catch ( Throwable $e ) {
		return $cache;
	}

	foreach ( $rows as $row ) {
		$value = (string) $row['setting_value'];

		if ( 1 === (int) $row['is_encrypted'] && '' !== $value ) {
			$value = decrypt_secret( $value );
		}

		$cache[ $row['setting_key'] ] = $value;
	}

	return $cache;
}

/**
 * Reads a setting.
 *
 * @param string $key     Key.
 * @param string $default Default.
 * @return string
 */
function get_setting( $key, $default = '' ) {
	$settings = load_settings();

	return array_key_exists( $key, $settings ) ? $settings[ $key ] : (string) $default;
}

/**
 * Reads an integer setting.
 *
 * @param string $key     Key.
 * @param int    $default Default.
 * @return int
 */
function get_setting_int( $key, $default = 0 ) {
	$value = get_setting( $key, (string) $default );

	return is_numeric( $value ) ? (int) $value : (int) $default;
}

/**
 * Creates or updates a setting. Secret keys are encrypted automatically.
 *
 * @param string $key   Key.
 * @param string $value Plain value.
 * @return void
 */
function update_setting( $key, $value ) {
	$is_secret = in_array( $key, get_secret_setting_keys(), true );
	$stored    = $is_secret ? encrypt_secret( (string) $value ) : (string) $value;

	db_query(
		'INSERT INTO settings (setting_key, setting_value, is_encrypted) VALUES (?, ?, ?)
		ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = VALUES(is_encrypted)',
		[ $key, $stored, $is_secret ? 1 : 0 ]
	);

	load_settings( true );
}

/**
 * Application display name.
 *
 * @return string
 */
function app_name() {
	return get_setting( 'app_name', 'RepuVio' );
}
