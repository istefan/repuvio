<?php
/**
 * Logout (POST + CSRF only).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

if ( ! is_post() ) {
	redirect( app_url() );
}

$user = current_user();

if ( $user ) {
	audit_log( 'logout', '', (int) $user['id'] );
}

logout_user();
flash( 'success', 'Te-ai deconectat cu succes.' );
redirect( app_url( 'auth/login.php' ) );
