<?php
/**
 * Public marketing / legal pages: document head (opens <body class="lp">).
 *
 * Expects: $public_title (string), $public_description (string). Optional: $public_robots (string),
 * $public_og (bool, Open Graph tags).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

$public_robots = $public_robots ?? 'index, follow';
$public_og     = $public_og ?? false;
?>
<!doctype html>
<html lang="ro">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( $public_title ); ?></title>
	<meta name="description" content="<?php echo esc_attr( $public_description ); ?>">
	<meta name="robots" content="<?php echo esc_attr( $public_robots ); ?>">
	<meta name="author" content="Stefan Iftimie — Ahoi Digital SRL">
	<?php if ( $public_og ) : ?>
		<meta property="og:title" content="<?php echo esc_attr( $public_title ); ?>">
		<meta property="og:description" content="<?php echo esc_attr( $public_description ); ?>">
		<meta property="og:type" content="website">
		<meta property="og:url" content="<?php echo esc_url( app_url() ); ?>">
	<?php endif; ?>
	<meta name="theme-color" content="#0F172A">
	<link rel="icon" href="<?php echo esc_url( asset_url( 'img/favicon.svg' ) ); ?>" type="image/svg+xml">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
	<link href="<?php echo esc_url( asset_url( 'css/landing.css' ) ); ?>" rel="stylesheet">
</head>
<body class="lp">
