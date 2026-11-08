<?php
/**
 * Public marketing / legal pages: sticky navbar. On the landing page the section links are in-page
 * anchors; on the legal pages they point back to the landing page sections.
 *
 * Optional: $is_landing (bool).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

$nav_base  = ! empty( $is_landing ) ? '' : app_url();
$nav_trial = max( 1, get_setting_int( 'trial_days', 30 ) );
$nav_links = [
	'cum-functioneaza' => 'Cum funcționează',
	'avantaje'         => 'Avantaje',
	'functionalitati'  => 'Funcționalități',
	'preturi'          => 'Prețuri',
	'intrebari'        => 'Întrebări frecvente',
];
?>
<header class="lp-nav" id="lpNav">
	<div class="lp-container lp-nav-inner">
		<a class="lp-brand" href="<?php echo esc_url( app_url() ); ?>" aria-label="<?php echo esc_attr( app_name() ); ?>">
			<span class="lp-brand-mark"><i class="bi bi-star-fill"></i></span>
			<span><?php echo esc_html( app_name() ); ?></span>
		</a>
		<button class="lp-nav-toggle" type="button" aria-controls="lpMenu" aria-expanded="false" aria-label="Deschide meniul" data-lp-toggle><i class="bi bi-list"></i></button>
		<nav class="lp-menu" id="lpMenu" aria-label="Navigare">
			<?php foreach ( $nav_links as $anchor => $label ) : ?>
				<a href="<?php echo esc_attr( $nav_base . '#' . $anchor ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
			<span class="lp-menu-actions">
				<a class="lp-link-strong" href="<?php echo esc_url( app_url( 'auth/login.php' ) ); ?>">Autentificare</a>
				<a class="lp-btn lp-btn-dark" href="<?php echo esc_url( app_url( 'auth/register.php' ) ); ?>">Încearcă Gratuit <?php echo esc_html( $nav_trial ); ?> Zile</a>
			</span>
		</nav>
	</div>
</header>
