<?php
/**
 * "Widget & Semnătură Email": live configurator for the website trust badge (floating / inline,
 * light / dark) and three ready-to-copy HTML email signatures with step-by-step tutorials for
 * Outlook, Gmail, Apple Mail and Thunderbird. Business owners only, all plans.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$user       = require_owner();

mark_onboarding_materials( (int) $user['id'] );
$companies  = get_user_companies( (int) $user['id'] );
$company_id = sanitize_int_range( input_get( 'company_id', (string) get_current_company_id() ), 0, PHP_INT_MAX, 0 );
$company    = 0 < $company_id ? get_owned_company( $company_id ) : null;
$active_tab = 'signature' === input_get( 'tab' ) ? 'signature' : 'widget';

if ( ! $company && ! empty( $companies ) ) {
	$company = $companies[0];
}

$positions = get_widget_positions();
$themes    = get_widget_themes();
$variants  = get_signature_variants();
$tutorials = get_signature_tutorials();

$page_title       = 'Widget & Semnătură Email';
$active_nav       = 'promote';
$page_breadcrumbs = [ [ 'Acasă', app_url( 'dashboard/index.php' ) ], [ 'Widget & Semnătură Email', '' ] ];

if ( $company ) {
	$snippets = [];
	$inline   = [];

	foreach ( array_keys( $positions ) as $position ) {
		foreach ( array_keys( $themes ) as $theme ) {
			$snippets[ $position ][ $theme ] = build_widget_snippet( $company, $position, $theme );
		}
	}

	foreach ( array_keys( $themes ) as $theme ) {
		$inline[ $theme ] = build_inline_badge_html( $company, $theme );
	}

	$page_scripts     = [ asset_url( 'js/badge.js' ), asset_url( 'js/promote.js' ) ];
	$page_inline_data = [
		'snippets'  => $snippets,
		'inline'    => $inline,
		'widgetUrl' => get_company_public_url( $company, 'widget' ),
		'name'      => $company['name'],
	];
}

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<?php if ( ! $company ) : ?>
	<div class="rm-card">
		<div class="rm-empty">
			<i class="bi bi-window-stack"></i>
			<h2 class="h5 mt-3">Adaugă o companie pentru a genera widget-ul și semnăturile</h2>
			<a class="btn btn-dark mt-2" href="<?php echo esc_url( app_url( 'dashboard/company-edit.php' ) ); ?>"><i class="bi bi-plus-lg"></i> Adaugă companie</a>
		</div>
	</div>
<?php else : ?>
	<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
		<ul class="nav nav-pills rm-tabs gap-1" role="tablist">
			<li class="nav-item" role="presentation"><button class="nav-link<?php echo 'widget' === $active_tab ? ' active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#tab-widget" type="button" role="tab"><i class="bi bi-patch-check"></i> Insignă website</button></li>
			<li class="nav-item" role="presentation"><button class="nav-link<?php echo 'signature' === $active_tab ? ' active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#tab-signature" type="button" role="tab"><i class="bi bi-envelope-paper"></i> Semnătură email</button></li>
		</ul>
		<form method="get" action="<?php echo esc_url( app_url( 'dashboard/promote.php' ) ); ?>" class="d-flex gap-2 align-items-center">
			<label class="small text-muted text-nowrap" for="promote-company">Companie</label>
			<select class="form-select form-select-sm rm-company-switcher" id="promote-company" name="company_id" data-autosubmit>
				<?php foreach ( $companies as $item ) : ?>
					<option value="<?php echo esc_attr( $item['id'] ); ?>"<?php echo (int) $item['id'] === (int) $company['id'] ? ' selected' : ''; ?>><?php echo esc_html( $item['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</form>
	</div>

	<div class="tab-content">
		<div class="tab-pane fade<?php echo 'widget' === $active_tab ? ' show active' : ''; ?>" id="tab-widget" role="tabpanel">
			<div class="row g-3">
				<div class="col-xl-5">
					<div class="rm-card h-100">
						<div class="rm-card-header"><h2 class="rm-card-title">Configurator „Insignă de încredere”</h2></div>
						<div class="rm-card-body">
							<label class="form-label">Poziție</label>
							<div class="d-grid gap-2 mb-3">
								<?php foreach ( $positions as $key => $label ) : ?>
									<label class="rm-template-option">
										<input type="radio" class="visually-hidden" name="widget_position" value="<?php echo esc_attr( $key ); ?>"<?php echo 'bottom-right' === $key ? ' checked' : ''; ?> data-widget-position>
										<span class="rm-template-card d-flex align-items-center gap-2 py-2">
											<i class="bi <?php echo esc_attr( 'inline' === $key ? 'bi-layout-text-window' : ( 'bottom-left' === $key ? 'bi-box-arrow-in-down-left' : 'bi-box-arrow-in-down-right' ) ); ?>"></i>
											<span class="small fw-medium text-dark"><?php echo esc_html( $label ); ?></span>
										</span>
									</label>
								<?php endforeach; ?>
							</div>

							<label class="form-label">Temă</label>
							<div class="row g-2 mb-3">
								<?php foreach ( $themes as $key => $label ) : ?>
									<div class="col-6">
										<label class="rm-template-option d-block">
											<input type="radio" class="visually-hidden" name="widget_theme" value="<?php echo esc_attr( $key ); ?>"<?php echo 'light' === $key ? ' checked' : ''; ?> data-widget-theme>
											<span class="rm-template-card d-flex align-items-center gap-2 py-2">
												<span class="d-inline-block rounded-circle border" style="width:14px;height:14px;background:<?php echo esc_attr( 'dark' === $key ? '#0F172A' : '#FFFFFF' ); ?>;"></span>
												<span class="small fw-medium text-dark"><?php echo esc_html( $label ); ?></span>
											</span>
										</label>
									</div>
								<?php endforeach; ?>
							</div>

							<label class="form-label" for="rmWidgetCode">Cod de inserat pe site</label>
							<textarea class="form-control rm-copy-input mb-2" id="rmWidgetCode" rows="6" readonly><?php echo esc_textarea( build_widget_snippet( $company, 'bottom-right', 'light' ) ); ?></textarea>
							<div class="d-flex flex-wrap gap-2 align-items-center">
								<button type="button" class="btn btn-dark btn-sm" data-copy="#rmWidgetCode"><i class="bi bi-clipboard"></i> Copiază codul</button>
								<span class="small text-muted">Lipește-l înainte de <code>&lt;/body&gt;</code> (WordPress: „Custom HTML” / Elementor: widget HTML).</span>
							</div>
							<div class="small text-muted mt-3"><i class="bi bi-shield-check"></i> Fără cookie-uri și fără tracking. Linkul folosește <code>?src=widget</code>, deci vezi în CRM câte recenzii vin de pe site.</div>
						</div>
					</div>
				</div>
				<div class="col-xl-7">
					<div class="rm-card mb-3">
						<div class="rm-card-header"><h2 class="rm-card-title">Previzualizare live</h2><span class="small text-muted">Așa va apărea pe site-ul tău</span></div>
						<div class="rm-card-body">
							<div class="rm-browser-mock" id="rmWidgetPreview">
								<div class="rm-browser-mock-bar"><span></span><span></span><span></span></div>
								<div class="rm-browser-mock-body">
									<div class="rm-skeleton w-50"></div>
									<div class="rm-skeleton w-75"></div>
									<div class="rm-skeleton w-100"></div>
									<div class="rm-skeleton w-75"></div>
									<div id="rmInlineSlot" class="my-3"></div>
									<div class="rm-skeleton w-100"></div>
									<div class="rm-skeleton w-50"></div>
								</div>
							</div>
						</div>
					</div>

					<div class="rm-card rm-wp-card">
						<div class="rm-card-body">
							<div class="d-flex flex-wrap align-items-center gap-3 mb-3">
								<span class="rm-wp-icon"><i class="bi bi-wordpress"></i></span>
								<div class="flex-grow-1 min-w-0">
									<h2 class="rm-card-title mb-1">Plugin WordPress „<?php echo esc_html( app_name() ); ?> Trust Badge”</h2>
									<div class="small text-muted">Pre-configurat pentru <strong><?php echo esc_html( $company['name'] ); ?></strong>. Fără cod: alegi poziția, tema și paginile din panoul WordPress.</div>
								</div>
								<a class="btn btn-dark" href="<?php echo esc_url( app_url( 'dashboard/wp-plugin.php', [ 'company_id' => (int) $company['id'] ] ) ); ?>"><i class="bi bi-download"></i> Descarcă plugin (.zip)</a>
							</div>
							<div class="row g-3 small">
								<div class="col-md-6">
									<ol class="rm-steps mb-0">
										<li>WordPress → <strong>Module → Adaugă nou → Încarcă modul</strong>, alege fișierul ZIP și apasă <strong>Instalează acum</strong>.</li>
										<li>Apasă <strong>Activează</strong>.</li>
										<li>Deschide <strong>Setări → <?php echo esc_html( app_name() ); ?> Badge</strong>: linkul și numele firmei sunt deja completate.</li>
									</ol>
								</div>
								<div class="col-md-6">
									<div class="text-muted mb-1"><i class="bi bi-check2 text-success"></i> Plutitor dreapta-jos / stânga-jos sau doar în pagină</div>
									<div class="text-muted mb-1"><i class="bi bi-check2 text-success"></i> Shortcode <code>[repuvio_badge]</code> pentru blocuri, Elementor și widget-uri</div>
									<div class="text-muted mb-1"><i class="bi bi-check2 text-success"></i> Tot site-ul, doar prima pagină sau pagini alese, cu excluderi</div>
									<div class="text-muted"><i class="bi bi-check2 text-success"></i> Fără cookie-uri și fără scripturi externe</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="tab-pane fade<?php echo 'signature' === $active_tab ? ' show active' : ''; ?>" id="tab-signature" role="tabpanel">
			<div class="row g-3">
				<div class="col-xl-7">
					<?php foreach ( $variants as $variant => $label ) : ?>
						<?php $signature = build_email_signature( $company, $variant ); ?>
						<div class="rm-card mb-3">
							<div class="rm-card-header"><h2 class="rm-card-title"><?php echo esc_html( $label ); ?></h2></div>
							<div class="rm-card-body">
								<div class="rm-signature-preview mb-3">
									<div class="rm-sig-fake">Cu stimă,<br><strong><?php echo esc_html( $user['name'] ); ?></strong><br><?php echo esc_html( $company['name'] ); ?></div>
									<div id="sig-<?php echo esc_attr( $variant ); ?>"><?php echo $signature; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts in build_email_signature(). ?></div>
								</div>
								<textarea class="visually-hidden" id="sig-code-<?php echo esc_attr( $variant ); ?>" readonly tabindex="-1" aria-hidden="true"><?php echo esc_textarea( $signature ); ?></textarea>
								<div class="d-flex flex-wrap gap-2">
									<button type="button" class="btn btn-dark btn-sm" data-copy-html="#sig-<?php echo esc_attr( $variant ); ?>"><i class="bi bi-clipboard-check"></i> Copiază Semnătura Formatată (Vizual)</button>
									<button type="button" class="btn btn-light btn-sm" data-copy="#sig-code-<?php echo esc_attr( $variant ); ?>"><i class="bi bi-code-slash"></i> Copiază Codul HTML</button>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
				<div class="col-xl-5">
					<div class="rm-card rm-studio-sticky">
						<div class="rm-card-header"><h2 class="rm-card-title">Cum o adaugi în clientul de email</h2></div>
						<div class="rm-card-body">
							<ul class="nav nav-pills rm-tabs gap-1 mb-3 flex-wrap" role="tablist">
								<?php $first = true; ?>
								<?php foreach ( $tutorials as $key => $tutorial ) : ?>
									<li class="nav-item" role="presentation"><button class="nav-link btn-sm<?php echo $first ? ' active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#tut-<?php echo esc_attr( $key ); ?>" type="button" role="tab"><?php echo esc_html( $tutorial[0] ); ?></button></li>
									<?php $first = false; ?>
								<?php endforeach; ?>
							</ul>
							<div class="tab-content">
								<?php $first = true; ?>
								<?php foreach ( $tutorials as $key => $tutorial ) : ?>
									<div class="tab-pane fade<?php echo $first ? ' show active' : ''; ?>" id="tut-<?php echo esc_attr( $key ); ?>" role="tabpanel">
										<ol class="rm-steps small">
											<?php foreach ( $tutorial[1] as $step ) : ?>
												<li><?php echo esc_html( $step ); ?></li>
											<?php endforeach; ?>
										</ol>
									</div>
									<?php $first = false; ?>
								<?php endforeach; ?>
							</div>
							<div class="alert rm-alert-info small mb-0 mt-2"><i class="bi bi-lightbulb"></i> Linkurile folosesc <code>?src=email_sig</code>: în Feedback & Recenzii vezi exact câte evaluări aduc emailurile echipei.</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
<?php endif; ?>

<?php
require REPUVIO_ROOT . '/templates/app-footer.php';
