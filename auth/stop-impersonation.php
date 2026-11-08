<?php
/**
 * Ends Super Admin impersonation (POST + CSRF only).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

if ( ! is_post() ) {
	redirect( app_url() );
}

$target_id = (int) ( $_SESSION['user_id'] ?? 0 );

if ( stop_impersonation() ) {
	flash( 'success', 'Te-ai întors în contul de Super Admin.' );
	redirect( app_url( 'admin/user-edit.php', [ 'id' => $target_id ] ) );
}

redirect( app_url( 'auth/login.php' ) );
