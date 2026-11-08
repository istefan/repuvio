<?php
/**
 * Changes the selected company for the dashboard (POST + CSRF, ownership / operator assignment checked).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

require_panel_access();

if ( ! is_post() ) {
	redirect( app_url( 'dashboard/index.php' ) );
}

$company_id = sanitize_int_range( input_post( 'company_id', '0' ), 0, PHP_INT_MAX, 0 );

if ( 0 === $company_id || get_owned_company( $company_id ) ) {
	$_SESSION['current_company_id'] = $company_id;
}

safe_redirect( input_post( 'return' ), 'dashboard/index.php' );
