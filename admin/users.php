<?php
/**
 * Super Admin user list: search, filters, pagination.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

require_super_admin();

$statuses = get_subscription_statuses();
$plans    = get_plans();
$filters  = [
	'search'  => sanitize_text( input_get( 'search' ), 100 ),
	'role'    => in_array( input_get( 'role' ), [ 'user', 'operator', 'super_admin' ], true ) ? input_get( 'role' ) : '',
	'sub'     => array_key_exists( input_get( 'sub' ), $statuses ) ? input_get( 'sub' ) : '',
	'plan_id' => sanitize_int_range( input_get( 'plan_id', '0' ), 0, PHP_INT_MAX, 0 ),
	'account' => in_array( input_get( 'account' ), [ 'active', 'suspended' ], true ) ? input_get( 'account' ) : '',
];

$where  = [ '1 = 1' ];
$params = [];

if ( '' !== $filters['search'] ) {
	$like             = '%' . str_replace( [ '\\', '%', '_' ], [ '\\\\', '\\%', '\\_' ], $filters['search'] ) . '%';
	$where[]          = '(u.name LIKE :search OR u.email LIKE :search2)';
	$params['search']  = $like;
	$params['search2'] = $like;
}
if ( '' !== $filters['role'] ) {
	$where[]        = 'u.role = :role';
	$params['role'] = $filters['role'];
}
if ( '' !== $filters['sub'] ) {
	$where[]       = 'u.subscription_status = :sub';
	$params['sub'] = $filters['sub'];
}
if ( 0 < $filters['plan_id'] ) {
	$where[]           = 'u.plan_id = :plan_id';
	$params['plan_id'] = $filters['plan_id'];
}
if ( '' !== $filters['account'] ) {
	$where[]           = 'u.status = :account';
	$params['account'] = $filters['account'];
}

$where_sql  = implode( ' AND ', $where );
$total      = (int) db_value( 'SELECT COUNT(*) FROM users u WHERE ' . $where_sql, $params );
$pagination = paginate( $total, 25, (int) input_get( 'page', '1' ) );

$list_params           = $params;
$list_params['limit']  = $pagination['per_page'];
$list_params['offset'] = $pagination['offset'];

$users = db_all(
	'SELECT u.*, p.name AS plan_name, o.name AS parent_name,
		(SELECT COUNT(*) FROM companies c WHERE c.user_id = u.id) AS companies_count,
		(SELECT COUNT(*) FROM users op WHERE op.parent_id = u.id) AS operators_count
	FROM users u LEFT JOIN plans p ON p.id = u.plan_id LEFT JOIN users o ON o.id = u.parent_id
	WHERE ' . $where_sql . '
	ORDER BY u.created_at DESC
	LIMIT :limit OFFSET :offset',
	$list_params
);

$query = array_filter(
	$filters,
	function ( $value ) {
		return '' !== $value && 0 !== $value;
	}
);

$page_title       = 'Utilizatori';
$active_nav       = 'users';
$page_breadcrumbs = [ [ 'Super Admin', app_url( 'admin/index.php' ) ], [ 'Utilizatori', '' ] ];

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<div class="rm-card mb-3">
	<div class="rm-card-body">
		<form method="get" class="row g-2 align-items-end">
			<div class="col-md-4">
				<label class="form-label small" for="search">Caută</label>
				<input type="search" class="form-control form-control-sm" id="search" name="search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="Nume sau email">
			</div>
			<div class="col-6 col-md-2">
				<label class="form-label small" for="role">Rol</label>
				<select class="form-select form-select-sm" id="role" name="role">
					<option value="">Toate</option>
					<option value="user"<?php echo 'user' === $filters['role'] ? ' selected' : ''; ?>>Business Owner</option>
					<option value="operator"<?php echo 'operator' === $filters['role'] ? ' selected' : ''; ?>>Operator</option>
					<option value="super_admin"<?php echo 'super_admin' === $filters['role'] ? ' selected' : ''; ?>>Super Admin</option>
				</select>
			</div>
			<div class="col-6 col-md-2">
				<label class="form-label small" for="sub">Abonament</label>
				<select class="form-select form-select-sm" id="sub" name="sub">
					<option value="">Toate</option>
					<?php foreach ( $statuses as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"<?php echo $key === $filters['sub'] ? ' selected' : ''; ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="col-6 col-md-2">
				<label class="form-label small" for="plan_id">Plan</label>
				<select class="form-select form-select-sm" id="plan_id" name="plan_id">
					<option value="0">Toate</option>
					<?php foreach ( $plans as $plan ) : ?>
						<option value="<?php echo esc_attr( $plan['id'] ); ?>"<?php echo (int) $plan['id'] === $filters['plan_id'] ? ' selected' : ''; ?>><?php echo esc_html( $plan['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="col-6 col-md-2">
				<label class="form-label small" for="account">Cont</label>
				<select class="form-select form-select-sm" id="account" name="account">
					<option value="">Toate</option>
					<option value="active"<?php echo 'active' === $filters['account'] ? ' selected' : ''; ?>>Activ</option>
					<option value="suspended"<?php echo 'suspended' === $filters['account'] ? ' selected' : ''; ?>>Suspendat</option>
				</select>
			</div>
			<div class="col-12 d-flex gap-2 justify-content-end">
				<a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'admin/users.php' ) ); ?>">Resetează</a>
				<button type="submit" class="btn btn-sm btn-dark"><i class="bi bi-funnel"></i> Filtrează</button>
				<a class="btn btn-sm btn-primary" href="<?php echo esc_url( app_url( 'admin/user-edit.php' ) ); ?>"><i class="bi bi-person-plus"></i> Utilizator nou</a>
			</div>
		</form>
	</div>
</div>

<div class="rm-card">
	<div class="rm-card-header"><h2 class="rm-card-title"><?php echo esc_html( format_number( $total ) ); ?> utilizatori</h2></div>
	<div class="table-responsive">
		<table class="table rm-table">
			<thead>
				<tr><th>Utilizator</th><th>Rol</th><th>Plan</th><th>Abonament</th><th>Cotă</th><th>Companii</th><th>Înregistrat</th><th></th></tr>
			</thead>
			<tbody>
				<?php foreach ( $users as $row ) : ?>
					<?php
					$summary = get_subscription_summary( $row );
					$usage   = get_quota_usage( $row );
					?>
					<tr>
						<td>
							<a class="fw-medium" href="<?php echo esc_url( app_url( 'admin/user-edit.php', [ 'id' => (int) $row['id'] ] ) ); ?>"><?php echo esc_html( $row['name'] ); ?></a>
							<?php if ( 'suspended' === $row['status'] ) : ?>
								<span class="rm-pill rm-pill-negative ms-1">Suspendat</span>
							<?php endif; ?>
							<div class="small text-muted"><?php echo esc_html( $row['email'] ); ?></div>
						</td>
						<td class="small">
							<?php echo esc_html( get_role_label( $row['role'] ) ); ?>
							<?php if ( 'operator' === $row['role'] ) : ?>
								<div class="text-muted">al <?php echo esc_html( $row['parent_name'] ?? '—' ); ?></div>
							<?php elseif ( 'user' === $row['role'] && 0 < (int) $row['operators_count'] ) : ?>
								<div class="text-muted"><?php echo esc_html( (int) $row['operators_count'] ); ?> operatori</div>
							<?php endif; ?>
						</td>
						<td class="small"><?php echo esc_html( 'user' !== $row['role'] ? '—' : ( $row['plan_name'] ?? 'Probă' ) . ( null !== $row['plan_name'] && 'yearly' === $row['billing_cycle'] ? ' (anual)' : '' ) ); ?></td>
						<td>
							<?php if ( 'user' === $row['role'] ) : ?>
								<span class="rm-pill <?php echo esc_attr( $summary['badge'] ); ?>"><?php echo esc_html( $summary['label'] ); ?></span>
								<?php if ( ! empty( $summary['ends_at'] ) ) : ?>
									<div class="small text-muted"><?php echo esc_html( format_date( $summary['ends_at'] ) ); ?></div>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td class="small text-nowrap"><?php echo esc_html( 'user' === $row['role'] ? format_quota_usage( $usage ) : '—' ); ?></td>
						<td class="small"><?php echo esc_html( (int) $row['companies_count'] ); ?></td>
						<td class="small text-muted text-nowrap"><?php echo esc_html( format_date( $row['created_at'] ) ); ?></td>
						<td class="text-end text-nowrap">
							<a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'admin/user-edit.php', [ 'id' => (int) $row['id'] ] ) ); ?>" title="Editează"><i class="bi bi-pencil"></i></a>
							<?php if ( 'user' === $row['role'] ) : ?>
								<form method="post" action="<?php echo esc_url( app_url( 'admin/impersonate.php' ) ); ?>" class="d-inline">
									<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<input type="hidden" name="user_id" value="<?php echo esc_attr( $row['id'] ); ?>">
									<button type="submit" class="btn btn-sm btn-light" title="Autentifică-te ca acest utilizator"><i class="bi bi-incognito"></i></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ( empty( $users ) ) : ?>
					<tr><td colspan="8" class="text-center text-muted py-4">Niciun utilizator găsit.</td></tr>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
	<div class="rm-card-body border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
		<span class="small text-muted">Pagina <?php echo esc_html( $pagination['page'] ); ?> din <?php echo esc_html( $pagination['pages'] ); ?></span>
		<?php render_pagination( $pagination, 'admin/users.php', $query ); ?>
	</div>
</div>

<?php
require REPUVIO_ROOT . '/templates/app-footer.php';
