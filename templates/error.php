<?php
/**
 * Standalone error page (403 / 404 / 500 / 429).
 *
 * Expects: $error_status (int), $error_title (string), $error_message (string).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;
?>
<!doctype html>
<html lang="ro">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex">
	<title><?php echo esc_html( $error_title ); ?></title>
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="<?php echo esc_url( asset_url( 'css/app.css' ) ); ?>" rel="stylesheet">
</head>
<body class="rm-auth">
<div class="rm-auth-wrap text-center">
	<div class="rm-card rm-auth-card">
		<div class="display-5 fw-bold text-body-tertiary mb-2"><?php echo esc_html( $error_status ); ?></div>
		<h1 class="h4 fw-bold"><?php echo esc_html( $error_title ); ?></h1>
		<p class="text-muted"><?php echo esc_html( $error_message ); ?></p>
		<a class="btn btn-dark" href="<?php echo esc_url( app_url() ); ?>">Înapoi la pagina principală</a>
	</div>
</div>
</body>
</html>
