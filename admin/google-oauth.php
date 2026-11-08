<?php
/**
 * Google OAuth2 consent flow for Gmail SMTP (XOAUTH2).
 *
 * POST action=connect     -> redirects to Google with a single-use `state` token.
 * GET  ?code=&state=      -> callback: validates state, exchanges the code, stores the refresh token (encrypted).
 * POST action=disconnect  -> removes stored tokens.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

require_super_admin();

$settings_url = app_url( 'admin/settings.php', [ 'tab' => 'email' ] );

if ( is_post() ) {
	$action = input_post( 'action' );

	if ( 'disconnect' === $action ) {
		google_oauth_disconnect();
		audit_log( 'admin_google_disconnected' );
		flash( 'success', 'Contul Google a fost deconectat.' );
		redirect( $settings_url );
	}

	if ( 'connect' === $action ) {
		if ( '' === get_setting( 'google_client_id' ) || '' === get_setting( 'google_client_secret' ) ) {
			flash( 'danger', 'Salvează mai întâi Client ID și Client Secret.' );
			redirect( $settings_url );
		}

		redirect( google_oauth_authorize_url( create_onetime_token( 'google_oauth' ) ) );
	}

	redirect( $settings_url );
}

if ( '' !== input_get( 'error' ) ) {
	flash( 'danger', 'Google a refuzat autorizarea: ' . sanitize_text( input_get( 'error' ), 100 ) );
	redirect( $settings_url );
}

$code  = input_get( 'code' );
$state = input_get( 'state' );

if ( '' === $code || ! consume_onetime_token( 'google_oauth', $state ) ) {
	flash( 'danger', 'Cerere OAuth invalidă sau expirată. Încearcă din nou.' );
	redirect( $settings_url );
}

$error = google_oauth_exchange_code( mb_substr( $code, 0, 1000 ) );

if ( '' !== $error ) {
	flash( 'danger', 'Conectarea a eșuat: ' . $error );
	redirect( $settings_url );
}

update_setting( 'mail_driver', 'google_oauth' );
audit_log( 'admin_google_connected' );
flash( 'success', 'Contul Google a fost conectat. Driverul de email a fost setat pe Google OAuth2 — trimite un email de test.' );
redirect( $settings_url );
