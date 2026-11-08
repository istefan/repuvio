<?php
/**
 * Starts impersonating a business owner (Super Admin only, POST + CSRF, audited).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$admin = require_super_admin();

if ( ! is_post() ) {
	redirect( app_url( 'admin/users.php' ) );
}

$target = get_user_by_id( (int) input_post( 'user_id' ) );

if ( ! $target || 'user' !== $target['role'] ) {
	flash( 'danger', 'Doar conturile Business Owner pot fi impersonate.' );
	redirect( app_url( 'admin/users.php' ) );
}

if ( start_impersonation( $admin, $target ) ) {
	flash( 'info', 'Acum vezi aplicația ca ' . $target['name'] . '. Folosește bara de sus pentru a reveni.' );
	redirect( app_url( 'dashboard/index.php' ) );
}

flash( 'danger', 'Impersonarea nu a putut fi pornită.' );
redirect( app_url( 'admin/users.php' ) );
