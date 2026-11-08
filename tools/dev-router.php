<?php
/**
 * Development router for PHP's built-in server. Emulates the .htaccess rules
 * (blocked folders/files, /r/{slug} rewrite, script-free uploads).
 *
 * Usage: php -S 127.0.0.1:8791 -t . tools/dev-router.php
 * Set APP_URL in config/config.php to http://127.0.0.1:8791. Never used in production.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

if ( 'cli-server' !== PHP_SAPI ) {
	http_response_code( 404 );
	exit;
}

$root = dirname( __DIR__ );
$path = rawurldecode( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );

$is_blocked = preg_match( '#^/(config|includes|storage|templates|tools)(/|$)#', $path )
	|| preg_match( '#/\.#', $path )
	|| preg_match( '/\.(md|sql|log|ini|bak|sh|dist|lock|json|yml|yaml|env)$/i', $path )
	|| preg_match( '#^/uploads/.*\.(php|phtml|phar|html|htm|js|svg)$#i', $path );

if ( $is_blocked ) {
	http_response_code( 403 );
	echo 'Forbidden';
	return true;
}

if ( preg_match( '#^/r/([a-z0-9-]{1,100})/?$#', $path, $matches ) ) {
	$_GET['b']              = $matches[1];
	$_SERVER['SCRIPT_NAME'] = '/review.php';
	chdir( $root );
	require $root . '/review.php';
	return true;
}

if ( preg_match( '#^/help/?$#', $path ) ) {
	$path = '/dashboard/help.php';
}

$public_routes = [
	'termeni-si-conditii' => '/terms.php',
	'confidentialitate'   => '/privacy.php',
	'politica-cookies'    => '/cookies.php',
	'contact'             => '/contact.php',
];

if ( preg_match( '#^/([a-z-]+)/?$#', $path, $route ) && isset( $public_routes[ $route[1] ] ) ) {
	$path = $public_routes[ $route[1] ];
}

$file = $root . $path;

if ( is_dir( $file ) ) {
	$file = rtrim( $file, '/' ) . '/index.php';
}

if ( is_file( $file ) ) {
	if ( preg_match( '/\.php$/', $file ) ) {
		$_SERVER['SCRIPT_NAME'] = substr( $file, strlen( $root ) );
		chdir( dirname( $file ) );
		require $file;
		return true;
	}

	return false;
}

http_response_code( 404 );
echo 'Not found';
return true;
