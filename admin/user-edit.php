<?php
/**
 * Super Admin: create / edit user, subscription, billing cycle and per-user custom limits (Enterprise),
 * quota management, payments, delete, impersonate. Operators are listed here but created by their owner.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$admin    = require_super_admin();
$user_id  = sanitize_int_range( is_post() ? input_post( 'user_id', '0' ) : input_get( 'id', '0' ), 0, PHP_INT_MAX, 0 );
$target   = 0 < $user_id ? get_user_by_id( $user_id ) : null;
$is_new   = null === $target;
$is_self  = ! $is_new && (int) $target['id'] === (int) $admin['id'];
$plans    = get_plans();
$statuses = get_subscription_statuses();
$errors   = [];

if ( 0 < $user_id && $is_new ) {
	render_error_page( 404, 'Utilizator inexistent', 'Utilizatorul nu a fost găsit.' );
}

/**
 * Converts a Y-m-d input to an end-of-day datetime or null.
 *
 * @param string $value Date.
 * @return string|null
 */
function admin_date_to_db( $value ) {
	$date = sanitize_date( $value );

	return '' === $date ? null : $date . ' 23:59:59';
}

$form = $is_new ? [
	'name'                 => '',
	'email'                => '',
	'role'                 => 'user',
	'status'               => 'active',
	'plan_id'              => 0,
	'billing_cycle'        => 'monthly',
	'custom_max_reviews'   => null,
	'custom_max_companies' => null,
	'subscription_status'  => 'trialing',
	'trial_ends_at'        => now_db( '+' . get_setting_int( 'trial_days', 30 ) . ' days' ),
	'subscription_ends_at' => null,
	'must_change_password' => 1,
] : $target;

if ( is_post() ) {
	$action = input_post( 'action' );

	if ( 'save' === $action ) {
		$form['name']                 = sanitize_text( input_post( 'name' ), 150 );
		$form['email']                = sanitize_email_address( input_post( 'email' ) );
		$form['role']                 = 'super_admin' === input_post( 'role' ) ? 'super_admin' : 'user';
		$form['billing_cycle']        = 'yearly' === input_post( 'billing_cycle' ) ? 'yearly' : 'monthly';
		$form['custom_max_reviews']   = '' === trim( input_post( 'custom_max_reviews' ) ) ? null : sanitize_int_range( input_post( 'custom_max_reviews' ), 0, 10000000, 0 );
		$form['custom_max_companies'] = '' === trim( input_post( 'custom_max_companies' ) ) ? null : sanitize_int_range( input_post( 'custom_max_companies' ), 1, 10000, 1 );

		// Operators keep their role and owner; they are managed from the owner's "Operatori" page.
		if ( ! $is_new && 'operator' === $target['role'] ) {
			$form['role'] = 'operator';
		}
		$form['status']               = 'suspended' === input_post( 'status' ) ? 'suspended' : 'active';
		$form['plan_id']              = sanitize_int_range( input_post( 'plan_id', '0' ), 0, PHP_INT_MAX, 0 );
		$form['subscription_status']  = array_key_exists( input_post( 'subscription_status' ), $statuses ) ? input_post( 'subscription_status' ) : 'trialing';
		$form['trial_ends_at']        = admin_date_to_db( input_post( 'trial_ends_at' ) );
		$form['subscription_ends_at'] = admin_date_to_db( input_post( 'subscription_ends_at' ) );
		$form['must_change_password'] = '1' === input_post( 'must_change_password' ) ? 1 : 0;
		$password                     = input_post( 'password' );

		if ( 2 > mb_strlen( $form['name'] ) ) {
			$errors[] = 'Introdu numele.';
		}
		if ( '' === $form['email'] ) {
			$errors[] = 'Email invalid.';
		} elseif ( 0 < (int) db_value( 'SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?', [ $form['email'], $is_new ? 0 : (int) $target['id'] ] ) ) {
			$errors[] = 'Emailul este deja folosit de alt cont.';
		}
		if ( 0 < $form['plan_id'] && ! get_plan( $form['plan_id'] ) ) {
			$errors[] = 'Plan invalid.';
		}
		if ( $is_self && ( 'super_admin' !== $form['role'] || 'active' !== $form['status'] ) ) {
			$errors[] = 'Nu îți poți elimina propriul rol de Super Admin și nu te poți suspenda.';
		}
		if ( $is_new || '' !== $password ) {
			$strength = validate_password_strength( $password, $password );
			if ( '' !== $strength ) {
				$errors[] = $strength;
			}
		}
		if ( 'trialing' === $form['subscription_status'] && null === $form['trial_ends_at'] ) {
			$errors[] = 'Setează data de încheiere a perioadei de probă.';
		}

		if ( empty( $errors ) ) {
			$params = [
				'name'                 => $form['name'],
				'email'                => $form['email'],
				'role'                 => $form['role'],
				'status'               => $form['status'],
				'plan_id'              => 0 < $form['plan_id'] ? (int) $form['plan_id'] : null,
				'billing_cycle'        => $form['billing_cycle'],
				'custom_max_reviews'   => $form['custom_max_reviews'],
				'custom_max_companies' => $form['custom_max_companies'],
				'subscription_status'  => $form['subscription_status'],
				'trial_ends_at'        => $form['trial_ends_at'],
				'subscription_ends_at' => $form['subscription_ends_at'],
				'must_change_password' => $form['must_change_password'],
			];

			if ( $is_new ) {
				$params['password_hash'] = hash_user_password( $password );

				db_query(
					'INSERT INTO users (name, email, role, status, plan_id, billing_cycle, custom_max_reviews, custom_max_companies, subscription_status, trial_ends_at, subscription_ends_at, must_change_password, password_hash, quota_cycle_start)
					VALUES (:name, :email, :role, :status, :plan_id, :billing_cycle, :custom_max_reviews, :custom_max_companies, :subscription_status, :trial_ends_at, :subscription_ends_at, :must_change_password, :password_hash, NOW())',
					$params
				);

				$user_id = db_insert_id();
				audit_log( 'admin_user_created', 'role=' . $form['role'], $user_id );
				flash( 'success', 'Utilizatorul a fost creat.' );
			} else {
				$params['id'] = (int) $target['id'];

				db_query(
					'UPDATE users SET name = :name, email = :email, role = :role, status = :status, plan_id = :plan_id,
						billing_cycle = :billing_cycle, custom_max_reviews = :custom_max_reviews, custom_max_companies = :custom_max_companies,
						subscription_status = :subscription_status, trial_ends_at = :trial_ends_at,
						subscription_ends_at = :subscription_ends_at, must_change_password = :must_change_password
					WHERE id = :id',
					$params
				);

				if ( '' !== $password ) {
					db_query( 'UPDATE users SET password_hash = ? WHERE id = ?', [ hash_user_password( $password ), (int) $target['id'] ] );
				}

				// A new trial end date deserves a new "trial ending" reminder.
				if ( $form['trial_ends_at'] !== $target['trial_ends_at'] ) {
					db_query( 'UPDATE users SET trial_reminder_sent = 0 WHERE id = ?', [ (int) $target['id'] ] );
				}

				// Suspension, role change or password change revokes existing sessions.
				if ( '' !== $password || $form['status'] !== $target['status'] || $form['role'] !== $target['role'] ) {
					if ( ! $is_self ) {
						bump_session_version( (int) $target['id'] );
					}
				}

				audit_log(
					'admin_user_updated',
					sprintf( 'status=%s role=%s plan=%d sub=%s', $form['status'], $form['role'], (int) $form['plan_id'], $form['subscription_status'] ),
					(int) $target['id']
				);

				unlock_pending_reviews( (int) $target['id'] );
				flash( 'success', 'Modificările au fost salvate.' );
			}

			redirect( app_url( 'admin/user-edit.php', [ 'id' => $user_id ] ) );
		}
	} elseif ( ! $is_new && 'reset_quota' === $action ) {
		db_query(
			'UPDATE users SET reviews_used_this_cycle = 0, quota_alert_80_sent = 0, quota_alert_100_sent = 0, quota_cycle_start = NOW() WHERE id = ?',
			[ (int) $target['id'] ]
		);
		$unlocked = unlock_pending_reviews( (int) $target['id'] );
		audit_log( 'admin_quota_reset', 'unlocked=' . $unlocked, (int) $target['id'] );
		flash( 'success', 'Contorul lunar a fost resetat.' . ( 0 < $unlocked ? ' ' . $unlocked . ' feedback-uri au fost deblocate.' : '' ) );
		redirect( app_url( 'admin/user-edit.php', [ 'id' => (int) $target['id'] ] ) );
	} elseif ( ! $is_new && 'log_payment' === $action ) {
		$plan_choice  = explode( ':', input_post( 'payment_plan_id', '0:monthly' ) );
		$plan_id      = sanitize_int_range( $plan_choice[0] ?? '0', 0, PHP_INT_MAX, 0 );
		$plan_cycle   = 'yearly' === ( $plan_choice[1] ?? '' ) ? 'yearly' : 'monthly';
		$plan         = get_plan( $plan_id );
		$amount       = (float) str_replace( ',', '.', input_post( 'amount_ron', '0' ) );
		$period_start = sanitize_date( input_post( 'period_start' ) );
		$period_end   = sanitize_date( input_post( 'period_end' ) );
		$method       = in_array( input_post( 'method' ), [ 'bank_transfer', 'card', 'cash', 'other' ], true ) ? input_post( 'method' ) : 'bank_transfer';

		if ( 0 >= $amount || 1000000 < $amount ) {
			flash( 'danger', 'Suma nu este validă.' );
		} elseif ( '' === $period_start || '' === $period_end || $period_end < $period_start ) {
			flash( 'danger', 'Perioada nu este validă.' );
		} else {
			db_query(
				'INSERT INTO payments (user_id, plan_id, amount_ron, period_start, period_end, method, reference, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
				[
					(int) $target['id'],
					$plan ? (int) $plan['id'] : null,
					number_format( $amount, 2, '.', '' ),
					$period_start,
					$period_end,
					$method,
					sanitize_text( input_post( 'reference' ), 100 ),
					sanitize_text( input_post( 'notes' ), 500 ),
					(int) $admin['id'],
				]
			);

			if ( '1' === input_post( 'activate' ) ) {
				db_query(
					"UPDATE users SET subscription_status = 'active', plan_id = COALESCE(?, plan_id), billing_cycle = ?, subscription_ends_at = ? WHERE id = ?",
					[ $plan ? (int) $plan['id'] : null, $plan_cycle, $period_end . ' 23:59:59', (int) $target['id'] ]
				);
			}

			// A payment that activates the subscription closes the user's open plan requests.
			if ( '1' === input_post( 'activate' ) ) {
				complete_open_subscription_requests( (int) $target['id'], (int) $admin['id'] );
			}

			$unlocked = unlock_pending_reviews( (int) $target['id'] );
			audit_log( 'admin_payment_logged', 'amount=' . $amount . ' plan=' . $plan_id . ' cycle=' . $plan_cycle, (int) $target['id'] );
			flash( 'success', 'Plata a fost înregistrată.' . ( 0 < $unlocked ? ' ' . $unlocked . ' feedback-uri au fost deblocate.' : '' ) );
		}

		redirect( app_url( 'admin/user-edit.php', [ 'id' => (int) $target['id'] ] ) );
	} elseif ( ! $is_new && 'send_reset' === $action ) {
		db_query( 'DELETE FROM password_resets WHERE user_id = ?', [ (int) $target['id'] ] );
		$token = bin2hex( random_bytes( 32 ) );
		db_query( 'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)', [ (int) $target['id'], hash( 'sha256', $token ), now_db( '+60 minutes' ) ] );
		$result = send_password_reset_email( $target, $token );
		audit_log( 'admin_reset_sent', '', (int) $target['id'] );
		flash( $result['success'] ? 'success' : 'danger', $result['success'] ? 'Linkul de resetare a fost trimis.' : 'Emailul nu a putut fi trimis: ' . $result['error'] );
		redirect( app_url( 'admin/user-edit.php', [ 'id' => (int) $target['id'] ] ) );
	} elseif ( ! $is_new && 'delete' === $action ) {
		if ( $is_self ) {
			flash( 'danger', 'Nu îți poți șterge propriul cont.' );
			redirect( app_url( 'admin/user-edit.php', [ 'id' => (int) $target['id'] ] ) );
		}

		if ( 'DELETE' !== input_post( 'confirm' ) ) {
			flash( 'danger', 'Scrie DELETE pentru a confirma ștergerea.' );
			redirect( app_url( 'admin/user-edit.php', [ 'id' => (int) $target['id'] ] ) );
		}

		$files = db_all( 'SELECT logo_path, cover_path FROM companies WHERE user_id = ?', [ (int) $target['id'] ] );
		db_query( 'DELETE FROM users WHERE id = ?', [ (int) $target['id'] ] );

		foreach ( $files as $file ) {
			delete_upload( $file['logo_path'] );
			delete_upload( $file['cover_path'] );
		}

		audit_log( 'admin_user_deleted', 'email=' . $target['email'], (int) $target['id'] );
		flash( 'success', 'Utilizatorul și toate datele lui au fost șterse.' );
		redirect( app_url( 'admin/users.php' ) );
	}
}

if ( ! $is_new ) {
	$target    = get_user_by_id( (int) $target['id'] );
	$usage     = get_quota_usage( $target );
	$limits    = get_user_limits( $target );
	$summary   = get_subscription_summary( $target );
	$companies = get_user_companies( (int) $target['id'] );
	$locked    = count_locked_reviews( (int) $target['id'] );
	$payments  = db_all( 'SELECT p.*, pl.name AS plan_name FROM payments p LEFT JOIN plans pl ON pl.id = p.plan_id WHERE p.user_id = ? ORDER BY p.created_at DESC LIMIT 20', [ (int) $target['id'] ] );
	$audit     = db_all( 'SELECT * FROM audit_log WHERE target_user_id = ? ORDER BY created_at DESC LIMIT 15', [ (int) $target['id'] ] );
	$plan      = get_plan( $target['plan_id'] );
	$plan      = $plan ? $plan : ( $plans[0] ?? null );
	$next_end  = date( 'Y-m-d', strtotime( ( ! empty( $target['subscription_ends_at'] ) && strtotime( $target['subscription_ends_at'] ) > time() ? $target['subscription_ends_at'] : 'now' ) . ( 'yearly' === $target['billing_cycle'] ? ' +1 year' : ' +1 month' ) ) );
	$parent    = 'operator' === $target['role'] ? get_user_by_id( (int) $target['parent_id'] ) : null;
	$operators = 'user' === $target['role'] ? get_owner_operators( (int) $target['id'] ) : [];
}

$page_title       = $is_new ? 'Utilizator nou' : $target['name'];
$active_nav       = 'users';
$page_breadcrumbs = [ [ 'Super Admin', app_url( 'admin/index.php' ) ], [ 'Utilizatori', app_url( 'admin/users.php' ) ], [ $is_new ? 'Nou' : 'Editează', '' ] ];

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<?php if ( ! empty( $errors ) ) : ?>
	<div class="alert alert-danger">
		<?php foreach ( $errors as $error ) : ?>
			<div><?php echo esc_html( $error ); ?></div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<?php if ( ! $is_new && 'user' === $target['role'] ) : ?>
	<div class="d-flex flex-wrap gap-2 mb-3">
		<form method="post" action="<?php echo esc_url( app_url( 'admin/impersonate.php' ) ); ?>">
			<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<input type="hidden" name="user_id" value="<?php echo esc_attr( $target['id'] ); ?>">
			<button type="submit" class="btn btn-primary"><i class="bi bi-incognito"></i> Autentifică-te ca acest utilizator</button>
		</form>
		<form method="post" data-confirm="Resetezi contorul lunar de recenzii pentru acest utilizator?">
			<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<input type="hidden" name="user_id" value="<?php echo esc_attr( $target['id'] ); ?>">
			<input type="hidden" name="action" value="reset_quota">
			<button type="submit" class="btn btn-light"><i class="bi bi-arrow-counterclockwise"></i> Resetează contorul lunar</button>
		</form>
		<form method="post" data-confirm="Trimiți un link de resetare a parolei către acest utilizator?">
			<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<input type="hidden" name="user_id" value="<?php echo esc_attr( $target['id'] ); ?>">
			<input type="hidden" name="action" value="send_reset">
			<button type="submit" class="btn btn-light"><i class="bi bi-key"></i> Trimite link resetare parolă</button>
		</form>
	</div>
<?php endif; ?>

<div class="row g-3">
	<div class="col-xl-7">
		<form method="post" class="rm-card mb-3" novalidate>
			<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<input type="hidden" name="action" value="save">
			<input type="hidden" name="user_id" value="<?php echo esc_attr( $is_new ? 0 : $target['id'] ); ?>">
			<div class="rm-card-header"><h2 class="rm-card-title">Cont & abonament</h2></div>
			<div class="rm-card-body">
				<div class="row g-3">
					<div class="col-md-6">
						<label class="form-label" for="name">Nume</label>
						<input type="text" class="form-control" id="name" name="name" value="<?php echo esc_attr( $form['name'] ); ?>" maxlength="150" required>
					</div>
					<div class="col-md-6">
						<label class="form-label" for="email">Email</label>
						<input type="email" class="form-control" id="email" name="email" value="<?php echo esc_attr( $form['email'] ); ?>" required>
					</div>
					<div class="col-md-4">
						<label class="form-label" for="role">Rol</label>
						<?php if ( 'operator' === $form['role'] ) : ?>
							<input type="text" class="form-control" id="role" value="<?php echo esc_attr( 'Operator — ' . ( $parent ? $parent['name'] : '?' ) ); ?>" readonly>
							<div class="form-text">Operatorii sunt gestionați de proprietarul firmei.</div>
						<?php else : ?>
							<select class="form-select" id="role" name="role">
								<option value="user"<?php echo 'user' === $form['role'] ? ' selected' : ''; ?>>Business Owner</option>
								<option value="super_admin"<?php echo 'super_admin' === $form['role'] ? ' selected' : ''; ?>>Super Admin</option>
							</select>
						<?php endif; ?>
					</div>
					<div class="col-md-4">
						<label class="form-label" for="status">Stare cont</label>
						<select class="form-select" id="status" name="status">
							<option value="active"<?php echo 'active' === $form['status'] ? ' selected' : ''; ?>>Activ</option>
							<option value="suspended"<?php echo 'suspended' === $form['status'] ? ' selected' : ''; ?>>Suspendat</option>
						</select>
					</div>
					<div class="col-md-4">
						<label class="form-label" for="password"><?php echo esc_html( $is_new ? 'Parolă' : 'Parolă nouă (opțional)' ); ?></label>
						<input type="password" class="form-control" id="password" name="password" autocomplete="new-password" minlength="10">
					</div>
					<div class="col-12"><hr class="my-1"></div>
					<div class="col-md-6">
						<label class="form-label" for="plan_id">Plan</label>
						<select class="form-select" id="plan_id" name="plan_id">
							<option value="0">— Limite de probă —</option>
							<?php foreach ( $plans as $plan_option ) : ?>
								<option value="<?php echo esc_attr( $plan_option['id'] ); ?>"<?php echo (int) $plan_option['id'] === (int) $form['plan_id'] ? ' selected' : ''; ?>><?php echo esc_html( $plan_option['name'] . ' — ' . format_ron( $plan_option['price_ron'] ) . ( 1 !== (int) $plan_option['is_active'] ? ' (inactiv)' : '' ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="col-md-6">
						<label class="form-label" for="billing_cycle">Facturare</label>
						<select class="form-select" id="billing_cycle" name="billing_cycle">
							<?php foreach ( get_billing_cycles() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"<?php echo $key === $form['billing_cycle'] ? ' selected' : ''; ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="col-md-6">
						<label class="form-label" for="custom_max_reviews">Cotă personalizată / lună</label>
						<input type="number" min="0" class="form-control" id="custom_max_reviews" name="custom_max_reviews" value="<?php echo esc_attr( null === $form['custom_max_reviews'] ? '' : $form['custom_max_reviews'] ); ?>" placeholder="Din plan">
						<div class="form-text">Gol = limita planului · 0 = nelimitat (Enterprise).</div>
					</div>
					<div class="col-md-6">
						<label class="form-label" for="custom_max_companies">Locații personalizate</label>
						<input type="number" min="1" class="form-control" id="custom_max_companies" name="custom_max_companies" value="<?php echo esc_attr( null === $form['custom_max_companies'] ? '' : $form['custom_max_companies'] ); ?>" placeholder="Din plan">
						<div class="form-text">Gol = limita planului.</div>
					</div>
					<div class="col-md-6">
						<label class="form-label" for="subscription_status">Status abonament</label>
						<select class="form-select" id="subscription_status" name="subscription_status">
							<?php foreach ( $statuses as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"<?php echo $key === $form['subscription_status'] ? ' selected' : ''; ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="col-md-6">
						<label class="form-label" for="trial_ends_at">Probă până la</label>
						<input type="date" class="form-control" id="trial_ends_at" name="trial_ends_at" value="<?php echo esc_attr( empty( $form['trial_ends_at'] ) ? '' : date( 'Y-m-d', strtotime( $form['trial_ends_at'] ) ) ); ?>">
					</div>
					<div class="col-md-6">
						<label class="form-label" for="subscription_ends_at">Abonament valabil până la</label>
						<input type="date" class="form-control" id="subscription_ends_at" name="subscription_ends_at" value="<?php echo esc_attr( empty( $form['subscription_ends_at'] ) ? '' : date( 'Y-m-d', strtotime( $form['subscription_ends_at'] ) ) ); ?>">
						<div class="form-text">Gol = fără dată de expirare.</div>
					</div>
					<div class="col-12">
						<div class="form-check">
							<input class="form-check-input" type="checkbox" value="1" id="must_change_password" name="must_change_password"<?php echo 1 === (int) $form['must_change_password'] ? ' checked' : ''; ?>>
							<label class="form-check-label" for="must_change_password">Obligă schimbarea parolei la următoarea autentificare</label>
						</div>
					</div>
				</div>
			</div>
			<div class="rm-card-body border-top">
				<button type="submit" class="btn btn-dark"><i class="bi bi-check2"></i> <?php echo esc_html( $is_new ? 'Creează utilizatorul' : 'Salvează' ); ?></button>
			</div>
		</form>

		<?php if ( ! $is_new && 'user' === $target['role'] ) : ?>
			<div class="rm-card mb-3">
				<div class="rm-card-header"><h2 class="rm-card-title">Operatori (<?php echo esc_html( count( $operators ) ); ?>)</h2></div>
				<?php if ( empty( $operators ) ) : ?>
					<div class="rm-card-body text-muted small">Proprietarul nu a creat încă operatori.</div>
				<?php else : ?>
					<ul class="list-group list-group-flush">
						<?php foreach ( $operators as $operator ) : ?>
							<li class="list-group-item d-flex justify-content-between align-items-center">
								<div>
									<a class="fw-medium" href="<?php echo esc_url( app_url( 'admin/user-edit.php', [ 'id' => (int) $operator['id'] ] ) ); ?>"><?php echo esc_html( $operator['name'] ); ?></a>
									<div class="small text-muted"><?php echo esc_html( $operator['email'] . ' · ' . implode( ', ', array_column( $operator['companies'], 'name' ) ) ); ?></div>
								</div>
								<span class="rm-pill <?php echo esc_attr( 'active' === $operator['status'] ? 'rm-pill-positive' : 'rm-pill-muted' ); ?>"><?php echo esc_html( 'active' === $operator['status'] ? 'Activ' : 'Suspendat' ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<div class="rm-card mb-3">
				<div class="rm-card-header"><h2 class="rm-card-title">Companii (<?php echo esc_html( count( $companies ) ); ?>)</h2></div>
				<?php if ( empty( $companies ) ) : ?>
					<div class="rm-card-body text-muted small">Nicio companie.</div>
				<?php else : ?>
					<ul class="list-group list-group-flush">
						<?php foreach ( $companies as $company ) : ?>
							<li class="list-group-item d-flex justify-content-between align-items-center">
								<div>
									<div class="fw-medium"><?php echo esc_html( $company['name'] ); ?></div>
									<div class="small text-muted"><?php echo esc_html( ( '' !== $company['tax_id'] ? 'CUI ' . $company['tax_id'] : 'CUI lipsă' ) . ' · ' . get_company_public_url( $company ) ); ?></div>
								</div>
								<a class="btn btn-sm btn-light" href="<?php echo esc_url( get_company_public_url( $company ) ); ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i></a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<div class="rm-card mb-3">
				<div class="rm-card-header"><h2 class="rm-card-title">Plăți</h2></div>
				<?php if ( empty( $payments ) ) : ?>
					<div class="rm-card-body text-muted small">Nicio plată înregistrată.</div>
				<?php else : ?>
					<div class="table-responsive">
						<table class="table rm-table">
							<thead><tr><th>Data</th><th>Plan</th><th>Perioadă</th><th>Metodă</th><th>Ref.</th><th class="text-end">Sumă</th></tr></thead>
							<tbody>
								<?php foreach ( $payments as $payment ) : ?>
									<tr>
										<td class="small text-nowrap"><?php echo esc_html( format_date( $payment['created_at'] ) ); ?></td>
										<td class="small"><?php echo esc_html( $payment['plan_name'] ?? '—' ); ?></td>
										<td class="small text-nowrap"><?php echo esc_html( format_date( $payment['period_start'] ) . ' – ' . format_date( $payment['period_end'] ) ); ?></td>
										<td class="small"><?php echo esc_html( $payment['method'] ); ?></td>
										<td class="small"><?php echo esc_html( $payment['reference'] ); ?></td>
										<td class="text-end fw-semibold text-nowrap"><?php echo esc_html( format_ron( $payment['amount_ron'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>

	<div class="col-xl-5">
		<?php if ( ! $is_new && 'user' === $target['role'] ) : ?>
			<div class="rm-card rm-card-body mb-3">
				<div class="d-flex justify-content-between align-items-center mb-2">
					<span class="fw-semibold text-dark"><?php echo esc_html( $limits['plan_name'] ); ?></span>
					<span class="rm-pill <?php echo esc_attr( $summary['badge'] ); ?>"><?php echo esc_html( $summary['label'] ); ?></span>
				</div>
				<div class="small text-muted mb-1">Cotă: <?php echo esc_html( format_quota_usage( $usage ) ); ?> · ciclu <?php echo esc_html( format_date( $usage['cycle_start'] ) . ' – ' . format_date( $usage['cycle_end'] ) ); ?></div>
				<div class="progress rm-progress mb-2" role="progressbar" aria-label="Cotă" aria-valuenow="<?php echo esc_attr( $usage['percent'] ); ?>" aria-valuemin="0" aria-valuemax="100">
					<div class="progress-bar<?php echo esc_attr( 100 <= $usage['percent'] ? ' is-danger' : ( 80 <= $usage['percent'] ? ' is-warning' : '' ) ); ?>" data-width="<?php echo esc_attr( $usage['percent'] ); ?>"></div>
				</div>
				<div class="small text-muted">Feedback blocat: <strong class="<?php echo esc_attr( 0 < $locked ? 'text-danger' : '' ); ?>"><?php echo esc_html( $locked ); ?></strong> · Ultima autentificare: <?php echo esc_html( format_datetime( $target['last_login_at'] ) ); ?></div>
			</div>

			<form method="post" class="rm-card mb-3" novalidate>
				<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<input type="hidden" name="action" value="log_payment">
				<input type="hidden" name="user_id" value="<?php echo esc_attr( $target['id'] ); ?>">
				<div class="rm-card-header"><h2 class="rm-card-title">Înregistrează plată</h2></div>
				<div class="rm-card-body">
					<div class="row g-2">
						<div class="col-7">
							<label class="form-label small" for="payment_plan_id">Plan</label>
							<select class="form-select form-select-sm" id="payment_plan_id" name="payment_plan_id" data-price-target="#amount_ron">
								<?php foreach ( $plans as $plan_option ) : ?>
									<?php foreach ( get_billing_cycles() as $cycle_key => $cycle_label ) : ?>
										<option value="<?php echo esc_attr( $plan_option['id'] . ':' . $cycle_key ); ?>" data-price="<?php echo esc_attr( number_format( get_plan_price( $plan_option, $cycle_key ), 2, '.', '' ) ); ?>"<?php echo $plan && (int) $plan_option['id'] === (int) $plan['id'] && $cycle_key === $target['billing_cycle'] ? ' selected' : ''; ?>><?php echo esc_html( $plan_option['name'] . ' — ' . mb_strtolower( $cycle_label ) ); ?></option>
									<?php endforeach; ?>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="col-5">
							<label class="form-label small" for="amount_ron">Sumă (RON)</label>
							<input type="text" inputmode="decimal" class="form-control form-control-sm" id="amount_ron" name="amount_ron" value="<?php echo esc_attr( $plan ? number_format( get_plan_price( $plan, $target['billing_cycle'] ), 2, '.', '' ) : '' ); ?>" required>
						</div>
						<div class="col-6">
							<label class="form-label small" for="period_start">De la</label>
							<input type="date" class="form-control form-control-sm" id="period_start" name="period_start" value="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>" required>
						</div>
						<div class="col-6">
							<label class="form-label small" for="period_end">Până la</label>
							<input type="date" class="form-control form-control-sm" id="period_end" name="period_end" value="<?php echo esc_attr( $next_end ); ?>" required>
						</div>
						<div class="col-6">
							<label class="form-label small" for="method">Metodă</label>
							<select class="form-select form-select-sm" id="method" name="method">
								<option value="bank_transfer">Transfer bancar</option>
								<option value="card">Card</option>
								<option value="cash">Numerar</option>
								<option value="other">Altă metodă</option>
							</select>
						</div>
						<div class="col-6">
							<label class="form-label small" for="reference">Nr. factură / ref.</label>
							<input type="text" class="form-control form-control-sm" id="reference" name="reference" maxlength="100">
						</div>
						<div class="col-12">
							<label class="form-label small" for="notes">Note</label>
							<input type="text" class="form-control form-control-sm" id="notes" name="notes" maxlength="500">
						</div>
						<div class="col-12">
							<div class="form-check">
								<input class="form-check-input" type="checkbox" value="1" id="activate" name="activate" checked>
								<label class="form-check-label small" for="activate">Activează abonamentul pe planul ales până la data de final</label>
							</div>
						</div>
					</div>
				</div>
				<div class="rm-card-body border-top">
					<button type="submit" class="btn btn-dark btn-sm"><i class="bi bi-receipt"></i> Înregistrează plata</button>
				</div>
			</form>
		<?php endif; ?>

		<?php if ( ! $is_new ) : ?>
			<div class="rm-card mb-3">
				<div class="rm-card-header"><h2 class="rm-card-title">Jurnal de securitate</h2></div>
				<?php if ( empty( $audit ) ) : ?>
					<div class="rm-card-body text-muted small">Nicio intrare.</div>
				<?php else : ?>
					<ul class="list-group list-group-flush small">
						<?php foreach ( $audit as $entry ) : ?>
							<li class="list-group-item">
								<div class="d-flex justify-content-between"><span class="fw-medium"><?php echo esc_html( $entry['action'] ); ?></span><span class="text-muted"><?php echo esc_html( format_datetime( $entry['created_at'] ) ); ?></span></div>
								<div class="text-muted text-truncate"><?php echo esc_html( $entry['details'] ); ?> · IP <?php echo esc_html( $entry['ip_address'] ); ?></div>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<?php if ( ! $is_self ) : ?>
				<form method="post" class="rm-card border-danger-subtle" data-confirm="Ștergi definitiv utilizatorul, companiile și toate recenziile lui?">
					<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<input type="hidden" name="action" value="delete">
					<input type="hidden" name="user_id" value="<?php echo esc_attr( $target['id'] ); ?>">
					<div class="rm-card-header"><h2 class="rm-card-title text-danger">Zonă periculoasă</h2></div>
					<div class="rm-card-body">
						<p class="small text-muted">Ștergerea este definitivă: contul, companiile, recenziile, notele și plățile vor fi eliminate.</p>
						<label class="form-label small" for="confirm">Scrie <strong>DELETE</strong> pentru confirmare</label>
						<input type="text" class="form-control form-control-sm mb-2" id="confirm" name="confirm" autocomplete="off">
						<button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i> Șterge utilizatorul</button>
					</div>
				</form>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>

<?php
require REPUVIO_ROOT . '/templates/app-footer.php';
