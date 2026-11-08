<?php
/**
 * Guest layout footer.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

clear_old_input();
?>
	</div>
	<p class="text-center small text-muted mt-4 mb-1">
		&copy; <?php echo esc_html( date( 'Y' ) ); ?> <?php echo esc_html( app_name() ); ?>
	</p>
	<p class="text-center small mb-0 rm-auth-links">
		<a href="<?php echo esc_url( legal_url( 'terms' ) ); ?>">Termeni</a> ·
		<a href="<?php echo esc_url( legal_url( 'privacy' ) ); ?>">Confidențialitate</a> ·
		<a href="<?php echo esc_url( legal_url( 'cookies' ) ); ?>">Cookies</a> ·
		<a href="<?php echo esc_url( legal_url( 'contact' ) ); ?>">Contact</a> ·
		<a href="https://anpc.ro/" target="_blank" rel="noopener">ANPC</a>
	</p>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo esc_url( asset_url( 'js/app.js' ) ); ?>"></script>
</body>
</html>
