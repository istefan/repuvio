<?php
/**
 * Super Admin: global settings, trial defaults, provider legal details ("Date Juridice & Contact Furnizor"), DEV / PROD environment + anti-abuse cooldown,
 * physical print upsell master toggle, sales showcase accounts ("Cont demo"), email delivery
 * (SMTP / Google OAuth2 / App Password) and "Send Test Email" with live SMTP transcript.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$admin       = require_super_admin();
$drivers     = get_mail_drivers();
$errors      = [];
$test_result = null;
$active_tab  = in_array( input_get( 'tab' ), [ 'general', 'legal', 'environment', 'features', 'showcase', 'email', 'test' ], true ) ? input_get( 'tab' ) : 'general';

if ( is_post() ) {
	$action = input_post( 'action' );

	if ( 'general' === $action ) {
		$app_name      = sanitize_text( input_post( 'app_name' ), 60 );
		$support_email = sanitize_email_address( input_post( 'support_email' ) );
		if ( '' === $app_name ) {
			$errors[] = 'Numele aplicației este obligatoriu.';
		}
		if ( '' !== input_post( 'support_email' ) && '' === $support_email ) {
			$errors[] = 'Emailul de suport nu este valid.';
		}

		if ( empty( $errors ) ) {
			update_setting( 'app_name', $app_name );
			update_setting( 'support_email', $support_email );
			update_setting( 'trial_days', (string) sanitize_int_range( input_post( 'trial_days' ), 1, 365, 30 ) );
			update_setting( 'trial_max_reviews', (string) sanitize_int_range( input_post( 'trial_max_reviews' ), 1, 100000, 50 ) );
			update_setting( 'trial_max_companies', (string) sanitize_int_range( input_post( 'trial_max_companies' ), 1, 1000, 1 ) );
			update_setting( 'trial_max_alert_emails', (string) sanitize_int_range( input_post( 'trial_max_alert_emails' ), 1, 10, 1 ) );
			update_setting( 'bank_details', sanitize_textarea( input_post( 'bank_details' ), 1000 ) );

			audit_log( 'admin_settings_general' );
			flash( 'success', 'Setările generale au fost salvate.' );
			redirect( app_url( 'admin/settings.php', [ 'tab' => 'general' ] ) );
		}

		$active_tab = 'general';
	} elseif ( 'legal' === $action ) {
		$legal_values = [];

		foreach ( get_legal_setting_fields() as $key => $field ) {
			$legal_values[ $key ] = sanitize_text( input_post( $key ), 255 );
		}

		if ( '' === sanitize_email_address( $legal_values['legal_email'] ) ) {
			$errors[] = 'Emailul de contact / GDPR nu este valid.';
		}

		if ( false === filter_var( $legal_values['legal_company_url'], FILTER_VALIDATE_URL ) || ! preg_match( '#^https?://#i', $legal_values['legal_company_url'] ) ) {
			$errors[] = 'Website-ul furnizorului trebuie să fie un URL valid (https://...).';
		}

		foreach ( [ 'legal_company_name', 'legal_cui', 'legal_address', 'legal_last_updated' ] as $required_key ) {
			if ( '' === $legal_values[ $required_key ] ) {
				$errors[] = 'Completează câmpul „' . get_legal_setting_fields()[ $required_key ][1] . '”.';
			}
		}

		if ( empty( $errors ) ) {
			foreach ( $legal_values as $key => $value ) {
				update_setting( $key, 'legal_email' === $key ? sanitize_email_address( $value ) : $value );
			}

			audit_log( 'admin_settings_legal' );
			flash( 'success', 'Datele juridice au fost salvate. Paginile Termeni, Confidențialitate, Cookies și Contact sunt actualizate.' );
			redirect( app_url( 'admin/settings.php', [ 'tab' => 'legal' ] ) );
		}

		$active_tab = 'legal';
	} elseif ( 'environment' === $action ) {
		$environment = 'dev' === input_post( 'app_environment' ) ? 'dev' : 'prod';
		$previous    = get_app_environment();

		update_setting( 'app_environment', $environment );
		update_setting( 'review_cooldown_hours', (string) sanitize_int_range( input_post( 'review_cooldown_hours' ), 1, 720, 24 ) );

		if ( 'dev' === $environment && '1' === input_post( 'clear_funnel_limits' ) ) {
			db_query( "DELETE FROM rate_limits WHERE action IN ('review_rate', 'review_submit', 'funnel_view')" );
		}

		audit_log( 'admin_settings_environment', 'from=' . $previous . ' to=' . $environment );
		flash( 'dev' === $environment ? 'warning' : 'success', 'dev' === $environment ? 'Mod DEV activ: limitele anti-abuz de pe /r/{slug} sunt dezactivate. Nu uita să revii la PROD.' : 'Mod PROD activ: protecția anti-abuz este aplicată.' );
		redirect( app_url( 'admin/settings.php', [ 'tab' => 'environment' ] ) );
	} elseif ( 'features' === $action ) {
		update_setting( 'enable_physical_print_upsell', '1' === input_post( 'enable_physical_print_upsell' ) ? '1' : '0' );

		audit_log( 'admin_settings_features', 'print=' . get_setting( 'enable_physical_print_upsell' ) );
		flash( 'success', 'Setările modulelor au fost salvate.' );
		redirect( app_url( 'admin/settings.php', [ 'tab' => 'features' ] ) );
	} elseif ( 'showcase' === $action ) {
		$showcase_enabled  = '1' === input_post( 'showcase_enabled' );
		$owner_email       = sanitize_email_address( input_post( 'showcase_owner_email' ) );
		$operator_email    = sanitize_email_address( input_post( 'showcase_operator_email' ) );
		$notify_email      = sanitize_email_address( input_post( 'showcase_notify_email' ) );
		$showcase_google   = parse_google_review_input( input_post( 'showcase_google_url' ) );
		$showcase_password = input_post( 'showcase_password' );

		if ( '' === $owner_email || '' === $operator_email ) {
			$errors[] = 'Adresele conturilor demo (proprietar și operator) trebuie să fie valide.';
		} elseif ( strtolower( $owner_email ) === strtolower( $operator_email ) ) {
			$errors[] = 'Contul de proprietar și cel de operator trebuie să aibă adrese diferite.';
		} elseif ( 0 < (int) db_value( 'SELECT COUNT(*) FROM users WHERE email IN (?, ?) AND is_showcase = 0', [ $owner_email, $operator_email ] ) ) {
			$errors[] = 'Una dintre adrese aparține deja unui cont real. Alege alte adrese pentru conturile demo.';
		}
		if ( '' !== input_post( 'showcase_notify_email' ) && '' === $notify_email ) {
			$errors[] = 'Adresa pentru notificările demo nu este validă.';
		}
		if ( '' !== $showcase_google['error'] ) {
			$errors[] = $showcase_google['error'];
		}
		if ( '' !== $showcase_password ) {
			$strength = validate_password_strength( $showcase_password, input_post( 'showcase_password_confirm' ) );
			if ( '' !== $strength ) {
				$errors[] = 'Parola conturilor demo: ' . $strength;
			}
		} elseif ( $showcase_enabled && '' === get_showcase_setting( 'showcase_password_hash' ) ) {
			$errors[] = 'Setează parola conturilor demo (prima activare).';
		}

		if ( empty( $errors ) ) {
			update_setting( 'showcase_enabled', $showcase_enabled ? '1' : '0' );
			update_setting( 'showcase_owner_email', $owner_email );
			update_setting( 'showcase_owner_name', sanitize_text( input_post( 'showcase_owner_name' ), 150 ) ?: get_showcase_defaults()['showcase_owner_name'] );
			update_setting( 'showcase_operator_email', $operator_email );
			update_setting( 'showcase_operator_name', sanitize_text( input_post( 'showcase_operator_name' ), 150 ) ?: get_showcase_defaults()['showcase_operator_name'] );
			update_setting( 'showcase_company_name', sanitize_text( input_post( 'showcase_company_name' ), 150 ) ?: get_showcase_defaults()['showcase_company_name'] );
			update_setting( 'showcase_google_url', '' !== $showcase_google['url'] ? $showcase_google['url'] : $showcase_google['place_id'] );
			update_setting( 'showcase_notify_email', $notify_email );

			if ( '' !== $showcase_password ) {
				update_setting( 'showcase_password_hash', hash_user_password( $showcase_password ) );
			}

			audit_log( 'admin_settings_showcase', 'enabled=' . ( $showcase_enabled ? 1 : 0 ) );

			if ( $showcase_enabled ) {
				$reset = reset_showcase_demo();
				flash( $reset['success'] ? 'success' : 'danger', $reset['success'] ? 'Conturile demo au fost salvate și regenerate (' . $reset['summary'] . ').' : 'Setările au fost salvate, dar regenerarea a eșuat: ' . $reset['error'] );
			} else {
				db_query( "UPDATE users SET status = 'suspended' WHERE is_showcase = 1" );
				flash( 'warning', 'Setările au fost salvate. Conturile demo sunt dezactivate (nu există sau nu se pot autentifica). Pentru a le crea apasă „Activează și creează conturile”.' );
			}

			redirect( app_url( 'admin/settings.php', [ 'tab' => 'showcase' ] ) );
		}

		$active_tab = 'showcase';
	} elseif ( 'showcase_reset' === $action ) {
		$reset = is_showcase_enabled() ? reset_showcase_demo() : [
			'success' => false,
			'error'   => 'Activează mai întâi conturile demo.',
			'summary' => '',
		];

		flash( $reset['success'] ? 'success' : 'danger', $reset['success'] ? 'Datele demo au fost regenerate (' . $reset['summary'] . ').' : $reset['error'] );
		redirect( app_url( 'admin/settings.php', [ 'tab' => 'showcase' ] ) );
	} elseif ( 'email' === $action ) {
		$driver     = array_key_exists( input_post( 'mail_driver' ), $drivers ) ? input_post( 'mail_driver' ) : 'log';
		$from_email = sanitize_email_address( input_post( 'mail_from_email' ) );
		$smtp_host  = sanitize_text( input_post( 'smtp_host' ), 190 );
		$redirect   = trim( sanitize_text( input_post( 'google_redirect_uri' ), 255 ) );

		if ( '' === $from_email ) {
			$errors[] = 'Adresa expeditorului nu este validă.';
		}
		if ( '' !== $smtp_host && ! preg_match( '/^[a-z0-9.\-]+$/i', $smtp_host ) ) {
			$errors[] = 'Hostul SMTP nu este valid.';
		}
		if ( '' !== $redirect && ( false === filter_var( $redirect, FILTER_VALIDATE_URL ) || ! preg_match( '#^https?://#', $redirect ) ) ) {
			$errors[] = 'Redirect URI nu este valid.';
		}
		if ( '' !== input_post( 'google_email' ) && '' === sanitize_email_address( input_post( 'google_email' ) ) ) {
			$errors[] = 'Adresa Gmail nu este validă.';
		}

		if ( empty( $errors ) ) {
			update_setting( 'mail_driver', $driver );
			update_setting( 'mail_from_email', $from_email );
			update_setting( 'mail_from_name', sanitize_text( input_post( 'mail_from_name' ), 100 ) );
			update_setting( 'smtp_host', $smtp_host );
			update_setting( 'smtp_port', (string) sanitize_int_range( input_post( 'smtp_port' ), 1, 65535, 587 ) );
			update_setting( 'smtp_encryption', in_array( input_post( 'smtp_encryption' ), [ 'tls', 'ssl', 'none' ], true ) ? input_post( 'smtp_encryption' ) : 'tls' );
			update_setting( 'smtp_username', sanitize_text( input_post( 'smtp_username' ), 190 ) );
			update_setting( 'google_email', sanitize_email_address( input_post( 'google_email' ) ) );
			update_setting( 'google_client_id', sanitize_text( input_post( 'google_client_id' ), 255 ) );
			update_setting( 'google_redirect_uri', $redirect );

			// Secrets: empty input keeps the stored value; the "clear" checkbox removes it.
			foreach ( [ 'smtp_password', 'google_client_secret', 'google_app_password' ] as $secret_key ) {
				$value = input_post( $secret_key );

				if ( '1' === input_post( 'clear_' . $secret_key ) ) {
					update_setting( $secret_key, '' );
				} elseif ( '' !== $value ) {
					update_setting( $secret_key, mb_substr( $value, 0, 500 ) );
				}
			}

			audit_log( 'admin_settings_email', 'driver=' . $driver );
			flash( 'success', 'Setările de email au fost salvate. Trimite un email de test pentru verificare.' );
			redirect( app_url( 'admin/settings.php', [ 'tab' => 'email' ] ) );
		}

		$active_tab = 'email';
	} elseif ( 'test' === $action ) {
		$to = sanitize_email_address( input_post( 'test_email' ) );

		if ( '' === $to ) {
			$errors[] = 'Introdu o adresă validă pentru testare.';
		} elseif ( rate_limit_is_locked( 'test_email' ) ) {
			$errors[] = 'Prea multe emailuri de test. Așteaptă 10 minute.';
		} else {
			rate_limit_hit( 'test_email', 10, 600, 600 );
			$test_result = send_test_email( $to );
			audit_log( 'admin_test_email', 'success=' . ( $test_result['success'] ? 1 : 0 ) );
		}

		$active_tab = 'test';
	}
}

$settings      = load_settings( true );
$google_linked = '' !== ( $settings['google_refresh_token'] ?? '' );

/**
 * Placeholder text for secret fields.
 *
 * @param array  $settings Settings.
 * @param string $key      Key.
 * @return string
 */
function secret_placeholder( array $settings, $key ) {
	return '' !== ( $settings[ $key ] ?? '' ) ? '•••••••• (salvat — lasă gol pentru a păstra)' : 'Nesetat';
}

$page_title       = 'Setări & Email';
$active_nav       = 'settings';
$page_breadcrumbs = [ [ 'Super Admin', app_url( 'admin/index.php' ) ], [ 'Setări', '' ] ];

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<?php if ( ! empty( $errors ) ) : ?>
	<div class="alert alert-danger">
		<?php foreach ( $errors as $error ) : ?>
			<div><?php echo esc_html( $error ); ?></div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<ul class="nav nav-pills rm-tabs gap-1 mb-3" role="tablist">
	<li class="nav-item" role="presentation"><button class="nav-link<?php echo 'general' === $active_tab ? ' active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#tab-general" type="button" role="tab">General</button></li>
	<li class="nav-item" role="presentation"><button class="nav-link<?php echo 'legal' === $active_tab ? ' active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#tab-legal" type="button" role="tab">Date juridice</button></li>
	<li class="nav-item" role="presentation"><button class="nav-link<?php echo 'environment' === $active_tab ? ' active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#tab-environment" type="button" role="tab">Mediu & Anti-abuz</button></li>
	<li class="nav-item" role="presentation"><button class="nav-link<?php echo 'features' === $active_tab ? ' active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#tab-features" type="button" role="tab">Printuri & Rapoarte</button></li>
	<li class="nav-item" role="presentation"><button class="nav-link<?php echo 'showcase' === $active_tab ? ' active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#tab-showcase" type="button" role="tab">Cont demo</button></li>
	<li class="nav-item" role="presentation"><button class="nav-link<?php echo 'email' === $active_tab ? ' active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#tab-email" type="button" role="tab">Livrare email</button></li>
	<li class="nav-item" role="presentation"><button class="nav-link<?php echo 'test' === $active_tab ? ' active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#tab-test" type="button" role="tab">Email de test</button></li>
</ul>

<div class="tab-content">
	<div class="tab-pane fade<?php echo 'general' === $active_tab ? ' show active' : ''; ?>" id="tab-general" role="tabpanel">
		<form method="post" class="rm-card" novalidate>
			<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<input type="hidden" name="action" value="general">
			<div class="rm-card-body">
				<div class="row g-3">
					<div class="col-md-6">
						<label class="form-label" for="app_name">Nume aplicație</label>
						<input type="text" class="form-control" id="app_name" name="app_name" value="<?php echo esc_attr( $settings['app_name'] ?? '' ); ?>" maxlength="60" required>
					</div>
					<div class="col-md-6">
						<label class="form-label" for="support_email">Email suport (primește cererile de abonament)</label>
						<input type="email" class="form-control" id="support_email" name="support_email" value="<?php echo esc_attr( $settings['support_email'] ?? '' ); ?>">
					</div>
					<div class="col-md-4">
						<label class="form-label" for="trial_days">Zile perioadă de probă</label>
						<input type="number" min="1" max="365" class="form-control" id="trial_days" name="trial_days" value="<?php echo esc_attr( $settings['trial_days'] ?? '30' ); ?>">
					</div>
					<div class="col-md-4">
						<label class="form-label" for="trial_max_reviews">Cotă recenzii în probă / lună</label>
						<input type="number" min="1" class="form-control" id="trial_max_reviews" name="trial_max_reviews" value="<?php echo esc_attr( $settings['trial_max_reviews'] ?? '50' ); ?>">
					</div>
					<div class="col-md-4">
						<label class="form-label" for="trial_max_companies">Companii în probă</label>
						<input type="number" min="1" class="form-control" id="trial_max_companies" name="trial_max_companies" value="<?php echo esc_attr( $settings['trial_max_companies'] ?? '1' ); ?>">
					</div>
					<div class="col-md-4">
						<label class="form-label" for="trial_max_alert_emails">Emailuri de alertă în probă</label>
						<input type="number" min="1" max="10" class="form-control" id="trial_max_alert_emails" name="trial_max_alert_emails" value="<?php echo esc_attr( $settings['trial_max_alert_emails'] ?? '1' ); ?>">
					</div>
					<div class="col-md-8 d-flex align-items-end">
						<div class="form-text">În perioada de probă toate funcțiile sunt deblocate (inclusiv codurile QR pe angajat), cu limitele de mai sus.</div>
					</div>
					<div class="col-12">
						<label class="form-label" for="bank_details">Date de plată (afișate clienților)</label>
						<textarea class="form-control" id="bank_details" name="bank_details" rows="4" maxlength="1000"><?php echo esc_textarea( $settings['bank_details'] ?? '' ); ?></textarea>
					</div>
				</div>
			</div>
			<div class="rm-card-body border-top"><button type="submit" class="btn btn-dark">Salvează</button></div>
		</form>
	</div>

	<div class="tab-pane fade<?php echo 'legal' === $active_tab ? ' show active' : ''; ?>" id="tab-legal" role="tabpanel">
		<?php $legal_placeholders = get_legal_placeholders(); ?>
		<?php if ( ! empty( $legal_placeholders ) ) : ?>
			<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> Câmpuri încă necompletate (apar public pe paginile legale): <strong><?php echo esc_html( implode( ', ', $legal_placeholders ) ); ?></strong>.</div>
		<?php endif; ?>
		<form method="post" class="rm-card" novalidate>
			<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<input type="hidden" name="action" value="legal">
			<div class="rm-card-header"><h2 class="rm-card-title"><i class="bi bi-bank"></i> Date Juridice & Contact Furnizor</h2></div>
			<div class="rm-card-body">
				<p class="small text-muted">Aceste date apar pe paginile <a href="<?php echo esc_url( legal_url( 'terms' ) ); ?>" target="_blank" rel="noopener">Termeni și Condiții</a>, <a href="<?php echo esc_url( legal_url( 'privacy' ) ); ?>" target="_blank" rel="noopener">Confidențialitate</a>, <a href="<?php echo esc_url( legal_url( 'cookies' ) ); ?>" target="_blank" rel="noopener">Cookies</a>, <a href="<?php echo esc_url( legal_url( 'contact' ) ); ?>" target="_blank" rel="noopener">Contact</a> și în subsolul paginilor publice.</p>
				<div class="row g-3">
					<?php $current_legal = get_legal_settings(); ?>
					<?php foreach ( get_legal_setting_fields() as $key => $field ) : ?>
						<div class="col-md-6">
							<label class="form-label" for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field[1] ); ?></label>
							<input type="<?php echo esc_attr( 'legal_email' === $key ? 'email' : ( 'legal_company_url' === $key ? 'url' : 'text' ) ); ?>" class="form-control" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $current_legal[ $key ] ); ?>" maxlength="255">
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="rm-card-body border-top"><button type="submit" class="btn btn-dark">Salvează datele juridice</button></div>
		</form>
	</div>

	<div class="tab-pane fade<?php echo 'environment' === $active_tab ? ' show active' : ''; ?>" id="tab-environment" role="tabpanel">
		<form method="post" class="rm-card" novalidate>
			<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<input type="hidden" name="action" value="environment">
			<div class="rm-card-body">
				<div class="row g-3">
					<div class="col-lg-6">
						<label class="form-label d-block">Mediu aplicație</label>
						<div class="d-grid gap-2">
							<label class="rm-template-option">
								<input type="radio" class="visually-hidden" name="app_environment" value="prod"<?php echo 'prod' === get_app_environment() ? ' checked' : ''; ?>>
								<span class="rm-template-card d-block">
									<span class="fw-semibold text-dark d-block"><i class="bi bi-shield-check text-success"></i> PROD — protecție anti-abuz activă</span>
									<span class="small text-muted">Maxim o evaluare numărată per dispozitiv (cookie) sau per IP + browser, pe companie, în fereastra de mai jos. Rate limiting pe IP, honeypot, timer semnat și CSRF.</span>
								</span>
							</label>
							<label class="rm-template-option">
								<input type="radio" class="visually-hidden" name="app_environment" value="dev"<?php echo 'dev' === get_app_environment() ? ' checked' : ''; ?>>
								<span class="rm-template-card d-block">
									<span class="fw-semibold text-dark d-block"><i class="bi bi-bug text-warning"></i> DEV — testare nelimitată</span>
									<span class="small text-muted">Dezactivează rate limiting-ul și blocarea recenziilor duplicate pe /r/{slug}, ca să poți simula oricâte evaluări din același browser. Honeypot-ul și CSRF rămân active. Un badge „DEV” apare în antet.</span>
								</span>
							</label>
						</div>
					</div>
					<div class="col-lg-6">
						<label class="form-label" for="review_cooldown_hours">Fereastră anti-duplicat (ore, doar PROD)</label>
						<input type="number" min="1" max="720" class="form-control" id="review_cooldown_hours" name="review_cooldown_hours" value="<?php echo esc_attr( get_review_cooldown_hours() ); ?>">
						<div class="form-text mb-3">Implicit 24. Un client care a votat deja este trimis în continuare pe Google (sau vede un mesaj de mulțumire), dar evaluarea nu mai este numărată în cotă.</div>
						<div class="form-check">
							<input class="form-check-input" type="checkbox" value="1" id="clear_funnel_limits" name="clear_funnel_limits">
							<label class="form-check-label small" for="clear_funnel_limits">La trecerea în DEV, șterge blocările de rate limit existente pentru funnel</label>
						</div>
						<div class="alert rm-alert-info small mt-3 mb-0"><i class="bi bi-info-circle"></i> O „recenzie” numărată în cotă = orice interacțiune completă cu stele (redirecționare Google sau feedback privat). Simpla deschidere a paginii este doar o vizualizare.</div>
					</div>
				</div>
			</div>
			<div class="rm-card-body border-top"><button type="submit" class="btn btn-dark">Salvează mediul</button></div>
		</form>
	</div>

	<div class="tab-pane fade<?php echo 'features' === $active_tab ? ' show active' : ''; ?>" id="tab-features" role="tabpanel">
		<form method="post" class="rm-card" novalidate>
			<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<input type="hidden" name="action" value="features">
			<div class="rm-card-body">
				<div class="form-check form-switch mb-1">
					<input class="form-check-input" type="checkbox" role="switch" id="enable_physical_print_upsell" name="enable_physical_print_upsell" value="1"<?php echo is_print_upsell_enabled() ? ' checked' : ''; ?>>
					<label class="form-check-label fw-semibold text-dark" for="enable_physical_print_upsell">Comandă printuri fizice: <?php echo esc_html( is_print_upsell_enabled() ? 'Activat' : 'Ascuns' ); ?></label>
				</div>
				<p class="small text-muted ms-5">Când este ascuns, toate bannerele și butoanele de comandă dispar pentru Business Owners. Prețurile și produsele se editează în <a href="<?php echo esc_url( app_url( 'admin/print-products.php' ) ); ?>">Printuri fizice</a>.</p>

				<div class="alert rm-alert-info small mt-3 mb-0"><i class="bi bi-info-circle"></i> Raportul lunar ROI automat se pornește / oprește și se editează din <a href="<?php echo esc_url( app_url( 'admin/emails.php', [ 'tab' => 'templates' ] ) ); ?>">Email & Șabloane</a> (șablonul „Raport lunar ROI”). Trimiterea manuală: <a href="<?php echo esc_url( app_url( 'admin/roi-reports.php' ) ); ?>">Rapoarte ROI</a>.</div>
			</div>
			<div class="rm-card-body border-top"><button type="submit" class="btn btn-dark">Salvează</button></div>
		</form>
	</div>

	<div class="tab-pane fade<?php echo 'showcase' === $active_tab ? ' show active' : ''; ?>" id="tab-showcase" role="tabpanel">
		<?php
		$showcase_owner      = db_row( "SELECT id, status FROM users WHERE is_showcase = 1 AND role = 'user' LIMIT 1" );
		$showcase_company    = $showcase_owner ? db_row( 'SELECT id, name, slug FROM companies WHERE user_id = ? ORDER BY id ASC LIMIT 1', [ (int) $showcase_owner['id'] ] ) : null;
		$showcase_last_reset = get_showcase_setting( 'showcase_last_reset' );
		?>
		<div class="row g-3">
			<div class="col-xl-8">
				<form method="post" class="rm-card" novalidate>
					<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<input type="hidden" name="action" value="showcase">
					<div class="rm-card-body">
						<div class="d-flex flex-wrap align-items-center gap-2 mb-1">
							<span class="fw-semibold text-dark">Conturi demo pentru prezentări</span>
							<?php if ( is_showcase_enabled() ) : ?>
								<span class="rm-pill rm-pill-positive"><i class="bi bi-check2"></i> Active</span>
							<?php else : ?>
								<span class="rm-pill rm-pill-muted">Dezactivate</span>
							<?php endif; ?>
						</div>
						<p class="small text-muted mb-0">Un proprietar pe planul Pro (o companie fără logo și copertă, istoric de 3 luni, 2 coduri QR pe angajat) și un operator. Datele sunt regenerate automat în fiecare zi de cron și imediat la salvare.</p>
						<?php if ( ! is_showcase_enabled() ) : ?>
							<div class="alert rm-alert-info small mt-3 mb-0"><i class="bi bi-info-circle"></i> Completează datele de mai jos (inclusiv parola la prima activare) și apasă <strong>Activează și creează conturile</strong>.</div>
						<?php endif; ?>

						<div class="row g-3 mt-1">
							<div class="col-md-6">
								<label class="form-label" for="showcase_owner_email">Email cont proprietar</label>
								<input type="email" class="form-control" id="showcase_owner_email" name="showcase_owner_email" value="<?php echo esc_attr( get_showcase_setting( 'showcase_owner_email' ) ); ?>" required>
							</div>
							<div class="col-md-6">
								<label class="form-label" for="showcase_owner_name">Nume afișat proprietar</label>
								<input type="text" class="form-control" id="showcase_owner_name" name="showcase_owner_name" value="<?php echo esc_attr( get_showcase_setting( 'showcase_owner_name' ) ); ?>" maxlength="150">
							</div>
							<div class="col-md-6">
								<label class="form-label" for="showcase_operator_email">Email cont operator</label>
								<input type="email" class="form-control" id="showcase_operator_email" name="showcase_operator_email" value="<?php echo esc_attr( get_showcase_setting( 'showcase_operator_email' ) ); ?>" required>
							</div>
							<div class="col-md-6">
								<label class="form-label" for="showcase_operator_name">Nume afișat operator</label>
								<input type="text" class="form-control" id="showcase_operator_name" name="showcase_operator_name" value="<?php echo esc_attr( get_showcase_setting( 'showcase_operator_name' ) ); ?>" maxlength="150">
							</div>
							<div class="col-md-6">
								<label class="form-label" for="showcase_company_name">Numele companiei demo</label>
								<input type="text" class="form-control" id="showcase_company_name" name="showcase_company_name" value="<?php echo esc_attr( get_showcase_setting( 'showcase_company_name' ) ); ?>" maxlength="150">
								<div class="form-text">Nume inventat (nu folosi o firmă reală). Linkul public rămâne același de la o zi la alta.</div>
							</div>
							<div class="col-md-6">
								<label class="form-label" for="showcase_google_url">Link Google pentru demo <span class="text-muted fw-normal">(opțional)</span></label>
								<input type="text" class="form-control" id="showcase_google_url" name="showcase_google_url" value="<?php echo esc_attr( get_showcase_setting( 'showcase_google_url' ) ); ?>" placeholder="https://g.page/r/.../review">
								<div class="form-text">Unde ajunge clientul după 4–5 stele când testezi live. Gol = un Place ID fictiv.</div>
							</div>
							<div class="col-12">
								<label class="form-label" for="showcase_notify_email">Email pentru notificările conturilor demo</label>
								<input type="email" class="form-control" id="showcase_notify_email" name="showcase_notify_email" value="<?php echo esc_attr( get_showcase_setting( 'showcase_notify_email' ) ); ?>" placeholder="ex: adresa ta, pentru a arăta alertele în prezentare">
								<div class="form-text">Niciun email nu pleacă spre adresele demo. Alertele de feedback negativ, cota, rapoartele ROI etc. ajung aici, cu subiectul prefixat „[Demo]”. Gol = nu se trimite nimic.</div>
							</div>
							<div class="col-md-6">
								<label class="form-label" for="showcase_password">Parola conturilor demo</label>
								<input type="password" class="form-control" id="showcase_password" name="showcase_password" minlength="10" autocomplete="new-password" placeholder="<?php echo esc_attr( '' !== get_showcase_setting( 'showcase_password_hash' ) ? '•••••••• (setată — lasă gol pentru a păstra)' : 'Nesetată' ); ?>">
							</div>
							<div class="col-md-6">
								<label class="form-label" for="showcase_password_confirm">Confirmă parola</label>
								<input type="password" class="form-control" id="showcase_password_confirm" name="showcase_password_confirm" minlength="10" autocomplete="new-password">
							</div>
							<div class="col-12">
								<div class="form-text">Aceeași parolă pentru ambele conturi. Din contul demo nu se pot schimba numele, emailul sau parola, nu se poate cere resetarea parolei, iar comenzile de abonament / printuri nu sunt trimise.</div>
							</div>
						</div>
					</div>
					<div class="rm-card-body border-top d-flex flex-wrap gap-2">
						<?php if ( is_showcase_enabled() ) : ?>
							<button type="submit" class="btn btn-dark" name="showcase_enabled" value="1"><i class="bi bi-arrow-repeat"></i> Salvează și regenerează</button>
							<button type="submit" class="btn btn-light" name="showcase_enabled" value="0" data-confirm="Dezactivezi conturile demo? Nu se vor mai putea autentifica până le reactivezi."><i class="bi bi-pause-circle"></i> Dezactivează conturile</button>
						<?php else : ?>
							<button type="submit" class="btn btn-dark" name="showcase_enabled" value="1"><i class="bi bi-play-circle"></i> Activează și creează conturile</button>
							<button type="submit" class="btn btn-light" name="showcase_enabled" value="0">Doar salvează (rămân dezactivate)</button>
						<?php endif; ?>
					</div>
				</form>
			</div>
			<div class="col-xl-4">
				<div class="rm-card">
					<div class="rm-card-header"><h2 class="rm-card-title">Stare</h2></div>
					<div class="rm-card-body small">
						<div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Ultima regenerare</span><strong class="text-dark"><?php echo esc_html( '' !== $showcase_last_reset ? format_datetime( $showcase_last_reset ) : '—' ); ?></strong></div>
						<div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Cont proprietar</span><strong class="text-dark"><?php echo esc_html( $showcase_owner ? ( 'active' === $showcase_owner['status'] ? 'Activ' : 'Dezactivat' ) : 'Necreat' ); ?></strong></div>
						<div class="d-flex justify-content-between py-1"><span class="text-muted">Companie</span><strong class="text-dark text-truncate ms-2"><?php echo esc_html( $showcase_company ? $showcase_company['name'] : '—' ); ?></strong></div>

						<?php if ( $showcase_company ) : ?>
							<a class="btn btn-sm btn-light w-100 mt-3" href="<?php echo esc_url( get_company_public_url( $showcase_company, 'direct' ) ); ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Pagina publică de recenzii</a>
						<?php endif; ?>

						<?php if ( $showcase_owner && 'active' === $showcase_owner['status'] ) : ?>
							<form method="post" action="<?php echo esc_url( app_url( 'admin/impersonate.php' ) ); ?>" class="mt-2">
								<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<input type="hidden" name="user_id" value="<?php echo esc_attr( (int) $showcase_owner['id'] ); ?>">
								<button type="submit" class="btn btn-sm btn-light w-100"><i class="bi bi-person-badge"></i> Intră ca proprietarul demo</button>
							</form>
						<?php endif; ?>

						<form method="post" class="mt-2">
							<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<input type="hidden" name="action" value="showcase_reset">
							<button type="submit" class="btn btn-sm btn-outline-dark w-100" data-confirm="Regenerezi acum toate datele demo? Logo-ul, coperta și modificările făcute azi se pierd."<?php echo is_showcase_enabled() ? '' : ' disabled'; ?>><i class="bi bi-arrow-repeat"></i> Regenerează acum</button>
						</form>
					</div>
				</div>
				<div class="alert rm-alert-info small mt-3 mb-0"><i class="bi bi-info-circle"></i> Regenerarea zilnică rulează la primul cron al zilei (<code>cron.php</code>). Asigură-te că jobul cron este programat pe server.</div>
			</div>
		</div>
	</div>

	<div class="tab-pane fade<?php echo 'email' === $active_tab ? ' show active' : ''; ?>" id="tab-email" role="tabpanel">
		<form method="post" class="rm-card mb-3" novalidate autocomplete="off">
			<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<input type="hidden" name="action" value="email">
			<div class="rm-card-body">
				<div class="row g-3">
					<div class="col-md-4">
						<label class="form-label" for="mail_driver">Metodă de trimitere</label>
						<select class="form-select" id="mail_driver" name="mail_driver" data-mail-driver>
							<?php foreach ( $drivers as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"<?php echo $key === ( $settings['mail_driver'] ?? 'log' ) ? ' selected' : ''; ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="col-md-4">
						<label class="form-label" for="mail_from_email">Email expeditor</label>
						<input type="email" class="form-control" id="mail_from_email" name="mail_from_email" value="<?php echo esc_attr( $settings['mail_from_email'] ?? '' ); ?>" required>
					</div>
					<div class="col-md-4">
						<label class="form-label" for="mail_from_name">Nume expeditor</label>
						<input type="text" class="form-control" id="mail_from_name" name="mail_from_name" value="<?php echo esc_attr( $settings['mail_from_name'] ?? '' ); ?>" maxlength="100">
					</div>
				</div>

				<div class="mt-4" data-driver-section="smtp">
					<h3 class="h6">SMTP standard</h3>
					<div class="row g-3">
						<div class="col-md-5">
							<label class="form-label" for="smtp_host">Host</label>
							<input type="text" class="form-control" id="smtp_host" name="smtp_host" value="<?php echo esc_attr( $settings['smtp_host'] ?? '' ); ?>" placeholder="mail.domeniu.ro">
						</div>
						<div class="col-md-3">
							<label class="form-label" for="smtp_port">Port</label>
							<input type="number" class="form-control" id="smtp_port" name="smtp_port" value="<?php echo esc_attr( $settings['smtp_port'] ?? '587' ); ?>">
						</div>
						<div class="col-md-4">
							<label class="form-label" for="smtp_encryption">Criptare</label>
							<select class="form-select" id="smtp_encryption" name="smtp_encryption">
								<?php foreach ( [ 'tls' => 'TLS (STARTTLS, port 587)', 'ssl' => 'SSL (port 465)', 'none' => 'Fără (nerecomandat)' ] as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>"<?php echo $key === ( $settings['smtp_encryption'] ?? 'tls' ) ? ' selected' : ''; ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="col-md-6">
							<label class="form-label" for="smtp_username">Utilizator</label>
							<input type="text" class="form-control" id="smtp_username" name="smtp_username" value="<?php echo esc_attr( $settings['smtp_username'] ?? '' ); ?>" autocomplete="off">
						</div>
						<div class="col-md-6">
							<label class="form-label" for="smtp_password">Parolă (criptată AES-256)</label>
							<input type="password" class="form-control" id="smtp_password" name="smtp_password" placeholder="<?php echo esc_attr( secret_placeholder( $settings, 'smtp_password' ) ); ?>" autocomplete="new-password">
							<div class="form-check mt-1"><input class="form-check-input" type="checkbox" value="1" id="clear_smtp_password" name="clear_smtp_password"><label class="form-check-label small" for="clear_smtp_password">Șterge parola salvată</label></div>
						</div>
					</div>
				</div>

				<div class="mt-4" data-driver-section="google_oauth google_app_password">
					<h3 class="h6">Cont Google</h3>
					<div class="row g-3">
						<div class="col-md-6">
							<label class="form-label" for="google_email">Adresa Gmail / Google Workspace</label>
							<input type="email" class="form-control" id="google_email" name="google_email" value="<?php echo esc_attr( $settings['google_email'] ?? '' ); ?>">
						</div>
					</div>
				</div>

				<div class="mt-3" data-driver-section="google_oauth">
					<div class="row g-3">
						<div class="col-md-6">
							<label class="form-label" for="google_client_id">OAuth Client ID</label>
							<input type="text" class="form-control" id="google_client_id" name="google_client_id" value="<?php echo esc_attr( $settings['google_client_id'] ?? '' ); ?>">
						</div>
						<div class="col-md-6">
							<label class="form-label" for="google_client_secret">OAuth Client Secret (criptat)</label>
							<input type="password" class="form-control" id="google_client_secret" name="google_client_secret" placeholder="<?php echo esc_attr( secret_placeholder( $settings, 'google_client_secret' ) ); ?>" autocomplete="new-password">
							<div class="form-check mt-1"><input class="form-check-input" type="checkbox" value="1" id="clear_google_client_secret" name="clear_google_client_secret"><label class="form-check-label small" for="clear_google_client_secret">Șterge secretul salvat</label></div>
						</div>
						<div class="col-12">
							<label class="form-label" for="google_redirect_uri">Authorized Redirect URI</label>
							<input type="url" class="form-control rm-copy-input" id="google_redirect_uri" name="google_redirect_uri" value="<?php echo esc_attr( $settings['google_redirect_uri'] ?? '' ); ?>" placeholder="<?php echo esc_attr( app_url( 'admin/google-oauth.php' ) ); ?>">
							<div class="form-text">Adaugă exact acest URL în Google Cloud Console → Credentials. Gol = <code><?php echo esc_html( app_url( 'admin/google-oauth.php' ) ); ?></code>. Scope: <code>https://mail.google.com/</code></div>
						</div>
					</div>
				</div>

				<div class="mt-3" data-driver-section="google_app_password">
					<div class="row g-3">
						<div class="col-md-6">
							<label class="form-label" for="google_app_password">Google App Password (criptată)</label>
							<input type="password" class="form-control" id="google_app_password" name="google_app_password" placeholder="<?php echo esc_attr( secret_placeholder( $settings, 'google_app_password' ) ); ?>" autocomplete="new-password">
							<div class="form-check mt-1"><input class="form-check-input" type="checkbox" value="1" id="clear_google_app_password" name="clear_google_app_password"><label class="form-check-label small" for="clear_google_app_password">Șterge parola salvată</label></div>
							<div class="form-text">Generată din myaccount.google.com → Securitate → Parole pentru aplicații (necesită 2FA). Folosește smtp.gmail.com:587.</div>
						</div>
					</div>
				</div>

				<div class="mt-3" data-driver-section="log">
					<div class="alert rm-alert-info small mb-0"><i class="bi bi-info-circle"></i> Driverul „Jurnal local” nu trimite emailuri; le scrie în <code>storage/logs/mail.log</code>. Folosește-l doar la dezvoltare.</div>
				</div>
				<div class="mt-3" data-driver-section="php_mail">
					<div class="alert rm-alert-info small mb-0"><i class="bi bi-info-circle"></i> Folosește serverul de email al hostingului (funcția <code>mail()</code>). Livrabilitatea poate fi mai slabă decât prin SMTP autentificat.</div>
				</div>
			</div>
			<div class="rm-card-body border-top"><button type="submit" class="btn btn-dark">Salvează setările de email</button></div>
		</form>

		<div class="rm-card" data-driver-section="google_oauth">
			<div class="rm-card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
				<div>
					<div class="fw-semibold text-dark">Conexiune OAuth Google</div>
					<?php if ( $google_linked ) : ?>
						<div class="small text-success"><i class="bi bi-check-circle-fill"></i> Conectat (refresh token salvat criptat) pentru <?php echo esc_html( $settings['google_email'] ?? '' ); ?></div>
					<?php else : ?>
						<div class="small text-muted">Neconectat. Salvează Client ID / Secret, apoi conectează contul.</div>
					<?php endif; ?>
				</div>
				<div class="d-flex gap-2">
					<form method="post" action="<?php echo esc_url( app_url( 'admin/google-oauth.php' ) ); ?>">
						<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<input type="hidden" name="action" value="connect">
						<button type="submit" class="btn btn-primary"><i class="bi bi-google"></i> <?php echo esc_html( $google_linked ? 'Reconectează' : 'Conectează contul Google' ); ?></button>
					</form>
					<?php if ( $google_linked ) : ?>
						<form method="post" action="<?php echo esc_url( app_url( 'admin/google-oauth.php' ) ); ?>" data-confirm="Deconectezi contul Google?">
							<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<input type="hidden" name="action" value="disconnect">
							<button type="submit" class="btn btn-light text-danger">Deconectează</button>
						</form>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>

	<div class="tab-pane fade<?php echo 'test' === $active_tab ? ' show active' : ''; ?>" id="tab-test" role="tabpanel">
		<div class="rm-card">
			<div class="rm-card-body">
				<form method="post" class="row g-2 align-items-end" novalidate>
					<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<input type="hidden" name="action" value="test">
					<div class="col-md-6">
						<label class="form-label" for="test_email">Trimite un email de test către</label>
						<input type="email" class="form-control" id="test_email" name="test_email" value="<?php echo esc_attr( input_post( 'test_email', $admin['email'] ) ); ?>" required>
					</div>
					<div class="col-md-6">
						<button type="submit" class="btn btn-dark"><i class="bi bi-send"></i> Trimite email de test</button>
						<span class="small text-muted ms-2">Driver activ: <strong><?php echo esc_html( $drivers[ $settings['mail_driver'] ?? 'log' ] ?? '' ); ?></strong></span>
					</div>
				</form>

				<?php if ( null !== $test_result ) : ?>
					<div class="alert <?php echo esc_attr( $test_result['success'] ? 'alert-success' : 'rm-alert-danger' ); ?> mt-3 mb-2">
						<?php echo esc_html( $test_result['success'] ? 'Emailul a fost trimis cu succes.' : 'Trimiterea a eșuat: ' . $test_result['error'] ); ?>
					</div>
					<div class="small fw-semibold mb-1">Jurnal de depanare (credențialele sunt mascate):</div>
					<pre class="rm-code"><?php echo esc_html( implode( "\n", $test_result['transcript'] ) ); ?></pre>
				<?php endif; ?>

				<?php $email_log = db_all( 'SELECT * FROM email_logs ORDER BY id DESC LIMIT 10' ); ?>
				<?php $log_statuses = get_email_log_statuses(); ?>
				<?php if ( ! empty( $email_log ) ) : ?>
					<h3 class="h6 mt-4">Ultimele emailuri <a class="small fw-normal ms-2" href="<?php echo esc_url( app_url( 'admin/emails.php', [ 'tab' => 'logs' ] ) ); ?>">Jurnalul complet <i class="bi bi-arrow-right"></i></a></h3>
					<div class="table-responsive">
						<table class="table rm-table">
							<thead><tr><th>Data</th><th>Destinatar</th><th>Subiect</th><th>Status</th></tr></thead>
							<tbody>
								<?php foreach ( $email_log as $log_row ) : ?>
									<tr>
										<td class="small text-nowrap"><?php echo esc_html( format_datetime( $log_row['created_at'] ) ); ?></td>
										<td class="small"><?php echo esc_html( $log_row['recipient_email'] ); ?></td>
										<td class="small rm-truncate"><?php echo esc_html( $log_row['subject'] ); ?></td>
										<td><span class="rm-pill <?php echo esc_attr( $log_statuses[ $log_row['status'] ][1] ?? 'rm-pill-muted' ); ?>" title="<?php echo esc_attr( (string) $log_row['error_message'] ); ?>"><?php echo esc_html( $log_statuses[ $log_row['status'] ][0] ?? $log_row['status'] ); ?></span></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>

<?php
require REPUVIO_ROOT . '/templates/app-footer.php';
