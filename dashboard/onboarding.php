<?php
/**
 * Guided 3-step onboarding wizard for business owners:
 * 1. Business identity (name → live /r/{slug}, required fiscal code CUI / CIF for invoicing, optional logo
 *    and cover; welcome text and CTA pre-filled).
 * 2. Google review link (g.page/r/..., writereview URL or Place ID, with the helper modal) and the
 *    visual star-threshold "reputation shield". Saving creates the company.
 * 3. Celebration: live QR code, phone mock-up of the public page, next actions.
 *
 * Also handles the dashboard checklist actions (POST action=dismiss_checklist | mark_materials).
 * Steps 1-2 are one multipart form; onboarding.js turns it into a wizard (without JS both steps show).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$user     = require_owner();
$limits   = get_user_limits( $user );
$defaults = get_onboarding_defaults();
$errors   = [];
$step     = 1;
$form     = [
	'name'               => '',
	'tax_id'             => '',
	'slug'               => '',
	'google_input'       => '',
	'min_star_threshold' => 3,
];

if ( is_post() ) {
	$action = input_post( 'action' );

	if ( 'dismiss_checklist' === $action ) {
		db_query( 'UPDATE users SET onboarding_dismissed = 1 WHERE id = ?', [ (int) $user['id'] ] );
		flash( 'success', 'Ghidul de activare a fost ascuns. Îl găsești oricând în „Ajutor & Ghid”.' );
		redirect( app_url( 'dashboard/index.php' ) );
	}

	if ( 'mark_materials' === $action ) {
		mark_onboarding_materials( (int) $user['id'] );
		redirect( app_url( 'dashboard/index.php' ) );
	}

	if ( 'create' === $action ) {
		$form['name']               = sanitize_text( input_post( 'name' ), 150 );
		$form['slug']               = sanitize_slug( input_post( 'slug' ), 80 );
		$tax_id                     = parse_tax_id( input_post( 'tax_id' ) );
		$form['tax_id']             = $tax_id['value'];
		$form['google_input']       = trim( sanitize_text( input_post( 'google_input' ), 500 ) );
		$form['min_star_threshold'] = sanitize_int_range( input_post( 'min_star_threshold', '3' ), 1, 5, 3 );
		$google                     = parse_google_review_input( $form['google_input'] );

		if ( count_user_companies( (int) $user['id'] ) >= $limits['max_companies'] ) {
			$errors[] = 'Ai atins numărul maxim de locații pentru abonamentul tău (' . $limits['max_companies'] . ').';
		}

		if ( 2 > mb_strlen( $form['name'] ) ) {
			$errors[] = 'Introdu numele afacerii.';
		}

		if ( '' !== $tax_id['error'] ) {
			$errors[] = $tax_id['error'];
		}

		if ( '' === $form['slug'] ) {
			$form['slug'] = generate_unique_slug( $form['name'] );
		} elseif ( 3 > strlen( $form['slug'] ) || ! is_slug_available( $form['slug'] ) ) {
			$form['slug'] = generate_unique_slug( $form['slug'] );
		}

		if ( empty( $errors ) && '' !== $google['error'] ) {
			$errors[] = $google['error'];
			$step     = 2;
		}

		$logo  = '';
		$cover = '';

		if ( empty( $errors ) && isset( $_FILES['logo'] ) ) {
			$upload = process_image_upload( $_FILES['logo'], 'logo' );

			if ( '' !== $upload['error'] ) {
				$errors[] = 'Logo: ' . $upload['error'];
			}

			$logo = $upload['path'];
		}

		if ( empty( $errors ) && isset( $_FILES['cover'] ) ) {
			$upload = process_image_upload( $_FILES['cover'], 'cover' );

			if ( '' !== $upload['error'] ) {
				$errors[] = 'Copertă: ' . $upload['error'];
			}

			$cover = $upload['path'];
		}

		if ( ! empty( $errors ) ) {
			delete_upload( $logo );
			delete_upload( $cover );
		} else {
			db_query(
				"INSERT INTO companies (user_id, name, tax_id, slug, description, logo_path, cover_path, google_place_id, google_review_url, notification_emails, min_star_threshold, redirect_mode, cta_text)
				VALUES (:user_id, :name, :tax_id, :slug, :description, :logo_path, :cover_path, :google_place_id, :google_review_url, :notification_emails, :min_star_threshold, 'helper', :cta_text)",
				[
					'user_id'             => (int) $user['id'],
					'name'                => $form['name'],
					'tax_id'              => $form['tax_id'],
					'slug'                => $form['slug'],
					'description'         => $defaults['description'],
					'logo_path'           => '' !== $logo ? $logo : null,
					'cover_path'          => '' !== $cover ? $cover : null,
					'google_place_id'     => $google['place_id'],
					'google_review_url'   => $google['url'],
					'notification_emails' => $user['email'],
					'min_star_threshold'  => (int) $form['min_star_threshold'],
					'cta_text'            => $defaults['cta_text'],
				]
			);

			$company_id = db_insert_id();
			audit_log( 'company_created', 'company=' . $company_id . ' via=onboarding', (int) $user['id'] );
			redirect( app_url( 'dashboard/onboarding.php', [ 'step' => 3, 'company_id' => $company_id ] ) );
		}
	}
}

$company = null;

if ( '3' === input_get( 'step' ) ) {
	$company = get_owned_company( (int) input_get( 'company_id', '0' ) );
	$step    = $company ? 3 : 1;
}

$companies_count = count_user_companies( (int) $user['id'] );
$at_limit        = 3 !== $step && $companies_count >= $limits['max_companies'];
$existing        = $at_limit ? get_user_companies( (int) $user['id'] ) : [];

$page_title       = 'Configurare rapidă';
$active_nav       = 'companies';
$page_breadcrumbs = [ [ 'Acasă', app_url( 'dashboard/index.php' ) ], [ 'Ghid de configurare', '' ] ];
$page_scripts     = [ asset_url( 'js/onboarding.js' ) ];

if ( 3 === $step ) {
	$page_scripts = [ 'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js', asset_url( 'js/printables.js' ) ];
}

$page_inline_data = [
	'baseUrl'    => defined( 'PRETTY_URLS' ) && PRETTY_URLS ? app_url( 'r/' ) : app_url( 'review.php' ) . '?b=',
	'startStep'  => $step,
	'ownerEmail' => $user['email'],
];

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<div class="rm-wizard">
	<div class="rm-wizard-top">
		<div class="rm-wizard-progress" aria-label="Progres configurare">
			<?php foreach ( [ 1 => 'Identitate', 2 => 'Google & prag', 3 => 'Testează & descarcă' ] as $number => $label ) : ?>
				<div class="rm-wizard-step<?php echo $number < $step ? ' is-done' : ( $number === $step ? ' is-active' : '' ); ?>" data-wizard-indicator="<?php echo esc_attr( $number ); ?>">
					<span class="rm-wizard-dot"><?php echo esc_html( $number ); ?></span>
					<span class="rm-wizard-label"><?php echo esc_html( $label ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<div class="progress rm-progress mt-3" role="progressbar" aria-label="Progres" aria-valuemin="0" aria-valuemax="100">
			<div class="progress-bar" data-wizard-bar data-width="<?php echo esc_attr( 3 === $step ? 100 : ( 2 === $step ? 66 : 33 ) ); ?>"></div>
		</div>
		<?php if ( 3 !== $step ) : ?>
			<div class="text-end mt-2">
				<a class="small text-muted" href="<?php echo esc_url( app_url( 'dashboard/company-edit.php', [ 'advanced' => 1 ] ) ); ?>">Sari peste ghid / Mergi la formularul avansat <i class="bi bi-arrow-right"></i></a>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( ! empty( $errors ) ) : ?>
		<div class="alert alert-danger">
			<?php foreach ( $errors as $error ) : ?>
				<div><?php echo esc_html( $error ); ?></div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( 3 === $step ) : ?>
		<?php
		$public_url = get_company_public_url( $company );
		$qr_url     = get_company_public_url( $company, 'qr' );
		$preview    = $public_url . ( false === strpos( $public_url, '?' ) ? '?' : '&' ) . 'preview=1';
		?>
		<div class="rm-celebrate mb-4">
			<div class="rm-celebrate-icon"><i class="bi bi-shield-check"></i></div>
			<div>
				<h2 class="h4 mb-1">Felicitări! Scutul de reputație pentru <?php echo esc_html( $company['name'] ); ?> este activ!</h2>
				<p class="mb-0 text-muted">Clienții mulțumiți ajung pe Google, iar nemulțumirile ajung privat la tine, la <strong><?php echo esc_html( $user['email'] ); ?></strong>.</p>
			</div>
		</div>

		<?php if ( '' === get_company_google_url( $company ) ) : ?>
			<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> Nu ai setat încă linkul Google. Clienții vor primi un mesaj de mulțumire în loc de redirecționare. <a href="<?php echo esc_url( app_url( 'dashboard/company-edit.php', [ 'id' => (int) $company['id'] ] ) ); ?>">Adaugă linkul acum</a>.</div>
		<?php endif; ?>

		<div class="row g-4 align-items-center">
			<div class="col-lg-5">
				<div class="rm-card rm-card-body text-center">
					<div class="rm-onboard-qr" data-qr="<?php echo esc_attr( $qr_url ); ?>"></div>
					<p class="fw-semibold text-dark mt-3 mb-1"><i class="bi bi-phone"></i> Îndreaptă camera telefonului tău spre acest cod QR chiar acum pentru a testa experiența clienților tăi!</p>
					<p class="small text-muted mb-0 text-break"><?php echo esc_html( $public_url ); ?></p>
				</div>
				<div class="d-grid gap-2 mt-3">
					<a class="btn btn-dark btn-lg" href="<?php echo esc_url( app_url( 'dashboard/printables.php', [ 'company_id' => (int) $company['id'] ] ) ); ?>"><i class="bi bi-file-earmark-pdf"></i> Descarcă Afișul A4 Gata de Print (PDF)</a>
					<a class="btn btn-light" href="<?php echo esc_url( $public_url ); ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Testează Pagina Publică în Browser</a>
					<a class="btn btn-light" href="<?php echo esc_url( app_url( 'dashboard/index.php' ) ); ?>"><i class="bi bi-grid-1x2"></i> Mergi la Panoul de Control (Dashboard)</a>
				</div>
			</div>
			<div class="col-lg-7 d-flex justify-content-center">
				<div class="rm-phone" aria-label="Previzualizare pe telefon">
					<div class="rm-phone-notch"></div>
					<iframe src="<?php echo esc_url( $preview ); ?>" title="Pagina publică de evaluare" loading="lazy"></iframe>
				</div>
			</div>
		</div>

	<?php elseif ( $at_limit ) : ?>
		<div class="rm-card rm-card-body">
			<h2 class="h5">Ai deja <?php echo esc_html( $companies_count ); ?> din <?php echo esc_html( $limits['max_companies'] ); ?> locații incluse în <?php echo esc_html( $limits['plan_name'] ); ?></h2>
			<p class="text-muted">Pentru o locație nouă fă upgrade. Poți relua oricând ultimul pas al ghidului pentru o companie existentă:</p>
			<div class="d-flex flex-wrap gap-2">
				<?php foreach ( $existing as $item ) : ?>
					<a class="btn btn-light" href="<?php echo esc_url( app_url( 'dashboard/onboarding.php', [ 'step' => 3, 'company_id' => (int) $item['id'] ] ) ); ?>"><i class="bi bi-qr-code"></i> <?php echo esc_html( $item['name'] ); ?></a>
				<?php endforeach; ?>
				<a class="btn btn-dark" href="<?php echo esc_url( app_url( 'dashboard/billing.php' ) ); ?>"><i class="bi bi-arrow-up-circle"></i> Vezi abonamentele</a>
			</div>
		</div>

	<?php else : ?>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( app_url( 'dashboard/onboarding.php' ) ); ?>" id="rmWizardForm" novalidate>
			<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<input type="hidden" name="action" value="create">
			<input type="hidden" name="MAX_FILE_SIZE" value="5242880">

			<section class="rm-card rm-wizard-panel mb-3" data-wizard-step="1">
				<div class="rm-card-body p-4">
					<div class="d-flex justify-content-between align-items-start gap-2 mb-3">
						<div>
							<h2 class="h4 mb-1">Identitatea afacerii tale</h2>
							<p class="text-muted mb-0">Durează aproximativ 30 de secunde.</p>
						</div>
						<span class="rm-pill rm-pill-muted"><i class="bi bi-clock"></i> 30 sec</span>
					</div>

					<label class="form-label" for="wiz-name">Nume afacere *</label>
					<input type="text" class="form-control form-control-lg" id="wiz-name" name="name" value="<?php echo esc_attr( $form['name'] ); ?>" maxlength="150" required autofocus data-slug-source placeholder="Ex: Bistro Teiul Verde">
					<div class="rm-slug-preview mt-2">
						<i class="bi bi-link-45deg"></i> Link public: <code><span data-slug-base></span><span data-slug-preview><?php echo esc_html( '' !== $form['slug'] ? $form['slug'] : 'nume-afacere' ); ?></span></code>
						<button class="btn btn-link btn-sm p-0 ms-1" type="button" data-bs-toggle="collapse" data-bs-target="#wizSlug" aria-expanded="false">Modifică</button>
					</div>
					<div class="row g-3 mt-1">
						<div class="col-md-6">
							<label class="form-label" for="wiz-tax-id">Cod fiscal (CUI / CIF) *</label>
							<input type="text" class="form-control" id="wiz-tax-id" name="tax_id" value="<?php echo esc_attr( $form['tax_id'] ); ?>" maxlength="20" required autocomplete="off" placeholder="Ex: 14399840 sau RO14399840">
							<div class="form-text">Necesar pentru facturare. Cu prefix „RO” dacă firma este plătitoare de TVA.</div>
						</div>
					</div>
					<div class="collapse mt-2" id="wizSlug">
						<div class="input-group input-group-sm" style="max-width: 420px;">
							<span class="input-group-text">/r/</span>
							<input type="text" class="form-control" name="slug" value="<?php echo esc_attr( $form['slug'] ); ?>" maxlength="80" pattern="[a-z0-9-]+" data-slug-target aria-label="Slug link public">
						</div>
						<div class="form-text">Doar litere mici, cifre și cratimă.</div>
					</div>

					<div class="row g-3 mt-2">
						<div class="col-md-5">
							<label class="form-label" for="wiz-logo">Logo companie <span class="text-muted fw-normal">(opțional)</span></label>
							<label class="rm-dropzone" for="wiz-logo">
								<img id="wizLogoPreview" class="d-none" alt="">
								<span class="rm-dropzone-empty" data-dropzone-empty><i class="bi bi-cloud-arrow-up"></i><span>Trage logo-ul aici sau apasă pentru a alege</span></span>
								<input type="file" class="rm-dropzone-input" id="wiz-logo" name="logo" accept="image/jpeg,image/png,image/webp,image/svg+xml,.svg" data-svg-raster="1200" data-min-width="500" data-min-height="500" data-ratio="1" data-ratio-hint="Logo-ul nu este pătrat: va fi centrat în cerc, cu spațiu alb în jur. Pentru cel mai bun rezultat folosește varianta pătrată a siglei (doar simbolul)." data-preview="#wizLogoPreview" data-image-hint="#wizLogoHint">
							</label>
							<div class="form-text d-none" id="wizLogoHint" aria-live="polite"></div>
							<div class="form-text">Pătrat, minim <strong>500 × 500 px</strong> (ideal 1000 × 1000), PNG cu fundal transparent sau <strong>SVG</strong>. Se afișează într-un cerc: alege varianta pătrată a siglei.</div>
							<div class="form-text">Nu ai logo-ul la îndemână? Nicio problemă, îl poți încărca mai târziu — vom genera automat o pictogramă elegantă cu inițialele firmei.</div>
						</div>
						<div class="col-md-7">
							<label class="form-label" for="wiz-cover">Imagine copertă <span class="text-muted fw-normal">(opțional)</span></label>
							<label class="rm-cover-drop" for="wiz-cover">
								<img id="wizCoverPreview" class="d-none" alt="">
								<span class="rm-cover-drop-badge"><i class="bi bi-image"></i> Alege o copertă</span>
								<span class="rm-cover-drop-initial" data-initial-preview>N</span>
								<input type="file" class="rm-dropzone-input" id="wiz-cover" name="cover" accept="image/jpeg,image/png,image/webp,image/svg+xml,.svg" data-svg-raster="2400" data-min-width="1600" data-min-height="600" data-ratio="2.67" data-ratio-hint="Coperta nu are format lat (aprox. 8:3): marginile de sus și de jos vor fi decupate." data-preview="#wizCoverPreview" data-image-hint="#wizCoverHint">
							</label>
							<div class="form-text d-none" id="wizCoverHint" aria-live="polite"></div>
							<div class="form-text">Format lat, minim <strong>1600 × 600 px</strong> (raport aprox. 8:3), JPG / PNG / WEBP / SVG. Pe telefon marginile pot fi decupate — ține elementele importante în centru. Fără copertă afișăm acest fundal monocrom elegant.</div>
						</div>
					</div>
				</div>
				<div class="rm-card-body border-top d-flex justify-content-end">
					<button type="button" class="btn btn-dark btn-lg" data-wizard-next="2">Continuă <i class="bi bi-arrow-right"></i></button>
				</div>
			</section>

			<section class="rm-card rm-wizard-panel mb-3" data-wizard-step="2">
				<div class="rm-card-body p-4">
					<div class="d-flex justify-content-between align-items-start gap-2 mb-3">
						<div>
							<h2 class="h4 mb-1">Conectarea cu Google Maps & scutul de reputație</h2>
							<p class="text-muted mb-0">Durează aproximativ 45 de secunde.</p>
						</div>
						<span class="rm-pill rm-pill-muted"><i class="bi bi-clock"></i> 45 sec</span>
					</div>

					<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
						<label class="form-label mb-0" for="wiz-google">Linkul tău de recenzii Google</label>
						<button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#rmGoogleHelpModal"><i class="bi bi-question-circle"></i> Cum găsesc linkul meu Google în 10 secunde?</button>
					</div>
					<input type="text" class="form-control form-control-lg" id="wiz-google" name="google_input" value="<?php echo esc_attr( $form['google_input'] ); ?>" placeholder="https://g.page/r/.../review" maxlength="500" inputmode="url" autocomplete="off">
					<div class="rm-google-formats mt-2">
						<span class="rm-pill rm-pill-positive"><i class="bi bi-star-fill"></i> Recomandat: https://g.page/r/...</span>
						<span class="rm-pill rm-pill-muted">writereview?placeid=...</span>
						<span class="rm-pill rm-pill-muted">Place ID (ChIJ...)</span>
					</div>
					<div class="form-text">Nu îl ai acum? Poți continua și îl adaugi mai târziu din Companii → Editează.</div>

					<h3 class="h6 mt-4 mb-2">Scutul tău de reputație</h3>
					<div class="row g-2">
						<?php
						$thresholds = [
							3 => [ '3 stele și peste', 'Echilibrat: 3–5★ ajung pe Google.', '' ],
							4 => [ '4 stele și peste', 'Recomandat pentru creșterea mediei', 'rm-pill-positive' ],
							5 => [ 'Doar 5 stele', 'Strict: doar clienții încântați ajung pe Google.', '' ],
						];
						?>
						<?php foreach ( $thresholds as $value => $threshold ) : ?>
							<div class="col-md-4">
								<label class="rm-template-option d-block h-100">
									<input type="radio" class="visually-hidden" name="min_star_threshold" value="<?php echo esc_attr( $value ); ?>"<?php echo $value === (int) $form['min_star_threshold'] ? ' checked' : ''; ?> data-threshold-input>
									<span class="rm-template-card d-block h-100 p-3">
										<span class="d-block text-warning mb-1"><?php echo esc_html( str_repeat( '★', $value ) ); ?><span class="text-muted"><?php echo esc_html( str_repeat( '★', 5 - $value ) ); ?></span></span>
										<span class="fw-semibold text-dark d-block"><?php echo esc_html( $threshold[0] ); ?></span>
										<?php if ( '' !== $threshold[2] ) : ?>
											<span class="rm-pill <?php echo esc_attr( $threshold[2] ); ?> mt-1"><?php echo esc_html( $threshold[1] ); ?></span>
										<?php else : ?>
											<span class="small text-muted"><?php echo esc_html( $threshold[1] ); ?></span>
										<?php endif; ?>
									</span>
								</label>
							</div>
						<?php endforeach; ?>
					</div>

					<div class="rm-shield mt-3" aria-live="polite">
						<div class="rm-shield-row is-private">
							<span class="rm-shield-stars" data-shield-low>1★ – 2★</span>
							<span class="rm-shield-tag">Sub prag</span>
							<span class="rm-shield-text">Clientul rămâne pe pagină și trimite o sesizare privată direct pe emailul tău (<strong><?php echo esc_html( $user['email'] ); ?></strong>), fără să afecteze media pe Google.</span>
						</div>
						<div class="rm-shield-row is-google">
							<span class="rm-shield-stars" data-shield-high>3★ – 5★</span>
							<span class="rm-shield-tag">La / peste prag</span>
							<span class="rm-shield-text">Clientul este redirecționat automat către profilul tău Google Maps pentru a publica recenzia.</span>
						</div>
					</div>
				</div>
				<div class="rm-card-body border-top d-flex justify-content-between">
					<button type="button" class="btn btn-light" data-wizard-next="1"><i class="bi bi-arrow-left"></i> Înapoi</button>
					<button type="submit" class="btn btn-dark btn-lg"><i class="bi bi-shield-check"></i> Activează scutul de reputație</button>
				</div>
			</section>
		</form>
	<?php endif; ?>
</div>

<?php
if ( 3 !== $step && ! $at_limit ) {
	require REPUVIO_ROOT . '/templates/google-link-modal.php';
}

require REPUVIO_ROOT . '/templates/app-footer.php';
