<?php
/**
 * Google OAuth 2.0 consent flow for the Google Drive Backup Manager (scope drive.file).
 *
 * POST action=connect     -> redirects to Google with a single-use `state` token.
 * GET  ?code=&state=      -> callback: validates state, exchanges the code, stores the encrypted
 *                            tokens and the account email, finds or creates the backup folder.
 * POST action=disconnect  -> revokes the token at Google and removes it locally.
 *
 * Authorized redirect URI to register in Google Cloud Console: gdrive_redirect_uri().
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

require_super_admin();

$backups_url = app_url( 'admin/backups.php' );

if ( is_post() ) {
	$action = input_post( 'action' );

	if ( 'disconnect' === $action ) {
		gdrive_disconnect();
		audit_log( 'admin_gdrive_disconnected' );
		flash( 'success', 'Contul Google Drive a fost deconectat. Backup-urile deja salvate rămân în Google Drive.' );
		redirect( $backups_url );
	}

	if ( 'connect' === $action ) {
		$credentials = gdrive_credentials();

		if ( '' === $credentials['client_id'] || '' === $credentials['client_secret'] ) {
			flash( 'danger', 'Salvează mai întâi Client ID și Client Secret (secțiunea „Credențiale OAuth”).' );
			redirect( $backups_url );
		}

		redirect( gdrive_authorize_url( create_onetime_token( 'gdrive_oauth' ) ) );
	}

	redirect( $backups_url );
}

if ( '' !== input_get( 'error' ) ) {
	flash( 'danger', 'Google a refuzat autorizarea: ' . sanitize_text( input_get( 'error' ), 100 ) );
	redirect( $backups_url );
}

$code  = input_get( 'code' );
$state = input_get( 'state' );

if ( '' === $code || ! consume_onetime_token( 'gdrive_oauth', $state ) ) {
	flash( 'danger', 'Cerere OAuth invalidă sau expirată. Încearcă din nou.' );
	redirect( $backups_url );
}

$error = gdrive_exchange_code( mb_substr( $code, 0, 1000 ) );

if ( '' !== $error && ! gdrive_is_connected() ) {
	flash( 'danger', 'Conectarea a eșuat: ' . $error );
	redirect( $backups_url );
}

if ( '' === get_backup_setting( 'gdrive_next_backup_at' ) ) {
	update_setting( 'gdrive_next_backup_at', compute_next_backup_at() );
}

audit_log( 'admin_gdrive_connected', get_backup_setting( 'gdrive_account_email' ) );
flash( '' === $error ? 'success' : 'warning', '' === $error ? 'Google Drive este conectat (' . get_backup_setting( 'gdrive_account_email' ) . '). Folderul „' . get_backup_setting( 'gdrive_folder_name' ) . '” este pregătit pentru backup-uri.' : $error );
redirect( $backups_url );
