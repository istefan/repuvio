<?php
/**
 * Public legal document layout: public head + navbar, title block with the last-updated date and a
 * print button, sticky table of contents (desktop), numbered sections, provider card, public footer.
 *
 * Expects: $doc_key (terms|privacy|cookies), $doc_title, $doc_intro (escaped HTML), $doc_sections
 * (array of [ id, title, html ] — html is authored in the page file with every dynamic value escaped),
 * $legal (get_legal_settings()).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

$public_title       = $doc_title . ' · ' . app_name();
$public_description = $doc_title . ' pentru platforma ' . app_name() . ', operată de ' . $legal['legal_company_name'] . '.';

require REPUVIO_ROOT . '/templates/public-head.php';
require REPUVIO_ROOT . '/templates/public-nav.php';
?>
<main class="lp-legal">
	<section class="lp-legal-hero">
		<div class="lp-container">
			<nav class="lp-legal-tabs" aria-label="Documente legale">
				<?php foreach ( get_public_pages() as $page_key => $page ) : ?>
					<a class="<?php echo $page_key === $doc_key ? 'is-active' : ''; ?>" href="<?php echo esc_url( legal_url( $page_key ) ); ?>"><?php echo esc_html( $page[0] ); ?></a>
				<?php endforeach; ?>
			</nav>
			<h1><?php echo esc_html( $doc_title ); ?></h1>
			<p class="lp-legal-meta">
				<span><i class="bi bi-calendar3"></i> Ultima actualizare: <?php echo esc_html( $legal['legal_last_updated'] ); ?></span>
				<button type="button" class="lp-btn lp-btn-light" data-lp-print><i class="bi bi-printer"></i> Printează / PDF</button>
			</p>
		</div>
	</section>

	<div class="lp-container lp-legal-grid">
		<aside class="lp-toc" aria-label="Cuprins">
			<div class="lp-toc-title">Cuprins</div>
			<ol>
				<?php foreach ( $doc_sections as $section ) : ?>
					<li><a href="#<?php echo esc_attr( $section[0] ); ?>" data-toc-link><?php echo esc_html( $section[1] ); ?></a></li>
				<?php endforeach; ?>
			</ol>
		</aside>

		<article class="lp-doc">
			<div class="lp-doc-intro"><?php echo $doc_intro; // phpcs:ignore WordPress.Security.EscapeOutput -- authored HTML, dynamic parts escaped in the page file. ?></div>

			<?php foreach ( $doc_sections as $index => $section ) : ?>
				<section id="<?php echo esc_attr( $section[0] ); ?>" class="lp-doc-section" data-toc-section>
					<h2><span><?php echo esc_html( $index + 1 ); ?>.</span> <?php echo esc_html( $section[1] ); ?></h2>
					<?php echo $section[2]; // phpcs:ignore WordPress.Security.EscapeOutput -- authored HTML, dynamic parts escaped in the page file. ?>
				</section>
			<?php endforeach; ?>

			<div class="lp-provider">
				<div class="lp-provider-title"><i class="bi bi-building"></i> Date de identificare furnizor</div>
				<dl>
					<dt>Denumire</dt><dd><?php echo esc_html( $legal['legal_company_name'] ); ?></dd>
					<dt>CUI</dt><dd><?php echo esc_html( $legal['legal_cui'] ); ?></dd>
					<dt>Nr. Reg. Com.</dt><dd><?php echo esc_html( $legal['legal_reg_com'] ); ?></dd>
					<dt>Cod CAEN</dt><dd><?php echo esc_html( $legal['legal_caen'] ); ?></dd>
					<dt>Sediu</dt><dd><?php echo esc_html( $legal['legal_address'] ); ?></dd>
					<dt>Email</dt><dd><a href="<?php echo esc_url( 'mailto:' . $legal['legal_email'] ); ?>"><?php echo esc_html( $legal['legal_email'] ); ?></a></dd>
					<dt>Telefon</dt><dd><?php echo esc_html( $legal['legal_phone'] ); ?></dd>
				</dl>
			</div>
		</article>
	</div>
</main>
<?php
require REPUVIO_ROOT . '/templates/public-footer.php';
