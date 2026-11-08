<?php
/**
 * Create / edit a company — the advanced form (branding uploads, Google link: g.page short link,
 * writereview URL or Place ID, required fiscal code CUI / CIF for invoicing, routing threshold, alert emails limited by plan, white-label toggle).
 * Business owners only — operators cannot change companies. Owners without any company are sent to
 * the onboarding wizard first, unless they open the advanced form explicitly (?advanced=1).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$user       = require_owner();
$company_id = sanitize_int_range( is_post() ? input_post( 'company_id', '0' ) : input_get( 'id', '0' ), 0, PHP_INT_MAX, 0 );
$company    = null;

if ( 0 < $company_id ) {
	$company = get_owned_company( $company_id );

	if ( ! $company ) {
		render_error_page( 404, 'Companie inexistentă', 'Compania nu există sau nu îți aparține.' );
	}
}

$is_new = null === $company;
$limits = get_user_limits( $user );

// First company: the guided wizard converts better; ?advanced=1 keeps this form reachable.
if ( '1' === input_get( 'advanced' ) ) {
	$_SESSION['onboarding_skipped'] = 1;
} elseif ( $is_new && ! is_post() && 0 === count_user_companies( (int) $user['id'] ) ) {
	redirect( app_url( 'dashboard/onboarding.php' ) );
}

if ( $is_new && count_user_companies( (int) $user['id'] ) >= $limits['max_companies'] ) {
	flash( 'warning', 'Ai atins numărul maxim de companii pentru abonamentul tău (' . $limits['max_companies'] . '). Fă upgrade pentru a adăuga mai multe.' );
	redirect( app_url( 'dashboard/companies.php' ) );
}

$defaults = [
	'name'                => '',
	'tax_id'              => '',
	'slug'                => '',
	'description'         => 'Mulțumim că ne-ai vizitat! Cum a fost experiența ta?',
	'google_place_id'     => '',
	'google_review_url'   => '',
	'notification_emails' => $user['email'],
	'email_delivery_mode' => 'global',
	'min_star_threshold'  => 3,
	'redirect_mode'       => 'helper',
	'cta_text'            => 'Scanează codul și spune-ne cum a fost!',
	'white_label_enabled' => 0,
	'is_active'           => 1,
	'logo_path'           => null,
	'cover_path'          => null,
];

$data   = $is_new ? $defaults : array_merge( $defaults, $company );
$errors = [];

if ( is_post() ) {
	$data['name']                = sanitize_text( input_post( 'name' ), 150 );
	$tax_id                      = parse_tax_id( input_post( 'tax_id' ) );
	$data['tax_id']              = $tax_id['value'];
	$data['slug']                = sanitize_slug( input_post( 'slug' ), 80 );
	$data['description']         = sanitize_textarea( input_post( 'description' ), 1000 );
	$data['google_place_id']     = trim( sanitize_text( input_post( 'google_place_id' ), 255 ) );
	$google_input                = parse_google_review_input( input_post( 'google_review_url' ) );
	$data['google_review_url']   = $google_input['url'];

	// A Place ID pasted into the link field is moved to the Place ID field.
	if ( '' !== $google_input['place_id'] && '' === $data['google_place_id'] ) {
		$data['google_place_id'] = $google_input['place_id'];
	}

	if ( '' !== $google_input['error'] ) {
		$data['google_review_url'] = trim( sanitize_text( input_post( 'google_review_url' ), 500 ) );
	}
	$data['min_star_threshold']  = sanitize_int_range( input_post( 'min_star_threshold', '3' ), 1, 5, 3 );
	$data['redirect_mode']       = 'immediate' === input_post( 'redirect_mode' ) ? 'immediate' : 'helper';
	$data['cta_text']            = sanitize_text( input_post( 'cta_text' ), 255 );
	$data['is_active']           = '1' === input_post( 'is_active' ) ? 1 : 0;
	$data['white_label_enabled'] = '1' === input_post( 'white_label_enabled' ) ? 1 : 0;
	$all_emails                  = parse_email_list( input_post( 'notification_emails' ), 50 );
	$notification_emails         = array_slice( $all_emails, 0, $limits['max_alert_emails'] );
	$data['notification_emails'] = implode( ', ', $notification_emails );
	$data['email_delivery_mode'] = array_key_exists( input_post( 'email_delivery_mode' ), get_review_delivery_modes() ) ? input_post( 'email_delivery_mode' ) : 'global';

	if ( count( $all_emails ) > $limits['max_alert_emails'] ) {
		$errors[] = 'Planul ' . $limits['plan_name'] . ' include ' . $limits['max_alert_emails'] . ' ' . ( 1 === $limits['max_alert_emails'] ? 'adresă' : 'adrese' ) . ' de email pentru alerte. Fă upgrade pentru mai multe.';
	}

	if ( 2 > mb_strlen( $data['name'] ) ) {
		$errors[] = 'Introdu numele afacerii.';
	}

	if ( '' !== $tax_id['error'] ) {
		$errors[] = $tax_id['error'];
	}

	if ( '' === $data['slug'] ) {
		$data['slug'] = generate_unique_slug( $data['name'], $is_new ? 0 : (int) $company['id'] );
	} elseif ( 3 > strlen( $data['slug'] ) ) {
		$errors[] = 'Slug-ul trebuie să aibă cel puțin 3 caractere.';
	} elseif ( ! is_slug_available( $data['slug'], $is_new ? 0 : (int) $company['id'] ) ) {
		$errors[] = 'Slug-ul „' . $data['slug'] . '” este deja folosit. Alege altul.';
	}

	if ( '' !== $data['google_place_id'] && ! is_valid_place_id( $data['google_place_id'] ) ) {
		$errors[] = 'Google Place ID nu este valid.';
	}

	if ( '' !== $google_input['error'] ) {
		$errors[] = $google_input['error'];
	}

	if ( '' !== trim( input_post( 'notification_emails' ) ) && empty( $notification_emails ) ) {
		$errors[] = 'Adresele de email pentru notificări nu sunt valide.';
	}

	if ( '' === $data['cta_text'] ) {
		$data['cta_text'] = $defaults['cta_text'];
	}

	$new_logo  = '';
	$new_cover = '';

	if ( empty( $errors ) && isset( $_FILES['logo'] ) ) {
		$upload = process_image_upload( $_FILES['logo'], 'logo' );
		if ( '' !== $upload['error'] ) {
			$errors[] = 'Logo: ' . $upload['error'];
		}
		$new_logo = $upload['path'];
	}

	if ( empty( $errors ) && isset( $_FILES['cover'] ) ) {
		$upload = process_image_upload( $_FILES['cover'], 'cover' );
		if ( '' !== $upload['error'] ) {
			$errors[] = 'Copertă: ' . $upload['error'];
		}
		$new_cover = $upload['path'];
	}

	if ( ! empty( $errors ) ) {
		// Discard files processed in this request when validation failed.
		delete_upload( $new_logo );
		delete_upload( $new_cover );
	} else {
		$old_logo  = $data['logo_path'];
		$old_cover = $data['cover_path'];

		if ( '' !== $new_logo ) {
			$data['logo_path'] = $new_logo;
		} elseif ( '1' === input_post( 'remove_logo' ) ) {
			$data['logo_path'] = null;
		}

		if ( '' !== $new_cover ) {
			$data['cover_path'] = $new_cover;
		} elseif ( '1' === input_post( 'remove_cover' ) ) {
			$data['cover_path'] = null;
		}

		$params = [
			'name'                => $data['name'],
			'tax_id'              => $data['tax_id'],
			'slug'                => $data['slug'],
			'description'         => $data['description'],
			'logo_path'           => $data['logo_path'],
			'cover_path'          => $data['cover_path'],
			'google_place_id'     => $data['google_place_id'],
			'google_review_url'   => $data['google_review_url'],
			'notification_emails' => $data['notification_emails'],
			'email_delivery_mode' => $data['email_delivery_mode'],
			'min_star_threshold'  => (int) $data['min_star_threshold'],
			'redirect_mode'       => $data['redirect_mode'],
			'cta_text'            => $data['cta_text'],
			'white_label_enabled' => (int) $data['white_label_enabled'],
			'is_active'           => (int) $data['is_active'],
		];

		if ( $is_new ) {
			$params['user_id'] = (int) $user['id'];

			db_query(
				'INSERT INTO companies (user_id, name, tax_id, slug, description, logo_path, cover_path, google_place_id, google_review_url, notification_emails, email_delivery_mode, min_star_threshold, redirect_mode, cta_text, white_label_enabled, is_active)
				VALUES (:user_id, :name, :tax_id, :slug, :description, :logo_path, :cover_path, :google_place_id, :google_review_url, :notification_emails, :email_delivery_mode, :min_star_threshold, :redirect_mode, :cta_text, :white_label_enabled, :is_active)',
				$params
			);

			$company_id = db_insert_id();
			audit_log( 'company_created', 'company=' . $company_id, (int) $user['id'] );
			flash( 'success', 'Compania a fost creată. Linkul public și codurile QR sunt gata!' );
		} else {
			$params['id']      = (int) $company['id'];
			$params['user_id'] = (int) $company['user_id'];

			db_query(
				'UPDATE companies SET name = :name, tax_id = :tax_id, slug = :slug, description = :description, logo_path = :logo_path, cover_path = :cover_path,
					google_place_id = :google_place_id, google_review_url = :google_review_url, notification_emails = :notification_emails,
					email_delivery_mode = :email_delivery_mode, min_star_threshold = :min_star_threshold, redirect_mode = :redirect_mode, cta_text = :cta_text,
					white_label_enabled = :white_label_enabled, is_active = :is_active
				WHERE id = :id AND user_id = :user_id',
				$params
			);

			if ( $old_logo !== $data['logo_path'] ) {
				delete_upload( $old_logo );
			}
			if ( $old_cover !== $data['cover_path'] ) {
				delete_upload( $old_cover );
			}

			flash( 'success', 'Modificările au fost salvate.' );
		}

		redirect( app_url( 'dashboard/company-edit.php', [ 'id' => $company_id ] ) );
	}
}

$logo_url  = upload_url( $data['logo_path'] );
$cover_url = upload_url( $data['cover_path'] );

$page_title       = $is_new ? 'Companie nouă' : $data['name'];
$active_nav       = 'companies';
$page_breadcrumbs = [ [ 'Acasă', app_url( 'dashboard/index.php' ) ], [ 'Companii', app_url( 'dashboard/companies.php' ) ], [ $is_new ? 'Adaugă' : 'Editează', '' ] ];

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<?php if ( ! empty( $errors ) ) : ?>
	<div class="alert alert-danger">
		<?php foreach ( $errors as $error ) : ?>
			<div><?php echo esc_html( $error ); ?></div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( app_url( 'dashboard/company-edit.php' ) ); ?>" novalidate>
	<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<input type="hidden" name="company_id" value="<?php echo esc_attr( $is_new ? 0 : $company['id'] ); ?>">
	<input type="hidden" name="MAX_FILE_SIZE" value="5242880">

	<div class="row g-3">
		<div class="col-xl-8">
			<div class="rm-card mb-3">
				<div class="rm-card-header"><h2 class="rm-card-title">Detalii afacere</h2></div>
				<div class="rm-card-body">
					<div class="row g-3">
						<div class="col-md-7">
							<label class="form-label" for="name">Nume afacere *</label>
							<input type="text" class="form-control" id="name" name="name" value="<?php echo esc_attr( $data['name'] ); ?>" maxlength="150" required data-slug-source>
						</div>
						<div class="col-md-5">
							<label class="form-label" for="tax_id">Cod fiscal (CUI / CIF) *</label>
							<input type="text" class="form-control" id="tax_id" name="tax_id" value="<?php echo esc_attr( $data['tax_id'] ); ?>" maxlength="20" required autocomplete="off" placeholder="Ex: 14399840 sau RO14399840">
							<div class="form-text">Necesar pentru facturare. Cu prefix „RO” dacă firma este plătitoare de TVA.</div>
						</div>
						<div class="col-md-7">
							<label class="form-label" for="slug">Slug link public</label>
							<div class="input-group">
								<span class="input-group-text small">/r/</span>
								<input type="text" class="form-control" id="slug" name="slug" value="<?php echo esc_attr( $data['slug'] ); ?>" maxlength="80" pattern="[a-z0-9-]+" data-slug-target>
							</div>
							<div class="form-text">Doar litere mici, cifre și cratimă.</div>
						</div>
						<div class="col-12">
							<label class="form-label" for="description">Mesaj de bun venit</label>
							<textarea class="form-control" id="description" name="description" rows="3" maxlength="1000"><?php echo esc_textarea( $data['description'] ); ?></textarea>
							<div class="form-text">Afișat clienților pe pagina de evaluare.</div>
						</div>
						<div class="col-12">
							<label class="form-label" for="cta_text">Text call-to-action pentru materialele tipărite</label>
							<input type="text" class="form-control" id="cta_text" name="cta_text" value="<?php echo esc_attr( $data['cta_text'] ); ?>" maxlength="255">
						</div>
					</div>
				</div>
			</div>

			<div class="rm-card mb-3">
				<div class="rm-card-header"><h2 class="rm-card-title">Google Reviews & rutare</h2></div>
				<div class="rm-card-body">
					<div class="row g-3">
						<div class="col-12">
							<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
								<label class="form-label mb-0" for="google_review_url">Link Google pentru recenzii</label>
								<button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#rmGoogleHelpModal"><i class="bi bi-question-circle"></i> Cum găsesc linkul meu Google în 10 secunde?</button>
							</div>
							<input type="text" class="form-control" id="google_review_url" name="google_review_url" value="<?php echo esc_attr( $data['google_review_url'] ); ?>" placeholder="https://g.page/r/.../review sau https://search.google.com/local/writereview?placeid=..." maxlength="500" inputmode="url" autocomplete="off">
							<div class="form-text">Recomandat: linkul scurt <code>https://g.page/r/...</code> din butonul „Solicită recenzii”. Merge și <code>writereview?placeid=...</code>; dacă lipești un Place ID aici, îl mutăm automat mai jos.</div>
						</div>
						<div class="col-12">
							<label class="form-label" for="google_place_id">sau Google Maps Place ID</label>
							<input type="text" class="form-control" id="google_place_id" name="google_place_id" value="<?php echo esc_attr( $data['google_place_id'] ); ?>" placeholder="ChIJ..." maxlength="255">
							<div class="form-text">Construim automat linkul <code>writereview?placeid=</code>. Linkul de mai sus are prioritate.</div>
						</div>
						<div class="col-md-6">
							<label class="form-label" for="min_star_threshold">Prag minim pentru Google</label>
							<select class="form-select" id="min_star_threshold" name="min_star_threshold">
								<?php for ( $stars = 1; 5 >= $stars; $stars++ ) : ?>
									<option value="<?php echo esc_attr( $stars ); ?>"<?php echo (int) $data['min_star_threshold'] === $stars ? ' selected' : ''; ?>><?php echo esc_html( $stars . ' ' . ( 1 === $stars ? 'stea' : 'stele' ) . ' și peste' ); ?></option>
								<?php endfor; ?>
							</select>
							<div class="form-text">Sub prag, clientul completează un feedback privat.</div>
						</div>
						<div class="col-md-6">
							<label class="form-label" for="redirect_mode">Redirecționare către Google</label>
							<select class="form-select" id="redirect_mode" name="redirect_mode">
								<option value="helper"<?php echo 'helper' === $data['redirect_mode'] ? ' selected' : ''; ?>>Cu pas intermediar (mulțumire + buton)</option>
								<option value="immediate"<?php echo 'immediate' === $data['redirect_mode'] ? ' selected' : ''; ?>>Imediată</option>
							</select>
						</div>
						<div class="col-12">
							<label class="form-label" for="notification_emails">Email-uri pentru alerte de feedback</label>
							<input type="text" class="form-control" id="notification_emails" name="notification_emails" value="<?php echo esc_attr( $data['notification_emails'] ); ?>" placeholder="<?php echo esc_attr( 1 < $limits['max_alert_emails'] ? 'manager@firma.ro, owner@firma.ro' : 'manager@firma.ro' ); ?>">
							<div class="form-text">Planul <?php echo esc_html( $limits['plan_name'] ); ?> include <strong><?php echo esc_html( $limits['max_alert_emails'] ); ?></strong> <?php echo esc_html( 1 === $limits['max_alert_emails'] ? 'adresă' : 'adrese (separate prin virgulă)' ); ?>. Gol = emailul contului.</div>
						</div>
						<div class="col-12">
							<label class="form-label" for="email_delivery_mode">Când primești emailurile despre recenzii</label>
							<select class="form-select" id="email_delivery_mode" name="email_delivery_mode">
								<option value="global"<?php echo 'global' === $data['email_delivery_mode'] ? ' selected' : ''; ?>><?php echo esc_html( 'Folosește setarea globală a platformei: ' . get_review_delivery_modes()[ get_global_delivery_mode() ][0] ); ?></option>
								<?php foreach ( get_review_delivery_modes() as $mode_key => $mode ) : ?>
									<option value="<?php echo esc_attr( $mode_key ); ?>"<?php echo $mode_key === $data['email_delivery_mode'] ? ' selected' : ''; ?>><?php echo esc_html( $mode[0] ); ?></option>
								<?php endforeach; ?>
							</select>
							<div class="form-text">Hibrid Smart: feedback-ul negativ vine imediat, iar restul activității o dată pe zi, dimineața, într-un rezumat al zilei precedente. Doar rezumat zilnic: un singur email pe zi, fără alerte în timpul zilei.</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="col-xl-4">
			<div class="rm-card mb-3">
				<div class="rm-card-header"><h2 class="rm-card-title">Branding</h2></div>
				<div class="rm-card-body">
					<label class="form-label" for="logo">Logo</label>
					<div class="d-flex align-items-center gap-3 mb-2">
						<img id="logoPreview" class="rm-logo-preview<?php echo '' === $logo_url ? ' d-none' : ''; ?>" src="<?php echo esc_url( $logo_url ); ?>" alt="Logo">
					</div>
					<input type="file" class="form-control" id="logo" name="logo" accept="image/jpeg,image/png,image/webp,image/svg+xml,.svg" data-svg-raster="1200" data-min-width="500" data-min-height="500" data-ratio="1" data-ratio-hint="Logo-ul nu este pătrat: va fi centrat în cerc, cu spațiu alb în jur. Pentru cel mai bun rezultat folosește varianta pătrată a siglei (doar simbolul)." data-preview="#logoPreview" data-image-hint="#logoHint">
					<div class="form-text">Pătrat, minim <strong>500 × 500 px</strong> (ideal 1000 × 1000), PNG cu fundal transparent sau <strong>SVG</strong>. Se afișează într-un cerc: alege varianta pătrată a siglei.</div>
					<div class="form-text d-none" id="logoHint" aria-live="polite"></div>
					<?php if ( '' !== $logo_url ) : ?>
						<div class="form-check mt-2">
							<input class="form-check-input" type="checkbox" value="1" id="remove_logo" name="remove_logo">
							<label class="form-check-label small" for="remove_logo">Elimină logo-ul</label>
						</div>
					<?php endif; ?>

					<hr>

					<label class="form-label" for="cover">Imagine copertă</label>
					<img id="coverPreview" class="rm-cover-preview mb-2<?php echo '' === $cover_url ? ' d-none' : ''; ?>" src="<?php echo esc_url( $cover_url ); ?>" alt="Copertă">
					<input type="file" class="form-control" id="cover" name="cover" accept="image/jpeg,image/png,image/webp,image/svg+xml,.svg" data-svg-raster="2400" data-min-width="1600" data-min-height="600" data-ratio="2.67" data-ratio-hint="Coperta nu are format lat (aprox. 8:3): marginile de sus și de jos vor fi decupate." data-preview="#coverPreview" data-image-hint="#coverHint">
					<div class="form-text">Format lat, minim <strong>1600 × 600 px</strong> (raport aprox. 8:3), JPG / PNG / WEBP / SVG. Pe telefon marginile pot fi decupate — ține elementele importante în centru.</div>
					<div class="form-text d-none" id="coverHint" aria-live="polite"></div>
					<?php if ( '' !== $cover_url ) : ?>
						<div class="form-check mt-2">
							<input class="form-check-input" type="checkbox" value="1" id="remove_cover" name="remove_cover">
							<label class="form-check-label small" for="remove_cover">Elimină coperta</label>
						</div>
					<?php endif; ?>
					<div class="form-text">Max. 5 MB per imagine. Imaginile sunt reprocesate (SVG-urile sunt convertite automat în PNG) și metadatele (EXIF) eliminate.</div>
				</div>
			</div>

			<div class="rm-card mb-3">
				<div class="rm-card-body">
					<div class="form-check form-switch">
						<input class="form-check-input" type="checkbox" role="switch" value="1" id="is_active" name="is_active"<?php echo 1 === (int) $data['is_active'] ? ' checked' : ''; ?>>
						<label class="form-check-label" for="is_active">Pagina publică este activă</label>
					</div>
					<div class="form-check form-switch mt-2">
						<input class="form-check-input" type="checkbox" role="switch" value="1" id="white_label_enabled" name="white_label_enabled"<?php echo 1 === (int) $data['white_label_enabled'] ? ' checked' : ''; ?>>
						<label class="form-check-label" for="white_label_enabled">White-label: ascunde „Protejat de <?php echo esc_html( app_name() ); ?>”</label>
					</div>
					<div class="form-text">Inclus în toate abonamentele.</div>
					<?php if ( ! $is_new ) : ?>
						<hr>
						<label class="form-label" for="public-url">Link public</label>
						<div class="input-group input-group-sm">
							<input type="text" class="form-control rm-copy-input" id="public-url" value="<?php echo esc_attr( get_company_public_url( $company ) ); ?>" readonly>
							<button class="btn btn-light" type="button" data-copy="#public-url"><i class="bi bi-clipboard"></i></button>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<div class="d-grid gap-2">
				<button type="submit" class="btn btn-dark"><i class="bi bi-check2"></i> <?php echo esc_html( $is_new ? 'Creează compania' : 'Salvează modificările' ); ?></button>
				<?php if ( ! $is_new ) : ?>
					<a class="btn btn-light" href="<?php echo esc_url( app_url( 'dashboard/printables.php', [ 'company_id' => (int) $company['id'] ] ) ); ?>"><i class="bi bi-qr-code"></i> Print Studio</a>
				<?php endif; ?>
			</div>
		</div>
	</div>
</form>

<?php
require REPUVIO_ROOT . '/templates/google-link-modal.php';
require REPUVIO_ROOT . '/templates/app-footer.php';
