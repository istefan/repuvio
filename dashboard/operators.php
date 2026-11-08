<?php
/**
 * Team management ("Operatori"): the business owner creates operator accounts (customer support),
 * assigns them to companies, suspends, resets passwords or deletes them.
 *
 * Operators can only see the dashboard metrics and the "Feedback & Recenzii" CRM of their
 * assigned companies (ticket status + internal notes).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$user        = require_owner();
$companies   = get_user_companies( (int) $user['id'] );
$errors      = [];
$edit_id     = sanitize_int_range( is_post() ? input_post( 'operator_id', '0' ) : input_get( 'edit', '0' ), 0, PHP_INT_MAX, 0 );
$editing     = 0 < $edit_id ? get_owner_operator( $edit_id, (int) $user['id'] ) : null;
$form        = [
	'name'        => '',
	'email'       => '',
	'status'      => 'active',
	'company_ids' => array_map( 'intval', array_column( $companies, 'id' ) ),
];

if ( 0 < $edit_id && ! $editing ) {
	render_error_page( 404, 'Operator inexistent', 'Operatorul nu există sau nu aparține contului tău.' );
}

if ( $editing ) {
	$form = [
		'name'        => $editing['name'],
		'email'       => $editing['email'],
		'status'      => $editing['status'],
		'company_ids' => get_operator_company_ids( (int) $editing['id'], true ),
	];
}

if ( is_post() ) {
	$action = input_post( 'action' );

	if ( 'save' === $action ) {
		$form['name']        = sanitize_text( input_post( 'name' ), 150 );
		$form['email']       = sanitize_email_address( input_post( 'email' ) );
		$form['status']      = 'suspended' === input_post( 'status' ) ? 'suspended' : 'active';
		$form['company_ids'] = array_map( 'intval', (array) ( $_POST['company_ids'] ?? [] ) );
		$password            = input_post( 'password' );

		if ( 2 > mb_strlen( $form['name'] ) ) {
			$errors[] = 'Introdu numele operatorului.';
		}

		if ( '' === $form['email'] ) {
			$errors[] = 'Adresa de email nu este validă.';
		} elseif ( 0 < (int) db_value( 'SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?', [ $form['email'], $editing ? (int) $editing['id'] : 0 ] ) ) {
			$errors[] = 'Această adresă de email nu poate fi folosită. Alege alta.';
		}

		if ( ! $editing || '' !== $password ) {
			$strength = validate_password_strength( $password, input_post( 'password_confirm' ) );

			if ( '' !== $strength ) {
				$errors[] = $strength;
			}
		}

		$owned_ids = array_map( 'intval', array_column( $companies, 'id' ) );

		if ( empty( array_intersect( $owned_ids, $form['company_ids'] ) ) ) {
			$errors[] = 'Alege cel puțin o companie pentru operator.';
		}

		if ( empty( $errors ) ) {
			if ( $editing ) {
				db_query(
					"UPDATE users SET name = ?, email = ?, status = ? WHERE id = ? AND role = 'operator' AND parent_id = ?",
					[ $form['name'], $form['email'], $form['status'], (int) $editing['id'], (int) $user['id'] ]
				);

				if ( '' !== $password ) {
					db_query( 'UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [ hash_user_password( $password ), (int) $editing['id'] ] );
				}

				// Password change or suspension logs the operator out everywhere.
				if ( '' !== $password || $form['status'] !== $editing['status'] ) {
					bump_session_version( (int) $editing['id'] );
				}

				$operator_id = (int) $editing['id'];
				audit_log( 'operator_updated', 'status=' . $form['status'], $operator_id );
				flash( 'success', 'Operatorul ' . $form['name'] . ' a fost actualizat.' );
			} else {
				db_query(
					"INSERT INTO users (role, parent_id, name, email, password_hash, status, must_change_password, subscription_status, quota_cycle_start)
					VALUES ('operator', ?, ?, ?, ?, ?, 1, 'active', NOW())",
					[ (int) $user['id'], $form['name'], $form['email'], hash_user_password( $password ), $form['status'] ]
				);

				$operator_id = db_insert_id();
				audit_log( 'operator_created', 'owner=' . (int) $user['id'], $operator_id );

				// Invitation with the initial password (template "operator_invite", can be switched off in Email & Șabloane).
				$assigned = array_column(
					array_filter(
						$companies,
						function ( $company ) use ( $form ) {
							return in_array( (int) $company['id'], $form['company_ids'], true );
						}
					),
					'name'
				);
				$invite   = send_operator_invite_email(
					[
						'id'    => $operator_id,
						'name'  => $form['name'],
						'email' => $form['email'],
					],
					$user,
					$assigned,
					$password
				);

				flash(
					'success',
					$invite['success'] && empty( $invite['queued'] )
						? 'Contul de operator pentru ' . $form['name'] . ' a fost creat și i-am trimis invitația pe email, cu parola inițială. La prima autentificare va fi obligat să o schimbe.'
						: 'Contul de operator pentru ' . $form['name'] . ' a fost creat. Trimite-i emailul și parola inițială; la prima autentificare va fi obligat să o schimbe.'
				);
			}

			save_operator_companies( $operator_id, (int) $user['id'], $form['company_ids'] );
			redirect( app_url( 'dashboard/operators.php' ) );
		}
	} elseif ( 'delete' === $action && $editing ) {
		db_query( "DELETE FROM users WHERE id = ? AND role = 'operator' AND parent_id = ?", [ (int) $editing['id'], (int) $user['id'] ] );
		audit_log( 'operator_deleted', 'email=' . $editing['email'], (int) $editing['id'] );
		flash( 'success', 'Operatorul ' . $editing['name'] . ' a fost șters.' );
		redirect( app_url( 'dashboard/operators.php' ) );
	}
}

$operators = get_owner_operators( (int) $user['id'] );

$page_title       = 'Operatori';
$active_nav       = 'operators';
$page_breadcrumbs = [ [ 'Acasă', app_url( 'dashboard/index.php' ) ], [ 'Operatori', '' ] ];

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<?php if ( ! empty( $errors ) ) : ?>
	<div class="alert alert-danger">
		<?php foreach ( $errors as $error ) : ?>
			<div><?php echo esc_html( $error ); ?></div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<div class="row g-3">
	<div class="col-xl-7">
		<div class="rm-card">
			<div class="rm-card-header">
				<h2 class="rm-card-title">Echipa de suport (<?php echo esc_html( count( $operators ) ); ?>)</h2>
				<?php if ( $editing ) : ?>
					<a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'dashboard/operators.php' ) ); ?>"><i class="bi bi-plus-lg"></i> Operator nou</a>
				<?php endif; ?>
			</div>
			<?php if ( empty( $operators ) ) : ?>
				<div class="rm-empty">
					<i class="bi bi-headset"></i>
					<h3 class="h6 mt-3">Nu ai încă operatori</h3>
					<p class="mb-0">Adaugă colegii care răspund la reclamații. Ei vor vedea doar panoul și feedback-ul companiilor alocate.</p>
				</div>
			<?php else : ?>
				<div class="table-responsive">
					<table class="table rm-table">
						<thead><tr><th>Operator</th><th>Companii</th><th>Stare</th><th>Ultima autentificare</th><th></th></tr></thead>
						<tbody>
							<?php foreach ( $operators as $operator ) : ?>
								<tr>
									<td>
										<div class="fw-medium text-dark"><?php echo esc_html( $operator['name'] ); ?></div>
										<div class="small text-muted"><?php echo esc_html( $operator['email'] ); ?></div>
									</td>
									<td class="small">
										<?php if ( empty( $operator['companies'] ) ) : ?>
											<span class="text-danger">Nicio companie</span>
										<?php else : ?>
											<?php echo esc_html( implode( ', ', array_column( $operator['companies'], 'name' ) ) ); ?>
										<?php endif; ?>
									</td>
									<td>
										<span class="rm-pill <?php echo esc_attr( 'active' === $operator['status'] ? 'rm-pill-positive' : 'rm-pill-muted' ); ?>"><?php echo esc_html( 'active' === $operator['status'] ? 'Activ' : 'Suspendat' ); ?></span>
										<?php if ( 1 === (int) $operator['must_change_password'] ) : ?>
											<div class="small text-muted mt-1">Parolă inițială</div>
										<?php endif; ?>
									</td>
									<td class="small text-muted text-nowrap"><?php echo esc_html( format_datetime( $operator['last_login_at'] ) ); ?></td>
									<td class="text-end"><a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'dashboard/operators.php', [ 'edit' => (int) $operator['id'] ] ) ); ?>"><i class="bi bi-pencil"></i> Editează</a></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>

		<div class="rm-card rm-card-body mt-3 small">
			<div class="fw-semibold text-dark mb-2"><i class="bi bi-shield-lock"></i> Ce poate face un operator</div>
			<div class="row g-2">
				<div class="col-md-6">
					<div class="text-success mb-1"><i class="bi bi-check2"></i> Vede Panoul (metrici) pentru companiile alocate</div>
					<div class="text-success mb-1"><i class="bi bi-check2"></i> Gestionează „Feedback & Recenzii”</div>
					<div class="text-success"><i class="bi bi-check2"></i> Schimbă statusul (Nou / În lucru / Rezolvat) și adaugă note interne</div>
				</div>
				<div class="col-md-6">
					<div class="text-danger mb-1"><i class="bi bi-x"></i> Nu adaugă / șterge companii și nu schimbă pragul de stele</div>
					<div class="text-danger mb-1"><i class="bi bi-x"></i> Nu vede abonamentul și nu face plăți</div>
					<div class="text-danger"><i class="bi bi-x"></i> Nu gestionează alți operatori</div>
				</div>
			</div>
		</div>
	</div>

	<div class="col-xl-5">
		<?php if ( empty( $companies ) ) : ?>
			<div class="rm-card rm-card-body">
				<p class="mb-2">Adaugă mai întâi o companie, apoi alocă-i operatori.</p>
				<a class="btn btn-dark btn-sm" href="<?php echo esc_url( app_url( 'dashboard/company-edit.php' ) ); ?>"><i class="bi bi-plus-lg"></i> Adaugă companie</a>
			</div>
		<?php else : ?>
			<form method="post" class="rm-card" novalidate autocomplete="off">
				<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<input type="hidden" name="action" value="save">
				<input type="hidden" name="operator_id" value="<?php echo esc_attr( $editing ? $editing['id'] : 0 ); ?>">
				<div class="rm-card-header"><h2 class="rm-card-title"><?php echo esc_html( $editing ? 'Editează operatorul' : 'Adaugă operator' ); ?></h2></div>
				<div class="rm-card-body">
					<div class="mb-3">
						<label class="form-label" for="op-name">Nume</label>
						<input type="text" class="form-control" id="op-name" name="name" value="<?php echo esc_attr( $form['name'] ); ?>" maxlength="150" required>
					</div>
					<div class="mb-3">
						<label class="form-label" for="op-email">Email de autentificare</label>
						<input type="email" class="form-control" id="op-email" name="email" value="<?php echo esc_attr( $form['email'] ); ?>" required autocomplete="off">
					</div>
					<div class="row g-2 mb-1">
						<div class="col-sm-6">
							<label class="form-label" for="op-password"><?php echo esc_html( $editing ? 'Parolă nouă (opțional)' : 'Parolă inițială' ); ?></label>
							<input type="password" class="form-control" id="op-password" name="password" minlength="10" autocomplete="new-password">
						</div>
						<div class="col-sm-6">
							<label class="form-label" for="op-password-confirm">Confirmă parola</label>
							<input type="password" class="form-control" id="op-password-confirm" name="password_confirm" minlength="10" autocomplete="new-password">
						</div>
					</div>
					<div class="form-text mb-3">Minim 10 caractere, cu cel puțin o cifră sau un simbol. Operatorul își schimbă parola la prima autentificare.</div>

					<label class="form-label">Companii alocate</label>
					<div class="border rounded-3 p-2 mb-3">
						<?php foreach ( $companies as $company ) : ?>
							<div class="form-check">
								<input class="form-check-input" type="checkbox" name="company_ids[]" value="<?php echo esc_attr( $company['id'] ); ?>" id="op-company-<?php echo esc_attr( $company['id'] ); ?>"<?php echo in_array( (int) $company['id'], $form['company_ids'], true ) ? ' checked' : ''; ?>>
								<label class="form-check-label" for="op-company-<?php echo esc_attr( $company['id'] ); ?>"><?php echo esc_html( $company['name'] ); ?></label>
							</div>
						<?php endforeach; ?>
					</div>

					<label class="form-label" for="op-status">Stare cont</label>
					<select class="form-select" id="op-status" name="status">
						<option value="active"<?php echo 'active' === $form['status'] ? ' selected' : ''; ?>>Activ</option>
						<option value="suspended"<?php echo 'suspended' === $form['status'] ? ' selected' : ''; ?>>Suspendat</option>
					</select>
				</div>
				<div class="rm-card-body border-top d-flex gap-2">
					<button type="submit" class="btn btn-dark"><i class="bi bi-check2"></i> <?php echo esc_html( $editing ? 'Salvează' : 'Creează operatorul' ); ?></button>
					<?php if ( $editing ) : ?>
						<a class="btn btn-light" href="<?php echo esc_url( app_url( 'dashboard/operators.php' ) ); ?>">Renunță</a>
					<?php endif; ?>
				</div>
			</form>

			<?php if ( $editing ) : ?>
				<form method="post" class="rm-card rm-card-body mt-3" data-confirm="Ștergi definitiv contul operatorului? Notele lui rămân în tichete ca „Utilizator șters”.">
					<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<input type="hidden" name="action" value="delete">
					<input type="hidden" name="operator_id" value="<?php echo esc_attr( $editing['id'] ); ?>">
					<div class="d-flex justify-content-between align-items-center gap-2">
						<span class="small text-muted">Șterge contul de operator</span>
						<button type="submit" class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i> Șterge</button>
					</div>
				</form>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>

<?php
require REPUVIO_ROOT . '/templates/app-footer.php';
