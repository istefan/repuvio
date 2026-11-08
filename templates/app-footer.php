<?php
/**
 * Authenticated layout footer (copyright + version, no links) and scripts.
 *
 * Optional: $page_scripts (array of script URLs), $page_inline_data (array exposed as window.RM_DATA).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

$page_scripts     = $page_scripts ?? [];
$page_inline_data = $page_inline_data ?? null;

clear_old_input();
?>
		</main>

		<footer class="rm-footer">
			<span>&copy; <?php echo esc_html( date( 'Y' ) ); ?> <?php echo esc_html( app_name() ); ?> · v<?php echo esc_html( REPUVIO_VERSION ); ?></span>
		</footer>
	</div>
</div>

<?php if ( null !== $page_inline_data ) : ?>
	<script nonce="<?php echo esc_attr( csp_nonce() ); ?>">window.RM_DATA = <?php echo safe_json( $page_inline_data ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON hex-escaped. ?>;</script>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php foreach ( $page_scripts as $script_url ) : ?>
	<script src="<?php echo esc_url( $script_url ); ?>"></script>
<?php endforeach; ?>
<script src="<?php echo esc_url( asset_url( 'js/app.js' ) ); ?>"></script>
</body>
</html>
