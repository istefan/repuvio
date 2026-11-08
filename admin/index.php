<?php
/**
 * Super Admin platform overview (MRR, users, trials, subscriptions, companies, reviews).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

require_super_admin();

$stats = get_platform_stats();

$signup_rows = db_all(
	"SELECT DATE(created_at) AS day, COUNT(*) AS total FROM users WHERE role = 'user' AND created_at >= CURDATE() - INTERVAL 29 DAY GROUP BY DATE(created_at)"
);
$review_rows = db_all(
	'SELECT DATE(created_at) AS day, COUNT(*) AS total FROM reviews WHERE created_at >= CURDATE() - INTERVAL 29 DAY GROUP BY DATE(created_at)'
);

$signups_by_day = array_column( $signup_rows, 'total', 'day' );
$reviews_by_day = array_column( $review_rows, 'total', 'day' );
$chart          = [
	'labels'  => [],
	'signups' => [],
	'reviews' => [],
];

for ( $i = 29; 0 <= $i; $i-- ) {
	$day = date( 'Y-m-d', strtotime( '-' . $i . ' days' ) );

	$chart['labels'][]  = date( 'd.m', strtotime( $day ) );
	$chart['signups'][] = (int) ( $signups_by_day[ $day ] ?? 0 );
	$chart['reviews'][] = (int) ( $reviews_by_day[ $day ] ?? 0 );
}

$recent_users = db_all(
	"SELECT u.*, p.name AS plan_name FROM users u LEFT JOIN plans p ON p.id = u.plan_id WHERE u.role = 'user' ORDER BY u.created_at DESC LIMIT 8"
);
$expiring     = db_all(
	"SELECT id, name, email, subscription_status, trial_ends_at, subscription_ends_at FROM users
	WHERE role = 'user' AND (
		(subscription_status = 'trialing' AND trial_ends_at BETWEEN NOW() AND NOW() + INTERVAL 7 DAY)
		OR (subscription_status = 'active' AND subscription_ends_at BETWEEN NOW() AND NOW() + INTERVAL 7 DAY)
	) ORDER BY COALESCE(subscription_ends_at, trial_ends_at) ASC LIMIT 8"
);
$statuses     = get_subscription_statuses();

$page_title       = 'Prezentare generală';
$active_nav       = 'admin';
$page_breadcrumbs = [ [ 'Super Admin', '' ] ];
$page_scripts     = [ 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', asset_url( 'js/admin.js' ) ];
$page_inline_data = $chart;

$kpis = [
	[ 'bi-cash-stack', 'MRR (venit lunar recurent)', format_ron( $stats['mrr'] ), 'Încasat în ultimele 30 zile: ' . format_ron( $stats['revenue_30d'] ) ],
	[ 'bi-people', 'Utilizatori', format_number( $stats['total_users'] ), format_number( $stats['expired'] ) . ' expirați / anulați' ],
	[ 'bi-hourglass-split', 'Perioade de probă active', format_number( $stats['active_trials'] ), 'Conversie potențială' ],
	[ 'bi-patch-check', 'Abonamente active', format_number( $stats['active_subscriptions'] ), 'Clienți plătitori' ],
	[ 'bi-shop', 'Companii', format_number( $stats['total_companies'] ), format_number( $stats['total_views'] ) . ' vizualizări funnel' ],
	[ 'bi-chat-square-heart', 'Recenzii procesate', format_number( $stats['total_reviews'] ), 'Google + feedback privat' ],
];

$open_requests = count_open_subscription_requests();

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<?php if ( 0 < $open_requests ) : ?>
	<div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2">
		<div><i class="bi bi-inbox"></i> Ai <strong><?php echo esc_html( $open_requests ); ?></strong> <?php echo esc_html( 1 === $open_requests ? 'solicitare de abonament deschisă' : 'solicitări de abonament deschise' ); ?>.</div>
		<a class="btn btn-sm btn-dark" href="<?php echo esc_url( app_url( 'admin/subscriptions.php', [ 'tab' => 'requests' ] ) ); ?>">Vezi solicitările</a>
	</div>
<?php endif; ?>

<div class="row g-3 mb-3">
	<?php foreach ( $kpis as $kpi ) : ?>
		<div class="col-sm-6 col-xl-4 col-xxl-2">
			<div class="rm-card rm-card-body h-100">
				<div class="rm-kpi-label"><span class="rm-kpi-icon"><i class="bi <?php echo esc_attr( $kpi[0] ); ?>"></i></span> <?php echo esc_html( $kpi[1] ); ?></div>
				<div class="rm-kpi-value fs-3"><?php echo esc_html( $kpi[2] ); ?></div>
				<div class="small text-muted"><?php echo esc_html( $kpi[3] ); ?></div>
			</div>
		</div>
	<?php endforeach; ?>
</div>

<div class="rm-card mb-3">
	<div class="rm-card-header"><h2 class="rm-card-title">Înregistrări și recenzii — ultimele 30 de zile</h2></div>
	<div class="rm-card-body"><div class="rm-chart-wrap"><canvas id="rmAdminChart" role="img" aria-label="Grafic platformă"></canvas></div></div>
</div>

<div class="row g-3">
	<div class="col-xl-7">
		<div class="rm-card h-100">
			<div class="rm-card-header">
				<h2 class="rm-card-title">Utilizatori noi</h2>
				<a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'admin/users.php' ) ); ?>">Toți utilizatorii</a>
			</div>
			<div class="table-responsive">
				<table class="table rm-table">
					<thead><tr><th>Utilizator</th><th>Abonament</th><th>Status</th><th>Înregistrat</th></tr></thead>
					<tbody>
						<?php foreach ( $recent_users as $row ) : ?>
							<?php $summary = get_subscription_summary( $row ); ?>
							<tr>
								<td><a class="fw-medium" href="<?php echo esc_url( app_url( 'admin/user-edit.php', [ 'id' => (int) $row['id'] ] ) ); ?>"><?php echo esc_html( $row['name'] ); ?></a><div class="small text-muted"><?php echo esc_html( $row['email'] ); ?></div></td>
								<td class="small"><?php echo esc_html( $row['plan_name'] ?? 'Probă' ); ?></td>
								<td><span class="rm-pill <?php echo esc_attr( $summary['badge'] ); ?>"><?php echo esc_html( $summary['label'] ); ?></span></td>
								<td class="small text-muted text-nowrap"><?php echo esc_html( format_date( $row['created_at'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						<?php if ( empty( $recent_users ) ) : ?>
							<tr><td colspan="4" class="text-center text-muted py-4">Niciun utilizator încă.</td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
	<div class="col-xl-5">
		<div class="rm-card h-100">
			<div class="rm-card-header"><h2 class="rm-card-title">Expiră în următoarele 7 zile</h2></div>
			<?php if ( empty( $expiring ) ) : ?>
				<div class="rm-empty"><i class="bi bi-calendar-check"></i><p class="mt-2 mb-0">Nimic nu expiră curând.</p></div>
			<?php else : ?>
				<ul class="list-group list-group-flush">
					<?php foreach ( $expiring as $row ) : ?>
						<li class="list-group-item d-flex justify-content-between align-items-center gap-2">
							<div class="min-w-0">
								<a class="fw-medium" href="<?php echo esc_url( app_url( 'admin/user-edit.php', [ 'id' => (int) $row['id'] ] ) ); ?>"><?php echo esc_html( $row['name'] ); ?></a>
								<div class="small text-muted text-truncate"><?php echo esc_html( $row['email'] ); ?></div>
							</div>
							<div class="text-end small">
								<div><?php echo esc_html( $statuses[ $row['subscription_status'] ] ?? '' ); ?></div>
								<div class="text-danger fw-semibold"><?php echo esc_html( format_date( 'trialing' === $row['subscription_status'] ? $row['trial_ends_at'] : $row['subscription_ends_at'] ) ); ?></div>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</div>
</div>

<?php
require REPUVIO_ROOT . '/templates/app-footer.php';
