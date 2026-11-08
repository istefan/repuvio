<?php
/**
 * Print Studio: company + template picker, employee / department QR tags (?ref=, Pro /
 * Enterprise / trial), CTA editor, live preview, print / PDF, SVG QR download and the optional
 * physical print upsell banner. Business owners only.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$user      = require_owner();

mark_onboarding_materials( (int) $user['id'] );
$companies = get_user_companies( (int) $user['id'] );
$templates = get_printable_templates();
$can_tag   = user_can_use_ref_tags( $user );

if ( is_post() ) {
	$action  = input_post( 'action' );
	$company = get_owned_company( (int) input_post( 'company_id' ) );

	if ( ! $company ) {
		flash( 'danger', 'Compania nu a fost găsită.' );
		redirect( app_url( 'dashboard/printables.php' ) );
	}

	if ( 'save_cta' === $action ) {
		$cta = sanitize_text( input_post( 'cta' ), 120 );

		if ( '' !== $cta ) {
			db_query( 'UPDATE companies SET cta_text = ? WHERE id = ? AND user_id = ?', [ $cta, (int) $company['id'], (int) $company['user_id'] ] );
			flash( 'success', 'Textul call-to-action a fost salvat ca implicit.' );
		}
	} elseif ( 'add_tag' === $action ) {
		if ( ! $can_tag ) {
			flash( 'warning', 'Codurile QR pe angajat / departament sunt incluse în planurile Pro și Enterprise.' );
		} else {
			$result = create_ref_tag( (int) $company['id'], input_post( 'tag_label' ) );

			if ( $result['success'] ) {
				audit_log( 'ref_tag_created', 'company=' . (int) $company['id'] . ' ref=' . $result['slug'] );
				flash( 'success', 'Eticheta a fost creată. Codul QR conține acum ?ref=' . $result['slug'] . '.' );
				redirect( app_url( 'dashboard/printables.php', [ 'company_id' => (int) $company['id'], 'ref' => $result['slug'] ] ) );
			}

			flash( 'danger', $result['error'] );
		}
	} elseif ( 'delete_tag' === $action ) {
		$deleted = db_query( 'DELETE FROM ref_tags WHERE id = ? AND company_id = ?', [ (int) input_post( 'tag_id' ), (int) $company['id'] ] )->rowCount();

		if ( 1 === $deleted ) {
			flash( 'success', 'Eticheta a fost ștearsă. Istoricul recenziilor păstrează codul etichetei.' );
		}
	}

	redirect( app_url( 'dashboard/printables.php', [ 'company_id' => (int) $company['id'] ] ) );
}

$company_id = sanitize_int_range( input_get( 'company_id', (string) get_current_company_id() ), 0, PHP_INT_MAX, 0 );
$company    = 0 < $company_id ? get_owned_company( $company_id ) : null;

if ( ! $company && ! empty( $companies ) ) {
	$company = $companies[0];
}

$ref_tags     = $company && $can_tag ? get_company_ref_tags( (int) $company['id'] ) : [];
$selected_ref = sanitize_ref_tag( input_get( 'ref' ) );

if ( '' !== $selected_ref && ! in_array( $selected_ref, array_column( $ref_tags, 'slug' ), true ) ) {
	$selected_ref = '';
}

$page_title       = 'Print Studio';
$active_nav       = 'printables';
$page_breadcrumbs = [ [ 'Acasă', app_url( 'dashboard/index.php' ) ], [ 'Print Studio', '' ] ];

if ( $company ) {
	$page_scripts     = [ 'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js', asset_url( 'js/printables.js' ), asset_url( 'js/studio.js' ) ];
	$page_inline_data = [
		'printUrl'   => app_url( 'dashboard/print.php' ),
		'companyId'  => (int) $company['id'],
		'publicUrl'  => get_company_public_url( $company ),
		'slug'       => $company['slug'],
		'sources'    => array_map(
			function ( $spec ) {
				return $spec['source'];
			},
			$templates
		),
		'defaultCta' => $company['cta_text'],
	];
}

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<?php if ( ! $company ) : ?>
	<div class="rm-card">
		<div class="rm-empty">
			<i class="bi bi-qr-code"></i>
			<h2 class="h5 mt-3">Adaugă o companie pentru a genera materiale</h2>
			<a class="btn btn-dark mt-2" href="<?php echo esc_url( app_url( 'dashboard/company-edit.php' ) ); ?>"><i class="bi bi-plus-lg"></i> Adaugă companie</a>
		</div>
	</div>
<?php else : ?>
	<div class="container-fluid px-0 rm-studio">
		<?php if ( is_print_upsell_enabled() ) : ?>
			<div class="rm-upsell d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
				<div>
					<div class="fw-semibold text-white"><i class="bi bi-box-seam"></i> Nu ai imprimantă? Ți le livrăm gata printate.</div>
					<div class="small">Stand A4 din plexiglas, flyere A5, cărți de vizită și stickere rezistente la apă, cu codul tău QR.</div>
				</div>
				<a class="btn btn-light btn-sm" href="<?php echo esc_url( app_url( 'dashboard/print-order.php', [ 'company_id' => (int) $company['id'] ] ) ); ?>">Comandă printuri <i class="bi bi-arrow-right"></i></a>
			</div>
		<?php endif; ?>

		<div class="row g-3">
			<div class="col-12 col-xl-4">
				<div class="rm-card mb-3">
					<div class="rm-card-header"><h2 class="rm-card-title">1. Companie</h2></div>
					<div class="rm-card-body">
						<form method="get" action="<?php echo esc_url( app_url( 'dashboard/printables.php' ) ); ?>">
							<select class="form-select" name="company_id" data-autosubmit aria-label="Companie">
								<?php foreach ( $companies as $item ) : ?>
									<option value="<?php echo esc_attr( $item['id'] ); ?>"<?php echo (int) $item['id'] === (int) $company['id'] ? ' selected' : ''; ?>><?php echo esc_html( $item['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</form>
						<?php if ( '' === get_company_google_url( $company ) ) : ?>
							<div class="alert rm-alert-danger small mt-3 mb-0"><i class="bi bi-exclamation-triangle"></i> Compania nu are setat linkul Google Reviews. <a href="<?php echo esc_url( app_url( 'dashboard/company-edit.php', [ 'id' => (int) $company['id'] ] ) ); ?>">Configurează acum</a>.</div>
						<?php endif; ?>
					</div>
				</div>

				<div class="rm-card mb-3">
					<div class="rm-card-header"><h2 class="rm-card-title">2. Șablon</h2></div>
					<div class="rm-card-body">
						<div class="row g-2">
							<?php foreach ( $templates as $key => $spec ) : ?>
								<div class="col-6">
									<label class="rm-template-option d-block h-100">
										<input type="radio" class="visually-hidden" name="template" value="<?php echo esc_attr( $key ); ?>"<?php echo 'a4' === $key ? ' checked' : ''; ?> data-template>
										<span class="rm-template-card d-block">
											<span class="fw-semibold d-block small text-dark"><?php echo esc_html( $spec['label'] ); ?></span>
											<span class="small text-muted"><?php echo esc_html( $spec['width'] . ' × ' . $spec['height'] . ' mm' ); ?></span>
										</span>
									</label>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<div class="rm-card mb-3">
					<div class="rm-card-header">
						<h2 class="rm-card-title">3. Cod QR pe angajat / departament</h2>
						<span class="rm-pill <?php echo esc_attr( $can_tag ? 'rm-pill-positive' : 'rm-pill-muted' ); ?>">Pro & Enterprise</span>
					</div>
					<div class="rm-card-body">
						<?php if ( ! $can_tag ) : ?>
							<p class="small text-muted mb-2">Generează coduri QR dedicate pentru fiecare angajat, echipă, masă sau departament (<code>?ref=echipa-ionut</code>, <code>?ref=receptie</code>) și vezi cine aduce cele mai multe recenzii pozitive.</p>
							<a class="btn btn-sm btn-dark" href="<?php echo esc_url( app_url( 'dashboard/billing.php' ) ); ?>"><i class="bi bi-arrow-up-circle"></i> Upgrade la Pro</a>
						<?php else : ?>
							<label class="form-label small" for="rmRef">Etichetă codificată în QR</label>
							<select class="form-select mb-3" id="rmRef" data-ref-select>
								<option value="">General (fără etichetă)</option>
								<?php foreach ( $ref_tags as $tag ) : ?>
									<option value="<?php echo esc_attr( $tag['slug'] ); ?>" data-label="<?php echo esc_attr( $tag['label'] ); ?>"<?php echo $tag['slug'] === $selected_ref ? ' selected' : ''; ?>><?php echo esc_html( $tag['label'] . ' (?ref=' . $tag['slug'] . ')' ); ?></option>
								<?php endforeach; ?>
							</select>

							<form method="post" class="input-group input-group-sm mb-2" action="<?php echo esc_url( app_url( 'dashboard/printables.php' ) ); ?>">
								<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<input type="hidden" name="action" value="add_tag">
								<input type="hidden" name="company_id" value="<?php echo esc_attr( $company['id'] ); ?>">
								<input type="text" class="form-control" name="tag_label" maxlength="80" placeholder="Ex: Chelner Mihai, Recepție, Masa 4" aria-label="Etichetă nouă" required>
								<button type="submit" class="btn btn-dark"><i class="bi bi-plus-lg"></i> Adaugă</button>
							</form>

							<?php if ( ! empty( $ref_tags ) ) : ?>
								<div class="rm-tag-list mt-2">
									<?php foreach ( $ref_tags as $tag ) : ?>
										<div class="rm-tag-row small">
											<i class="bi bi-tag text-muted"></i>
											<span class="flex-grow-1 min-w-0 text-truncate"><span class="fw-medium text-dark"><?php echo esc_html( $tag['label'] ); ?></span> <span class="text-muted">?ref=<?php echo esc_html( $tag['slug'] ); ?></span></span>
											<input type="text" class="visually-hidden" readonly tabindex="-1" aria-hidden="true" id="tag-url-<?php echo esc_attr( $tag['id'] ); ?>" value="<?php echo esc_attr( get_company_public_url( $company, 'qr', $tag['slug'] ) ); ?>">
											<button type="button" class="btn btn-sm btn-light" data-copy="#tag-url-<?php echo esc_attr( $tag['id'] ); ?>" title="Copiază linkul etichetei"><i class="bi bi-clipboard"></i></button>
											<form method="post" class="d-inline" data-confirm="Ștergi eticheta? Codurile QR deja printate cu ea vor fi numărate ca link general.">
												<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
												<input type="hidden" name="action" value="delete_tag">
												<input type="hidden" name="company_id" value="<?php echo esc_attr( $company['id'] ); ?>">
												<input type="hidden" name="tag_id" value="<?php echo esc_attr( $tag['id'] ); ?>">
												<button type="submit" class="btn btn-sm btn-light text-danger" title="Șterge"><i class="bi bi-x-lg"></i></button>
											</form>
										</div>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				</div>

				<div class="rm-card mb-3">
					<div class="rm-card-header"><h2 class="rm-card-title">4. Text call-to-action</h2></div>
					<div class="rm-card-body">
						<form method="post" action="<?php echo esc_url( app_url( 'dashboard/printables.php' ) ); ?>">
							<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<input type="hidden" name="action" value="save_cta">
							<input type="hidden" name="company_id" value="<?php echo esc_attr( $company['id'] ); ?>">
							<textarea class="form-control mb-2" id="rmCta" name="cta" rows="2" maxlength="120"><?php echo esc_textarea( $company['cta_text'] ); ?></textarea>
							<div class="d-flex flex-wrap gap-1 mb-2">
								<?php foreach ( [ 'Scanează codul și spune-ne cum a fost!', 'Ți-a plăcut? Lasă-ne o recenzie!', 'Părerea ta contează pentru noi', 'Evaluează experiența în 10 secunde' ] as $suggestion ) : ?>
									<button type="button" class="btn btn-sm btn-light" data-cta-suggestion="<?php echo esc_attr( $suggestion ); ?>"><?php echo esc_html( $suggestion ); ?></button>
								<?php endforeach; ?>
							</div>
							<button type="submit" class="btn btn-sm btn-light"><i class="bi bi-save"></i> Salvează ca implicit</button>
						</form>
					</div>
				</div>

				<div class="rm-card">
					<div class="rm-card-header"><h2 class="rm-card-title">5. Descarcă</h2></div>
					<div class="rm-card-body d-grid gap-2">
						<a class="btn btn-dark" id="rmPrintLink" href="#" target="_blank" rel="noopener"><i class="bi bi-printer"></i> Printează / Descarcă PDF</a>
						<button type="button" class="btn btn-light" id="rmSvgDownload"><i class="bi bi-filetype-svg"></i> Descarcă doar codul QR (SVG vectorial)</button>
						<?php if ( is_print_upsell_enabled() ) : ?>
							<a class="btn btn-light" href="<?php echo esc_url( app_url( 'dashboard/print-order.php', [ 'company_id' => (int) $company['id'] ] ) ); ?>"><i class="bi bi-box-seam"></i> Comandă printuri</a>
						<?php endif; ?>
						<div class="small text-muted">Pentru PDF alege „Salvează ca PDF” în fereastra de printare, cu marginile setate pe „Niciuna”.</div>
						<div class="small text-muted text-break">Link urmărit: <code id="rmTrackedUrl"></code></div>
					</div>
				</div>
			</div>

			<div class="col-12 col-xl-8">
				<div class="rm-card rm-studio-sticky">
					<div class="rm-card-header">
						<h2 class="rm-card-title">Previzualizare live</h2>
						<span class="small text-muted" id="rmPreviewLabel"></span>
					</div>
					<div class="rm-card-body">
						<iframe id="rmPreview" class="rm-preview-frame" title="Previzualizare material tipărit"></iframe>
					</div>
				</div>
			</div>
		</div>
	</div>
<?php endif; ?>

<?php
require REPUVIO_ROOT . '/templates/app-footer.php';
