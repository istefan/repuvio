<?php
/**
 * Password recovery request (rate limited, identical response for every email).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

send_no_cache_headers();

$sent  = false;
$error = '';

if ( is_post() ) {
	if ( rate_limit_is_locked( 'password_reset' ) ) {
		$error = 'Prea multe cereri de pe această adresă IP. Încearcă din nou peste o oră.';
	} else {
		rate_limit_hit( 'password_reset', 5, 3600, 3600 );

		$email = sanitize_email_address( input_post( 'email' ) );
		$user  = '' !== $email ? db_row( "SELECT * FROM users WHERE email = ? AND status = 'active'", [ $email ] ) : null;

		if ( $user && ! is_showcase_user( $user ) ) {
			// Invalidate previous tokens, then issue a new single-use token (only its hash is stored).
			db_query( 'DELETE FROM password_resets WHERE user_id = ?', [ (int) $user['id'] ] );

			$token = bin2hex( random_bytes( 32 ) );
			db_query(
				'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)',
				[ (int) $user['id'], hash( 'sha256', $token ), now_db( '+60 minutes' ) ]
			);

			send_password_reset_email( $user, $token );
			audit_log( 'password_reset_requested', '', (int) $user['id'], 0 );
		} else {
			// Equalize timing with the branch that sends an email.
			usleep( random_int( 150000, 400000 ) );
		}

		$sent = true;
	}
}

$page_title    = 'Resetare parolă';
$auth_subtitle = 'Îți trimitem un link securizat pentru a seta o parolă nouă.';

require REPUVIO_ROOT . '/templates/auth-header.php';
?>
<?php if ( $sent ) : ?>
	<div class="alert alert-success" role="alert">
		Dacă adresa există în sistem, vei primi în câteva minute un email cu instrucțiuni. Verifică și folderul Spam.
	</div>
<?php else : ?>
	<?php if ( '' !== $error ) : ?>
		<div class="alert alert-danger" role="alert"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( app_url( 'auth/forgot-password.php' ) ); ?>" novalidate>
		<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<div class="mb-3">
			<label class="form-label" for="email">Email</label>
			<input type="email" class="form-control" id="email" name="email" autocomplete="email" required autofocus>
		</div>
		<button type="submit" class="btn btn-dark w-100">Trimite linkul de resetare</button>
	</form>
<?php endif; ?>

<p class="text-center small text-muted mt-4 mb-0">
	<a href="<?php echo esc_url( app_url( 'auth/login.php' ) ); ?>"><i class="bi bi-arrow-left"></i> Înapoi la autentificare</a>
</p>
<?php
require REPUVIO_ROOT . '/templates/auth-footer.php';
