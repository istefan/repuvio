<?php
/**
 * Login page (rate limited, enumeration-safe).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

send_no_cache_headers();

$user = current_user();

if ( $user ) {
	redirect( get_home_url( $user ) );
}

$error = '';
$email = '';

if ( is_post() ) {
	$email  = sanitize_email_address( input_post( 'email' ) );
	$result = attempt_login( input_post( 'email' ), input_post( 'password' ) );

	if ( $result['success'] ) {
		$user   = current_user();
		$target = (string) ( $_SESSION['login_redirect'] ?? '' );
		unset( $_SESSION['login_redirect'] );

		if ( '' !== $target && is_internal_url( $target ) && ( 'super_admin' === $user['role'] ) === ( false !== strpos( $target, '/admin/' ) ) ) {
			redirect( $target );
		}

		redirect( get_home_url( $user ) );
	}

	$error = $result['error'];
}

$page_title    = 'Autentificare';
$auth_subtitle = 'Intră în contul tău pentru a-ți gestiona reputația online.';

require REPUVIO_ROOT . '/templates/auth-header.php';
?>
<?php if ( '' !== $error ) : ?>
	<div class="alert alert-danger" role="alert"><?php echo esc_html( $error ); ?></div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( app_url( 'auth/login.php' ) ); ?>" novalidate>
	<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<div class="mb-3">
		<label class="form-label" for="email">Email</label>
		<input type="email" class="form-control" id="email" name="email" value="<?php echo esc_attr( $email ); ?>" autocomplete="username" required autofocus>
	</div>
	<div class="mb-2">
		<div class="d-flex justify-content-between">
			<label class="form-label" for="password">Parolă</label>
			<a class="small" href="<?php echo esc_url( app_url( 'auth/forgot-password.php' ) ); ?>">Ai uitat parola?</a>
		</div>
		<input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
	</div>
	<button type="submit" class="btn btn-dark w-100 mt-3">Autentificare</button>
</form>

<p class="text-center small text-muted mt-4 mb-0">
	Nu ai cont? <a href="<?php echo esc_url( app_url( 'auth/register.php' ) ); ?>">Începe perioada de probă gratuită</a>
</p>
<?php
require REPUVIO_ROOT . '/templates/auth-footer.php';
