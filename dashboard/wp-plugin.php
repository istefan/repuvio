<?php
/**
 * Downloads the "RepuVio Trust Badge" WordPress plugin, pre-configured for one of the owner's
 * companies (review URL with ?src=widget and company name). Business owners only.
 *
 * Query: company_id.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$user    = require_owner();
$company = get_owned_company( sanitize_int_range( input_get( 'company_id', '0' ), 0, PHP_INT_MAX, 0 ) );

if ( ! $company ) {
	render_error_page( 404, 'Companie inexistentă', 'Compania nu există sau nu îți aparține.' );
}

$zip = build_wordpress_plugin_zip( $company );

audit_log( 'wp_plugin_downloaded', 'company=' . (int) $company['id'], (int) $user['id'] );

header( 'Content-Type: application/zip' );
header( 'Content-Disposition: attachment; filename="repuvio-trust-badge-' . $company['slug'] . '.zip"' );
header( 'Content-Length: ' . strlen( $zip ) );
header( 'X-Content-Type-Options: nosniff' );

echo $zip; // phpcs:ignore WordPress.Security.EscapeOutput -- binary ZIP download.
exit;
