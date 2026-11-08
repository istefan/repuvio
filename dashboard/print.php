<?php
/**
 * Printable template renderer (exact physical sizes, vector QR, print / Save as PDF).
 *
 * Query: company_id, template (a4|a5|card|sticker), cta (optional override), ref (employee / department tag,
 * Pro / Enterprise / trial), preview (1 = scaled preview), print (1 = auto print).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$user = require_owner();

$templates = get_printable_templates();
$company   = get_owned_company( (int) input_get( 'company_id', '0' ) );
$template  = array_key_exists( input_get( 'template' ), $templates ) ? input_get( 'template' ) : 'a4';

if ( ! $company ) {
	render_error_page( 404, 'Companie inexistentă', 'Compania nu există sau nu îți aparține.' );
}

$cta        = sanitize_text( input_get( 'cta' ), 120 );
$cta        = '' !== $cta ? $cta : $company['cta_text'];
$is_preview = '1' === input_get( 'preview' );
$auto_print = '1' === input_get( 'print' );
$spec       = $templates[ $template ];
$ref_tag    = user_can_use_ref_tags( $user ) ? get_company_ref_tag( (int) $company['id'], input_get( 'ref' ) ) : null;
$qr_url     = get_company_public_url( $company, $spec['source'], $ref_tag ? $ref_tag['slug'] : '' );
$logo_url   = upload_url( $company['logo_path'] );
$cover_url  = upload_url( $company['cover_path'] );
$short_url  = preg_replace( '#^https?://#', '', get_company_public_url( $company ) );

header( 'X-Robots-Tag: noindex' );
?>
<!doctype html>
<html lang="ro">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( $spec['label'] . ' — ' . $company['name'] ); ?></title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
	<link href="<?php echo esc_url( asset_url( 'css/print.css' ) ); ?>" rel="stylesheet">
	<style>
		@page { size: <?php echo esc_html( $spec['size'] ); ?>; margin: 0; }
		.rm-sheet { width: <?php echo esc_html( $spec['width'] ); ?>mm; height: <?php echo esc_html( $spec['height'] ); ?>mm; }
	</style>
</head>
<body class="rm-print rm-tpl-<?php echo esc_attr( $template ); ?><?php echo $is_preview ? ' is-preview' : ''; ?>">

<?php if ( ! $is_preview ) : ?>
	<div class="rm-print-toolbar">
		<span><strong><?php echo esc_html( $spec['label'] ); ?></strong> · <?php echo esc_html( $company['name'] ); ?></span>
		<span class="rm-print-hint">Setează „Margini: Niciuna” și „Grafică de fundal: Activat” în fereastra de printare. Pentru PDF alege „Salvează ca PDF”.</span>
		<button type="button" id="rmPrintBtn"><i class="bi bi-printer"></i> Printează / PDF</button>
	</div>
<?php endif; ?>

<div class="rm-sheets" id="rmSheets">
	<?php if ( 'card' === $template ) : ?>
		<section class="rm-sheet rm-card-front">
			<div class="rm-card-front-inner">
				<?php if ( '' !== $logo_url ) : ?>
					<img class="rm-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="">
				<?php endif; ?>
				<div class="rm-name"><?php echo esc_html( $company['name'] ); ?></div>
				<div class="rm-stars">★★★★★</div>
				<div class="rm-cta"><?php echo esc_html( $cta ); ?></div>
			</div>
		</section>
		<section class="rm-sheet rm-card-back">
			<div class="rm-qr" data-qr="<?php echo esc_attr( $qr_url ); ?>"></div>
			<div class="rm-card-back-text">
				<div class="rm-scan">Scanează &amp; evaluează-ne</div>
				<?php if ( $ref_tag ) : ?>
					<div class="rm-ref-label"><?php echo esc_html( $ref_tag['label'] ); ?></div>
				<?php endif; ?>
				<div class="rm-url"><?php echo esc_html( $short_url ); ?></div>
			</div>
		</section>
	<?php else : ?>
		<section class="rm-sheet">
			<?php if ( 'sticker' !== $template ) : ?>
				<div class="rm-cover">
					<?php if ( '' !== $cover_url ) : ?>
						<img src="<?php echo esc_url( $cover_url ); ?>" alt="">
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<div class="rm-body">
				<?php if ( '' !== $logo_url ) : ?>
					<img class="rm-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="">
				<?php endif; ?>
				<div class="rm-name"><?php echo esc_html( $company['name'] ); ?></div>
				<div class="rm-stars">★★★★★</div>
				<div class="rm-cta"><?php echo esc_html( $cta ); ?></div>
				<div class="rm-qr" data-qr="<?php echo esc_attr( $qr_url ); ?>"></div>
				<div class="rm-scan"><i class="bi bi-phone"></i> Deschide camera telefonului și scanează codul</div>
				<?php if ( $ref_tag ) : ?>
					<div class="rm-ref-label"><?php echo esc_html( $ref_tag['label'] ); ?></div>
				<?php endif; ?>
				<?php if ( 'sticker' !== $template ) : ?>
					<div class="rm-url"><?php echo esc_html( $short_url ); ?></div>
				<?php endif; ?>
			</div>
		</section>
	<?php endif; ?>
</div>

<script nonce="<?php echo esc_attr( csp_nonce() ); ?>">window.RM_PRINT = <?php echo safe_json( [ 'autoPrint' => $auto_print, 'preview' => $is_preview ] ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON hex-escaped. ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
<script src="<?php echo esc_url( asset_url( 'js/printables.js' ) ); ?>"></script>
</body>
</html>
