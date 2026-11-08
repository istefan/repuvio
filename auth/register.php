<?php
/**
 * Registration with automatic free trial (rate limited, honeypot protected).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

send_no_cache_headers();

if ( current_user() ) {
	redirect( get_home_url( current_user() ) );
}

$errors = [];
$name   = '';
$email  = '';

if ( is_post() ) {
	$name     = sanitize_text( input_post( 'name' ), 150 );
	$email    = sanitize_email_address( input_post( 'email' ) );
	$password = input_post( 'password' );
	$confirm  = input_post( 'password_confirm' );

	if ( rate_limit_is_locked( 'register' ) ) {
		$errors[] = 'Prea multe înregistrări de pe această adresă IP. Încearcă din nou mai târziu.';
	} elseif ( '' !== input_post( 'website' ) ) {
		// Honeypot triggered: pretend success without creating anything.
		rate_limit_hit( 'register', 5, 3600, 3600 );
		flash( 'success', 'Contul a fost creat. Te poți autentifica.' );
		redirect( app_url( 'auth/login.php' ) );
	} else {
		if ( 2 > mb_strlen( $name ) ) {
			$errors[] = 'Introdu numele tău.';
		}
		if ( '' === $email ) {
			$errors[] = 'Introdu o adresă de email validă.';
		}

		$password_error = validate_password_strength( $password, $confirm );
		if ( '' !== $password_error ) {
			$errors[] = $password_error;
		}

		if ( '1' !== input_post( 'terms' ) ) {
			$errors[] = 'Trebuie să fii de acord cu Termenii și Condițiile și să confirmi că ai citit Politica de Confidențialitate.';
		}

		if ( empty( $errors ) ) {
			rate_limit_hit( 'register', 5, 3600, 3600 );

			$exists = (int) db_value( 'SELECT COUNT(*) FROM users WHERE email = ?', [ $email ] );

			if ( 0 < $exists ) {
				$errors[] = 'Nu am putut crea contul cu aceste date. Dacă ai deja cont, autentifică-te sau resetează parola.';
			} else {
				db_query(
					"INSERT INTO users (role, name, email, password_hash, status, subscription_status, terms_accepted_at) VALUES ('user', ?, ?, ?, 'active', 'trialing', NOW())",
					[ $name, $email, hash_user_password( $password ) ]
				);

				$user_id = db_insert_id();
				start_free_trial( $user_id );

				$user = get_user_by_id( $user_id );
				login_user( $user );
				audit_log( 'register', '', $user_id, $user_id );
				send_welcome_email( $user );

				flash( 'success', 'Bine ai venit! Perioada ta de probă gratuită a început. Configurăm împreună prima companie în 3 pași.' );
				redirect( app_url( 'dashboard/onboarding.php' ) );
			}
		}
	}
}

$page_title    = 'Creează cont';
$auth_subtitle = get_setting_int( 'trial_days', 30 ) . ' zile gratuit. Fără card bancar.';

require REPUVIO_ROOT . '/templates/auth-header.php';
?>
<?php if ( ! empty( $errors ) ) : ?>
	<div class="alert alert-danger" role="alert">
		<?php foreach ( $errors as $error ) : ?>
			<div><?php echo esc_html( $error ); ?></div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( app_url( 'auth/register.php' ) ); ?>" novalidate>
	<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<div class="rm-hp" aria-hidden="true">
		<label for="website">Website</label>
		<input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
	</div>
	<div class="mb-3">
		<label class="form-label" for="name">Nume complet</label>
		<input type="text" class="form-control" id="name" name="name" value="<?php echo esc_attr( $name ); ?>" maxlength="150" autocomplete="name" required autofocus>
	</div>
	<div class="mb-3">
		<label class="form-label" for="email">Email</label>
		<input type="email" class="form-control" id="email" name="email" value="<?php echo esc_attr( $email ); ?>" autocomplete="email" required>
	</div>
	<div class="row g-3">
		<div class="col-sm-6">
			<label class="form-label" for="password">Parolă</label>
			<input type="password" class="form-control" id="password" name="password" minlength="10" autocomplete="new-password" required>
		</div>
		<div class="col-sm-6">
			<label class="form-label" for="password_confirm">Confirmă parola</label>
			<input type="password" class="form-control" id="password_confirm" name="password_confirm" minlength="10" autocomplete="new-password" required>
		</div>
	</div>
	<div class="form-text mb-3">Minim 10 caractere, litere și cel puțin o cifră sau un simbol.</div>
	<div class="form-check mb-3">
		<input class="form-check-input" type="checkbox" value="1" id="terms" name="terms" required>
		<label class="form-check-label small" for="terms">Sunt de acord cu <a href="<?php echo esc_url( legal_url( 'terms' ) ); ?>" target="_blank" rel="noopener">Termenii și Condițiile</a> și am citit <a href="<?php echo esc_url( legal_url( 'privacy' ) ); ?>" target="_blank" rel="noopener">Politica de Confidențialitate GDPR</a> a platformei <?php echo esc_html( app_name() ); ?>.</label>
	</div>
	<button type="submit" class="btn btn-dark w-100">Creează contul gratuit</button>
</form>

<p class="text-center small text-muted mt-4 mb-0">
	Ai deja cont? <a href="<?php echo esc_url( app_url( 'auth/login.php' ) ); ?>">Autentifică-te</a>
</p>
<?php
require REPUVIO_ROOT . '/templates/auth-footer.php';
