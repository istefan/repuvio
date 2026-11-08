<?php
/**
 * Guest layout header (login, register, password recovery).
 *
 * Expects: $page_title (string), optional $auth_subtitle (string).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

$page_title    = $page_title ?? app_name();
$auth_subtitle = $auth_subtitle ?? '';
?>
<!doctype html>
<html lang="ro">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="author" content="Stefan Iftimie — Ahoi Digital SRL">
	<title><?php echo esc_html( $page_title . ' · ' . app_name() ); ?></title>
	<link rel="icon" href="<?php echo esc_url( asset_url( 'img/favicon.svg' ) ); ?>" type="image/svg+xml">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
	<link href="<?php echo esc_url( asset_url( 'css/app.css' ) ); ?>" rel="stylesheet">
</head>
<body class="rm-auth">
<div class="rm-auth-wrap">
	<a class="rm-brand justify-content-center mb-4" href="<?php echo esc_url( app_url() ); ?>">
		<span class="rm-brand-mark"><i class="bi bi-star-fill"></i></span>
		<span><?php echo esc_html( app_name() ); ?></span>
	</a>
	<div class="rm-card rm-auth-card">
		<h1 class="h4 fw-bold mb-1"><?php echo esc_html( $page_title ); ?></h1>
		<?php if ( '' !== $auth_subtitle ) : ?>
			<p class="text-muted mb-4"><?php echo esc_html( $auth_subtitle ); ?></p>
		<?php endif; ?>

		<?php foreach ( get_flash_messages() as $flash_message ) : ?>
			<div class="alert alert-<?php echo esc_attr( $flash_message['type'] ); ?>" role="alert"><?php echo esc_html( $flash_message['message'] ); ?></div>
		<?php endforeach; ?>
