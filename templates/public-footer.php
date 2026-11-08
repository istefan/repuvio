<?php
/**
 * Public marketing / legal pages: footer (legal links, ANPC / SAL, provider attribution), the
 * minimalist cookie information banner (hidden once `repuvio_cookie_consent=1` is set), landing.js,
 * and the closing </body></html>. Never included on /r/{slug}.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

$footer_legal = get_legal_settings();
?>
<footer class="lp-footer">
	<div class="lp-container">
		<div class="lp-footer-grid">
			<div>
				<a class="lp-brand" href="<?php echo esc_url( app_url() ); ?>">
					<span class="lp-brand-mark"><i class="bi bi-star-fill"></i></span>
					<span><?php echo esc_html( app_name() ); ?></span>
				</a>
				<p class="lp-footer-about">Scutul inteligent de reputație pentru afacerile locale din România: mai multe recenzii de 5★ pe Google, nemulțumiri rezolvate în privat.</p>
				<?php if ( '' !== phone_tel_url( $footer_legal['legal_phone'] ) ) : ?>
					<a class="lp-footer-phone" href="<?php echo esc_url( phone_tel_url( $footer_legal['legal_phone'] ) ); ?>"><i class="bi bi-telephone"></i> <?php echo esc_html( $footer_legal['legal_phone'] ); ?></a>
				<?php endif; ?>
				<a class="lp-footer-phone" href="<?php echo esc_url( 'mailto:' . $footer_legal['legal_email'] ); ?>"><i class="bi bi-envelope"></i> <?php echo esc_html( $footer_legal['legal_email'] ); ?></a>
			</div>
			<div>
				<div class="lp-footer-title">Produs</div>
				<a href="<?php echo esc_url( app_url() . '#preturi' ); ?>">Prețuri</a>
				<a href="<?php echo esc_url( app_url( 'auth/register.php' ) ); ?>">Înregistrare</a>
				<a href="<?php echo esc_url( app_url( 'auth/login.php' ) ); ?>">Autentificare</a>
			</div>
			<div>
				<div class="lp-footer-title">Legal</div>
				<a href="<?php echo esc_url( legal_url( 'terms' ) ); ?>">Termeni și Condiții</a>
				<a href="<?php echo esc_url( legal_url( 'privacy' ) ); ?>">Confidențialitate (GDPR)</a>
				<a href="<?php echo esc_url( legal_url( 'cookies' ) ); ?>">Politica de Cookies</a>
				<a href="<?php echo esc_url( legal_url( 'contact' ) ); ?>">Contact</a>
			</div>
			<div>
				<div class="lp-footer-title">Protecția consumatorilor</div>
				<a class="lp-anpc" href="https://anpc.ro/ce-este-sal/" target="_blank" rel="noopener"><span>ANPC</span> Soluționarea alternativă a litigiilor (SAL)</a>
				<a class="lp-anpc" href="https://anpc.ro/" target="_blank" rel="noopener"><span>ANPC</span> Autoritatea Națională pentru Protecția Consumatorilor</a>
			</div>
		</div>
		<p class="lp-footer-copy">&copy; <?php echo esc_html( date( 'Y' ) . ' ' . app_name() ); ?> · Făurit cu <span class="lp-heart" aria-label="dragoste">♥</span> în România de <a href="<?php echo esc_url( $footer_legal['legal_company_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $footer_legal['legal_company_name'] ); ?></a> · CUI <?php echo esc_html( $footer_legal['legal_cui'] ); ?></p>
	</div>
</footer>

<?php if ( ! has_cookie_consent() ) : ?>
	<div class="lp-cookie" role="region" aria-label="Informare cookies" data-cookie-banner>
		<i class="bi bi-shield-check"></i>
		<p>Folosim doar cookie-uri strict necesare pentru securitate și funcționarea optimă a platformei. <a href="<?php echo esc_url( legal_url( 'cookies' ) ); ?>">Detalii în Politica de Cookies</a>.</p>
		<button type="button" class="lp-btn lp-btn-dark" data-cookie-accept data-cookie-name="<?php echo esc_attr( COOKIE_CONSENT_NAME ); ?>" data-cookie-secure="<?php echo esc_attr( is_https() ? '1' : '0' ); ?>">Am înțeles</button>
	</div>
<?php endif; ?>

<script src="<?php echo esc_url( asset_url( 'js/landing.js' ) ); ?>" defer></script>
</body>
</html>
