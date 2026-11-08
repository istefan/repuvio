<?php
/**
 * Super Admin: customer subscriptions control center.
 * - KPIs (MRR, ARR, cash-in this month / 12 months, active, trials, expired, open requests).
 * - Charts: monthly cash-in from payments (12 months) and MRR per plan.
 * - Tabs: plan requests (stored in DB, independent of email delivery, with status + note),
 *   active subscriptions, trials, expiring in 14 days, expired / past due / canceled.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$admin        = require_super_admin();
$req_statuses = get_request_statuses();
$tabs         = [
	'requests' => 'Solicitări',
	'active'   => 'Active',
	'trials'   => 'În probă',
	'expiring' => 'Expiră în 14 zile',
	'expired'  => 'Expirate & restante',
];

if ( is_post() && 'update_request' === input_post( 'action' ) ) {
	$request_id = (int) input_post( 'request_id' );
	$status     = input_post( 'status' );

	if ( array_key_exists( $status, $req_statuses ) ) {
		db_query(
			'UPDATE subscription_requests SET status = ?, admin_note = ?, processed_by = ?, processed_at = NOW() WHERE id = ?',
			[ $status, sanitize_text( input_post( 'admin_note' ), 500 ), (int) $admin['id'], $request_id ]
		);
		audit_log( 'admin_request_updated', 'request=' . $request_id . ' status=' . $status );
		flash( 'success', 'Solicitarea #' . $request_id . ' a fost actualizată: ' . $req_statuses[ $status ][0] . '.' );
	}

	redirect( app_url( 'admin/subscriptions.php', [ 'tab' => 'requests', 'show' => input_post( 'show', 'open' ) ] ) );
}

$open_count = count_open_subscription_requests();
$active_tab = array_key_exists( input_get( 'tab' ), $tabs ) ? input_get( 'tab' ) : ( 0 < $open_count ? 'requests' : 'active' );
$show       = 'all' === input_get( 'show' ) ? 'all' : 'open';
$stats      = get_platform_stats();
$series     = get_revenue_series( 12 );
$mrr_plans  = get_mrr_by_plan();
$month_cash = (float) end( $series['revenue'] );
$year_cash  = array_sum( $series['revenue'] );

$user_select = "SELECT u.id, u.name, u.email, u.status, u.subscription_status, u.billing_cycle, u.trial_ends_at, u.subscription_ends_at, u.created_at,
		p.name AS plan_name, p.price_ron, p.price_yearly_ron,
		(SELECT MAX(pm.created_at) FROM payments pm WHERE pm.user_id = u.id) AS last_payment_at,
		(SELECT COUNT(*) FROM companies c WHERE c.user_id = u.id) AS companies_count
	FROM users u LEFT JOIN plans p ON p.id = u.plan_id
	WHERE u.role = 'user' AND ";

$lists = [
	'active'   => db_all( $user_select . "u.subscription_status = 'active' AND (u.subscription_ends_at IS NULL OR u.subscription_ends_at > NOW()) ORDER BY u.subscription_ends_at IS NULL, u.subscription_ends_at ASC" ),
	'trials'   => db_all( $user_select . "u.subscription_status = 'trialing' AND u.trial_ends_at > NOW() ORDER BY u.trial_ends_at ASC" ),
	'expiring' => db_all(
		$user_select . "((u.subscription_status = 'active' AND u.subscription_ends_at BETWEEN NOW() AND NOW() + INTERVAL 14 DAY)
		OR (u.subscription_status = 'trialing' AND u.trial_ends_at BETWEEN NOW() AND NOW() + INTERVAL 14 DAY))
		ORDER BY COALESCE(u.subscription_ends_at, u.trial_ends_at) ASC"
	),
	'expired'  => db_all(
		$user_select . "(u.subscription_status IN ('expired', 'canceled', 'past_due')
		OR (u.subscription_status = 'active' AND u.subscription_ends_at <= NOW())
		OR (u.subscription_status = 'trialing' AND u.trial_ends_at <= NOW()))
		ORDER BY COALESCE(u.subscription_ends_at, u.trial_ends_at) DESC"
	),
];

$requests = db_all(
	'SELECT r.*, u.name AS user_name, u.email AS user_email, a.name AS processed_by_name
	FROM subscription_requests r
	INNER JOIN users u ON u.id = r.user_id
	LEFT JOIN users a ON a.id = r.processed_by
	' . ( 'open' === $show ? "WHERE r.status IN ('pending', 'invoiced')" : '' ) . '
	ORDER BY FIELD(r.status, \'pending\', \'invoiced\', \'completed\', \'rejected\'), r.created_at DESC
	LIMIT 200'
);

$counts = [
	'requests' => $open_count,
	'active'   => count( $lists['active'] ),
	'trials'   => count( $lists['trials'] ),
	'expiring' => count( $lists['expiring'] ),
	'expired'  => count( $lists['expired'] ),
];

/**
 * Days until a date (negative when in the past), or null.
 *
 * @param string|null $date Datetime.
 * @return int|null
 */
function subscription_days_left( $date ) {
	return empty( $date ) ? null : (int) floor( ( strtotime( $date ) - time() ) / 86400 );
}

/**
 * Renders the customer table for a list tab.
 *
 * @param array  $rows  Users.
 * @param string $empty Empty-state text.
 * @return void
 */
function render_subscription_table( array $rows, $empty ) {
	$statuses = get_subscription_statuses();

	if ( empty( $rows ) ) {
		echo '<div class="rm-empty"><i class="bi bi-inbox"></i><p class="mt-2 mb-0">' . esc_html( $empty ) . '</p></div>';

		return;
	}
	?>
	<div class="table-responsive">
		<table class="table rm-table">
			<thead><tr><th>Client</th><th>Plan</th><th>Status</th><th>Valabil până la</th><th class="text-end">Valoare</th><th>Ultima plată</th><th></th></tr></thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$summary = get_subscription_summary( $row + [ 'role' => 'user' ] );
					$ends    = 'trialing' === $row['subscription_status'] ? $row['trial_ends_at'] : $row['subscription_ends_at'];
					$days    = subscription_days_left( $ends );
					$value   = null === $row['plan_name'] ? null : ( 'yearly' === $row['billing_cycle'] ? (float) $row['price_yearly_ron'] : (float) $row['price_ron'] );
					?>
					<tr>
						<td>
							<a class="fw-medium" href="<?php echo esc_url( app_url( 'admin/user-edit.php', [ 'id' => (int) $row['id'] ] ) ); ?>"><?php echo esc_html( $row['name'] ); ?></a>
							<?php if ( 'suspended' === $row['status'] ) : ?>
								<span class="rm-pill rm-pill-negative ms-1">Suspendat</span>
							<?php endif; ?>
							<div class="small text-muted"><?php echo esc_html( $row['email'] . ' · ' . (int) $row['companies_count'] . ' companii' ); ?></div>
						</td>
						<td class="small"><?php echo esc_html( null === $row['plan_name'] ? 'Probă gratuită' : $row['plan_name'] . ' · ' . ( 'yearly' === $row['billing_cycle'] ? 'anual' : 'lunar' ) ); ?></td>
						<td><span class="rm-pill <?php echo esc_attr( $summary['badge'] ); ?>"><?php echo esc_html( $statuses[ $row['subscription_status'] ] ?? $row['subscription_status'] ); ?></span></td>
						<td class="small text-nowrap">
							<?php echo esc_html( empty( $ends ) ? 'Fără dată de expirare' : format_date( $ends ) ); ?>
							<?php if ( null !== $days ) : ?>
								<div class="<?php echo esc_attr( 0 > $days ? 'text-danger' : ( 14 >= $days ? 'text-warning' : 'text-muted' ) ); ?>"><?php echo esc_html( 0 > $days ? 'expirat de ' . abs( $days ) . ' zile' : 'încă ' . $days . ' zile' ); ?></div>
							<?php endif; ?>
						</td>
						<td class="text-end text-nowrap fw-semibold"><?php echo esc_html( null === $value ? '—' : format_ron( $value ) ); ?></td>
						<td class="small text-nowrap text-muted"><?php echo esc_html( empty( $row['last_payment_at'] ) ? '—' : format_date( $row['last_payment_at'] ) ); ?></td>
						<td class="text-end text-nowrap"><a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'admin/user-edit.php', [ 'id' => (int) $row['id'] ] ) ); ?>"><i class="bi bi-receipt"></i> Plată / editare</a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

$page_title       = 'Abonamente clienți';
$active_nav       = 'subscriptions';
$page_breadcrumbs = [ [ 'Super Admin', app_url( 'admin/index.php' ) ], [ 'Abonamente clienți', '' ] ];
$page_scripts     = [ 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', asset_url( 'js/subscriptions.js' ) ];
$page_inline_data = [
	'labels'    => $series['labels'],
	'revenue'   => $series['revenue'],
	'payments'  => $series['payments'],
	'planNames' => array_column( $mrr_plans, 'name' ),
	'planMrr'   => array_map(
		function ( $row ) {
			return round( (float) $row['mrr'], 2 );
		},
		$mrr_plans
	),
];

$kpis = [
	[ 'bi-cash-stack', 'MRR', format_ron( $stats['mrr'] ), 'ARR estimat: ' . format_ron( $stats['mrr'] * 12 ) ],
	[ 'bi-wallet2', 'Încasat luna aceasta', format_ron( $month_cash ), 'Ultimele 12 luni: ' . format_ron( $year_cash ) ],
	[ 'bi-patch-check', 'Abonamente active', format_number( $stats['active_subscriptions'] ), format_number( $stats['active_trials'] ) . ' în perioada de probă' ],
	[ 'bi-inbox', 'Solicitări deschise', format_number( $open_count ), format_number( $stats['expired'] ) . ' expirate / restante' ],
];

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<div class="row g-3 mb-3">
	<?php foreach ( $kpis as $kpi ) : ?>
		<div class="col-sm-6 col-xl-3">
			<div class="rm-card rm-card-body h-100">
				<div class="rm-kpi-label"><span class="rm-kpi-icon"><i class="bi <?php echo esc_attr( $kpi[0] ); ?>"></i></span> <?php echo esc_html( $kpi[1] ); ?></div>
				<div class="rm-kpi-value"><?php echo esc_html( $kpi[2] ); ?></div>
				<div class="small text-muted"><?php echo esc_html( $kpi[3] ); ?></div>
			</div>
		</div>
	<?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
	<div class="col-xl-8">
		<div class="rm-card h-100">
			<div class="rm-card-header"><h2 class="rm-card-title">Încasări lunare — ultimele 12 luni</h2><span class="small text-muted">din plățile înregistrate</span></div>
			<div class="rm-card-body"><div class="rm-chart-wrap"><canvas id="rmRevenueChart" role="img" aria-label="Grafic încasări lunare"></canvas></div></div>
		</div>
	</div>
	<div class="col-xl-4">
		<div class="rm-card h-100">
			<div class="rm-card-header"><h2 class="rm-card-title">MRR pe plan</h2></div>
			<div class="rm-card-body">
				<?php if ( 0.0 >= (float) $stats['mrr'] ) : ?>
					<div class="rm-empty"><i class="bi bi-pie-chart"></i><p class="mt-2 mb-0">Încă nu există abonamente plătite active.</p></div>
				<?php else : ?>
					<div class="rm-chart-wrap-sm"><canvas id="rmMrrChart" role="img" aria-label="MRR pe plan"></canvas></div>
				<?php endif; ?>
				<ul class="list-unstyled small mt-3 mb-0">
					<?php foreach ( $mrr_plans as $plan_row ) : ?>
						<li class="d-flex justify-content-between py-1 border-bottom"><span><?php echo esc_html( $plan_row['name'] ); ?> <span class="text-muted">· <?php echo esc_html( (int) $plan_row['subscribers'] ); ?> abonați</span></span><strong><?php echo esc_html( format_ron( $plan_row['mrr'] ) ); ?></strong></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
	</div>
</div>

<ul class="nav nav-pills rm-tabs gap-1 mb-3 flex-wrap" role="tablist">
	<?php foreach ( $tabs as $key => $label ) : ?>
		<li class="nav-item" role="presentation">
			<button class="nav-link<?php echo $key === $active_tab ? ' active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#sub-<?php echo esc_attr( $key ); ?>" type="button" role="tab">
				<?php echo esc_html( $label ); ?>
				<span class="badge rounded-pill <?php echo esc_attr( 'requests' === $key && 0 < $counts[ $key ] ? 'bg-danger' : 'bg-secondary-subtle text-secondary' ); ?> ms-1"><?php echo esc_html( $counts[ $key ] ); ?></span>
			</button>
		</li>
	<?php endforeach; ?>
</ul>

<div class="tab-content">
	<div class="tab-pane fade<?php echo 'requests' === $active_tab ? ' show active' : ''; ?>" id="sub-requests" role="tabpanel">
		<div class="rm-card">
			<div class="rm-card-header">
				<h2 class="rm-card-title">Solicitări de abonament</h2>
				<div class="btn-group btn-group-sm">
					<a class="btn <?php echo esc_attr( 'open' === $show ? 'btn-dark' : 'btn-light' ); ?>" href="<?php echo esc_url( app_url( 'admin/subscriptions.php', [ 'tab' => 'requests', 'show' => 'open' ] ) ); ?>">Deschise</a>
					<a class="btn <?php echo esc_attr( 'all' === $show ? 'btn-dark' : 'btn-light' ); ?>" href="<?php echo esc_url( app_url( 'admin/subscriptions.php', [ 'tab' => 'requests', 'show' => 'all' ] ) ); ?>">Toate</a>
				</div>
			</div>
			<?php if ( empty( $requests ) ) : ?>
				<div class="rm-empty"><i class="bi bi-inbox"></i><p class="mt-2 mb-0"><?php echo esc_html( 'open' === $show ? 'Nicio solicitare deschisă.' : 'Nicio solicitare încă.' ); ?></p></div>
			<?php else : ?>
				<div class="table-responsive">
					<table class="table rm-table">
						<thead><tr><th>#</th><th>Data</th><th>Client</th><th>Abonament</th><th class="text-end">Preț</th><th>Email</th><th style="min-width: 320px;">Status & notă</th></tr></thead>
						<tbody>
							<?php foreach ( $requests as $request ) : ?>
								<tr>
									<td class="fw-semibold">#<?php echo esc_html( (int) $request['id'] ); ?></td>
									<td class="small text-nowrap"><?php echo esc_html( format_datetime( $request['created_at'] ) ); ?><div class="text-muted"><?php echo esc_html( time_ago( $request['created_at'] ) ); ?></div></td>
									<td>
										<a class="fw-medium" href="<?php echo esc_url( app_url( 'admin/user-edit.php', [ 'id' => (int) $request['user_id'] ] ) ); ?>"><?php echo esc_html( $request['user_name'] ); ?></a>
										<div class="small text-muted"><?php echo esc_html( $request['user_email'] ); ?></div>
									</td>
									<td><?php echo esc_html( $request['plan_name'] ); ?><div class="small text-muted"><?php echo esc_html( 'yearly' === $request['billing_cycle'] ? 'Anual' : 'Lunar' ); ?></div></td>
									<td class="text-end text-nowrap fw-semibold"><?php echo esc_html( format_ron( $request['price_ron'] ) ); ?></td>
									<td>
										<?php if ( 1 === (int) $request['email_sent'] ) : ?>
											<span class="rm-pill rm-pill-positive" title="Emailul de notificare a fost trimis"><i class="bi bi-envelope-check"></i> Trimis</span>
										<?php else : ?>
											<span class="rm-pill rm-pill-negative" title="Emailul nu a putut fi trimis — solicitarea există doar aici"><i class="bi bi-envelope-x"></i> Netrimis</span>
										<?php endif; ?>
									</td>
									<td>
										<form method="post" class="d-flex flex-wrap gap-1">
											<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
											<input type="hidden" name="action" value="update_request">
											<input type="hidden" name="request_id" value="<?php echo esc_attr( $request['id'] ); ?>">
											<input type="hidden" name="show" value="<?php echo esc_attr( $show ); ?>">
											<select class="form-select form-select-sm w-auto" name="status" aria-label="Status solicitare">
												<?php foreach ( $req_statuses as $key => $status ) : ?>
													<option value="<?php echo esc_attr( $key ); ?>"<?php echo $key === $request['status'] ? ' selected' : ''; ?>><?php echo esc_html( $status[0] ); ?></option>
												<?php endforeach; ?>
											</select>
											<input type="text" class="form-control form-control-sm" style="max-width: 180px;" name="admin_note" value="<?php echo esc_attr( $request['admin_note'] ); ?>" maxlength="500" placeholder="Notă (ex: proforma 123)" aria-label="Notă">
											<button type="submit" class="btn btn-sm btn-dark">Salvează</button>
											<a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'admin/user-edit.php', [ 'id' => (int) $request['user_id'] ] ) ); ?>" title="Înregistrează plata și activează abonamentul"><i class="bi bi-receipt"></i></a>
										</form>
										<?php if ( ! empty( $request['processed_at'] ) ) : ?>
											<div class="small text-muted mt-1"><?php echo esc_html( 'Actualizat ' . format_datetime( $request['processed_at'] ) . ( ! empty( $request['processed_by_name'] ) ? ' de ' . $request['processed_by_name'] : '' ) ); ?></div>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<div class="rm-card-body border-top small text-muted"><i class="bi bi-info-circle"></i> Când înregistrezi o plată cu „Activează abonamentul”, solicitările deschise ale clientului devin automat „Activată”.</div>
			<?php endif; ?>
		</div>
	</div>

	<?php
	$empty_texts = [
		'active'   => 'Niciun abonament plătit activ.',
		'trials'   => 'Nicio perioadă de probă activă.',
		'expiring' => 'Nimic nu expiră în următoarele 14 zile.',
		'expired'  => 'Niciun abonament expirat sau restant.',
	];
	?>
	<?php foreach ( $empty_texts as $key => $empty_text ) : ?>
		<div class="tab-pane fade<?php echo $key === $active_tab ? ' show active' : ''; ?>" id="sub-<?php echo esc_attr( $key ); ?>" role="tabpanel">
			<div class="rm-card">
				<?php render_subscription_table( $lists[ $key ], $empty_text ); ?>
			</div>
		</div>
	<?php endforeach; ?>
</div>

<?php
require REPUVIO_ROOT . '/templates/app-footer.php';
