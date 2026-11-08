<?php
/**
 * Account profile: name, email and password (current password required for sensitive changes).
 * Available to every role. Business owners also opt in / out of the monthly ROI report email.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$user   = require_login();
$errors = [];

if ( is_post() ) {
	$action = input_post( 'action' );

	// Showcase accounts are shared by the sales team: their login data must stay unchanged.
	if ( is_showcase_user( $user ) && in_array( $action, [ 'profile', 'password' ], true ) ) {
		flash( 'warning', 'Cont demo: numele, emailul și parola nu pot fi schimbate. Datele contului se resetează automat în fiecare zi.' );
		redirect( app_url( 'dashboard/profile.php' ) );
	}

	if ( 'profile' === $action ) {
		$name  = sanitize_text( input_post( 'name' ), 150 );
		$email = sanitize_email_address( input_post( 'email' ) );

		if ( 2 > mb_strlen( $name ) ) {
			$errors[] = 'Introdu numele.';
		}
		if ( '' === $email ) {
			$errors[] = 'Adresa de email nu este validă.';
		}

		if ( empty( $errors ) && $email !== $user['email'] ) {
			if ( is_impersonating() ) {
				$errors[] = 'Emailul nu poate fi schimbat în modul impersonare.';
			} elseif ( ! password_verify( input_post( 'current_password' ), $user['password_hash'] ) ) {
				$errors[] = 'Pentru a schimba emailul introdu parola curentă.';
			} elseif ( 0 < (int) db_value( 'SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?', [ $email, (int) $user['id'] ] ) ) {
				$errors[] = 'Această adresă de email nu poate fi folosită.';
			}
		}

		if ( empty( $errors ) ) {
			db_query( 'UPDATE users SET name = ?, email = ? WHERE id = ?', [ $name, $email, (int) $user['id'] ] );

			if ( $email !== $user['email'] ) {
				audit_log( 'email_changed', 'from=' . $user['email'] . ' to=' . $email, (int) $user['id'] );
			}

			flash( 'success', 'Profilul a fost actualizat.' );
			redirect( app_url( 'dashboard/profile.php' ) );
		}
	} elseif ( 'password' === $action ) {
		$current  = input_post( 'current_password' );
		$password = input_post( 'password' );

		if ( is_impersonating() ) {
			$errors[] = 'Parola nu poate fi schimbată în modul impersonare.';
		} elseif ( ! password_verify( $current, $user['password_hash'] ) ) {
			$errors[] = 'Parola curentă este incorectă.';
		} else {
			$strength = validate_password_strength( $password, input_post( 'password_confirm' ) );

			if ( '' !== $strength ) {
				$errors[] = $strength;
			} elseif ( hash_equals( $current, $password ) ) {
				$errors[] = 'Parola nouă trebuie să fie diferită de cea curentă.';
			}
		}

		if ( empty( $errors ) ) {
			db_query( 'UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [ hash_user_password( $password ), (int) $user['id'] ] );

			// Invalidate every other session, keep this one alive with a fresh ID.
			bump_session_version( (int) $user['id'] );
			$_SESSION['session_version'] = (int) db_value( 'SELECT session_version FROM users WHERE id = ?', [ (int) $user['id'] ] );
			regenerate_session();

			audit_log( 'password_changed', '', (int) $user['id'] );
			flash( 'success', 'Parola a fost schimbată. Celelalte sesiuni active au fost deconectate.' );
			redirect( app_url( 'dashboard/profile.php' ) );
		}
	}
}

if ( is_post() && 'reports' === input_post( 'action' ) && 'user' === $user['role'] ) {
	db_query( 'UPDATE users SET roi_report_enabled = ? WHERE id = ?', [ '1' === input_post( 'roi_report_enabled' ) ? 1 : 0, (int) $user['id'] ] );
	flash( 'success', 'Preferința pentru raportul lunar a fost salvată.' );
	redirect( app_url( 'dashboard/profile.php' ) );
}

$page_title       = 'Profil';
$active_nav       = 'profile';
$page_breadcrumbs = [ [ 'Acasă', get_home_url( $user ) ], [ 'Profil', '' ] ];

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<?php if ( ! empty( $errors ) ) : ?>
	<div class="alert alert-danger">
		<?php foreach ( $errors as $error ) : ?>
			<div><?php echo esc_html( $error ); ?></div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<?php if ( 1 === (int) $user['must_change_password'] ) : ?>
	<div class="alert alert-warning"><i class="bi bi-shield-exclamation"></i> Contul folosește o parolă implicită. Setează o parolă nouă pentru a continua.</div>
<?php endif; ?>

<div class="row g-3">
	<div class="col-lg-6">
		<div class="rm-card">
			<div class="rm-card-header"><h2 class="rm-card-title">Date cont</h2></div>
			<div class="rm-card-body">
				<form method="post" novalidate>
					<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<input type="hidden" name="action" value="profile">
					<div class="mb-3">
						<label class="form-label" for="name">Nume</label>
						<input type="text" class="form-control" id="name" name="name" value="<?php echo esc_attr( $user['name'] ); ?>" maxlength="150" required>
					</div>
					<div class="mb-3">
						<label class="form-label" for="email">Email</label>
						<input type="email" class="form-control" id="email" name="email" value="<?php echo esc_attr( $user['email'] ); ?>" required>
					</div>
					<div class="mb-3">
						<label class="form-label" for="profile_current_password">Parola curentă <span class="text-muted fw-normal">(necesară doar la schimbarea emailului)</span></label>
						<input type="password" class="form-control" id="profile_current_password" name="current_password" autocomplete="current-password">
					</div>
					<button type="submit" class="btn btn-dark">Salvează</button>
				</form>
			</div>
		</div>
	</div>
	<div class="col-lg-6">
		<div class="rm-card">
			<div class="rm-card-header"><h2 class="rm-card-title">Schimbă parola</h2></div>
			<div class="rm-card-body">
				<form method="post" novalidate>
					<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<input type="hidden" name="action" value="password">
					<div class="mb-3">
						<label class="form-label" for="current_password">Parola curentă</label>
						<input type="password" class="form-control" id="current_password" name="current_password" autocomplete="current-password" required>
					</div>
					<div class="mb-3">
						<label class="form-label" for="password">Parolă nouă</label>
						<input type="password" class="form-control" id="password" name="password" minlength="10" autocomplete="new-password" required>
					</div>
					<div class="mb-3">
						<label class="form-label" for="password_confirm">Confirmă parola nouă</label>
						<input type="password" class="form-control" id="password_confirm" name="password_confirm" minlength="10" autocomplete="new-password" required>
						<div class="form-text">Minim 10 caractere, litere și cel puțin o cifră sau un simbol.</div>
					</div>
					<button type="submit" class="btn btn-dark">Schimbă parola</button>
				</form>
				<div class="small text-muted mt-3">Ultima autentificare: <?php echo esc_html( format_datetime( $user['last_login_at'] ) ); ?><?php echo $user['last_login_ip'] ? esc_html( ' · IP ' . $user['last_login_ip'] ) : ''; ?></div>
			</div>
		</div>
	</div>

	<?php if ( 'user' === $user['role'] ) : ?>
		<div class="col-lg-6">
			<form method="post" class="rm-card">
				<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<input type="hidden" name="action" value="reports">
				<div class="rm-card-header"><h2 class="rm-card-title">Raport lunar „Valoare generată”</h2></div>
				<div class="rm-card-body">
					<div class="form-check form-switch mb-2">
						<input class="form-check-input" type="checkbox" role="switch" id="roi_report_enabled" name="roi_report_enabled" value="1"<?php echo 1 === (int) $user['roi_report_enabled'] ? ' checked' : ''; ?>>
						<label class="form-check-label" for="roi_report_enabled">Primesc pe email raportul ROI la începutul fiecărei luni</label>
					</div>
					<p class="small text-muted mb-3">Scanări QR, clienți trimiși pe Google, feedback negativ interceptat și topul angajaților / surselor. Inclus în toate abonamentele.</p>
					<button type="submit" class="btn btn-dark btn-sm">Salvează</button>
				</div>
			</form>
		</div>
	<?php endif; ?>
</div>

<?php
require REPUVIO_ROOT . '/templates/app-footer.php';
