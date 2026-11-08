<?php
/**
 * Password reset via single-use hashed token.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

send_no_cache_headers();
header( 'Referrer-Policy: no-referrer' );

$token = is_post() ? input_post( 'token' ) : input_get( 'token' );
$token = preg_match( '/^[a-f0-9]{64}$/', $token ) ? $token : '';
$reset = null;
$error = '';

if ( '' !== $token ) {
	$reset = db_row(
		'SELECT pr.*, u.email FROM password_resets pr INNER JOIN users u ON u.id = pr.user_id
		WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW() AND u.status = \'active\'',
		[ hash( 'sha256', $token ) ]
	);
}

if ( $reset && is_post() ) {
	$password = input_post( 'password' );
	$error    = validate_password_strength( $password, input_post( 'password_confirm' ) );

	if ( '' === $error ) {
		db_transaction(
			function () use ( $reset, $password ) {
				db_query( 'UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [ hash_user_password( $password ), (int) $reset['user_id'] ] );
				db_query( 'UPDATE password_resets SET used_at = NOW() WHERE id = ?', [ (int) $reset['id'] ] );
				db_query( 'DELETE FROM password_resets WHERE user_id = ? AND id <> ?', [ (int) $reset['user_id'], (int) $reset['id'] ] );
			}
		);

		// Log out every existing session of this user.
		bump_session_version( (int) $reset['user_id'] );
		rate_limit_clear( 'login' );
		audit_log( 'password_reset', '', (int) $reset['user_id'], (int) $reset['user_id'] );

		flash( 'success', 'Parola a fost schimbată. Te poți autentifica acum.' );
		redirect( app_url( 'auth/login.php' ) );
	}
}

$page_title    = 'Setează o parolă nouă';
$auth_subtitle = $reset ? 'Pentru contul ' . $reset['email'] : '';

require REPUVIO_ROOT . '/templates/auth-header.php';
?>
<?php if ( ! $reset ) : ?>
	<div class="alert alert-warning" role="alert">Linkul de resetare este invalid sau a expirat. Solicită unul nou.</div>
	<a class="btn btn-dark w-100" href="<?php echo esc_url( app_url( 'auth/forgot-password.php' ) ); ?>">Solicită un link nou</a>
<?php else : ?>
	<?php if ( '' !== $error ) : ?>
		<div class="alert alert-danger" role="alert"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( app_url( 'auth/reset-password.php' ) ); ?>" novalidate>
		<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
		<div class="mb-3">
			<label class="form-label" for="password">Parolă nouă</label>
			<input type="password" class="form-control" id="password" name="password" minlength="10" autocomplete="new-password" required autofocus>
		</div>
		<div class="mb-3">
			<label class="form-label" for="password_confirm">Confirmă parola</label>
			<input type="password" class="form-control" id="password_confirm" name="password_confirm" minlength="10" autocomplete="new-password" required>
			<div class="form-text">Minim 10 caractere, litere și cel puțin o cifră sau un simbol.</div>
		</div>
		<button type="submit" class="btn btn-dark w-100">Salvează parola</button>
	</form>
<?php endif; ?>
<?php
require REPUVIO_ROOT . '/templates/auth-footer.php';
