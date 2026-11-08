<?php
/**
 * Super Admin: all manually logged payments with date filters and totals.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

require_super_admin();

$date_from = sanitize_date( input_get( 'date_from' ) );
$date_to   = sanitize_date( input_get( 'date_to' ) );
$where     = [ '1 = 1' ];
$params    = [];

if ( '' !== $date_from ) {
	$where[]             = 'p.created_at >= :date_from';
	$params['date_from'] = $date_from . ' 00:00:00';
}
if ( '' !== $date_to ) {
	$where[]           = 'p.created_at <= :date_to';
	$params['date_to'] = $date_to . ' 23:59:59';
}

$where_sql  = implode( ' AND ', $where );
$total      = (int) db_value( 'SELECT COUNT(*) FROM payments p WHERE ' . $where_sql, $params );
$sum        = (float) db_value( 'SELECT COALESCE(SUM(p.amount_ron), 0) FROM payments p WHERE ' . $where_sql, $params );
$pagination = paginate( $total, 30, (int) input_get( 'page', '1' ) );

$list_params           = $params;
$list_params['limit']  = $pagination['per_page'];
$list_params['offset'] = $pagination['offset'];

$payments = db_all(
	'SELECT p.*, u.name AS user_name, u.email AS user_email, pl.name AS plan_name, a.name AS recorded_by_name
	FROM payments p
	INNER JOIN users u ON u.id = p.user_id
	LEFT JOIN plans pl ON pl.id = p.plan_id
	LEFT JOIN users a ON a.id = p.recorded_by
	WHERE ' . $where_sql . '
	ORDER BY p.created_at DESC
	LIMIT :limit OFFSET :offset',
	$list_params
);

$page_title       = 'Plăți';
$active_nav       = 'payments';
$page_breadcrumbs = [ [ 'Super Admin', app_url( 'admin/index.php' ) ], [ 'Plăți', '' ] ];

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<div class="row g-3 mb-3">
	<div class="col-md-8">
		<div class="rm-card rm-card-body h-100">
			<form method="get" class="row g-2 align-items-end">
				<div class="col-sm-4">
					<label class="form-label small" for="date_from">De la</label>
					<input type="date" class="form-control form-control-sm" id="date_from" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
				</div>
				<div class="col-sm-4">
					<label class="form-label small" for="date_to">Până la</label>
					<input type="date" class="form-control form-control-sm" id="date_to" name="date_to" value="<?php echo esc_attr( $date_to ); ?>">
				</div>
				<div class="col-sm-4 d-flex gap-2">
					<button type="submit" class="btn btn-sm btn-dark flex-grow-1">Filtrează</button>
					<a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'admin/payments.php' ) ); ?>">Reset</a>
				</div>
			</form>
		</div>
	</div>
	<div class="col-md-4">
		<div class="rm-card rm-card-body h-100">
			<div class="rm-kpi-label"><span class="rm-kpi-icon"><i class="bi bi-cash-coin"></i></span> Total încasat (filtrat)</div>
			<div class="rm-kpi-value fs-3"><?php echo esc_html( format_ron( $sum ) ); ?></div>
			<div class="small text-muted"><?php echo esc_html( $total ); ?> plăți</div>
		</div>
	</div>
</div>

<div class="rm-card">
	<div class="table-responsive">
		<table class="table rm-table">
			<thead><tr><th>Data</th><th>Client</th><th>Plan</th><th>Perioadă</th><th>Metodă</th><th>Referință</th><th>Înregistrat de</th><th class="text-end">Sumă</th></tr></thead>
			<tbody>
				<?php foreach ( $payments as $payment ) : ?>
					<tr>
						<td class="small text-nowrap"><?php echo esc_html( format_datetime( $payment['created_at'] ) ); ?></td>
						<td><a href="<?php echo esc_url( app_url( 'admin/user-edit.php', [ 'id' => (int) $payment['user_id'] ] ) ); ?>"><?php echo esc_html( $payment['user_name'] ); ?></a><div class="small text-muted"><?php echo esc_html( $payment['user_email'] ); ?></div></td>
						<td class="small"><?php echo esc_html( $payment['plan_name'] ?? '—' ); ?></td>
						<td class="small text-nowrap"><?php echo esc_html( format_date( $payment['period_start'] ) . ' – ' . format_date( $payment['period_end'] ) ); ?></td>
						<td class="small"><?php echo esc_html( $payment['method'] ); ?></td>
						<td class="small"><?php echo esc_html( $payment['reference'] ); ?></td>
						<td class="small text-muted"><?php echo esc_html( $payment['recorded_by_name'] ?? '—' ); ?></td>
						<td class="text-end fw-semibold text-nowrap"><?php echo esc_html( format_ron( $payment['amount_ron'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( empty( $payments ) ) : ?>
					<tr><td colspan="8" class="text-center text-muted py-4">Nicio plată în intervalul selectat.</td></tr>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
	<div class="rm-card-body border-top d-flex justify-content-end">
		<?php
		render_pagination(
			$pagination,
			'admin/payments.php',
			array_filter(
				[
					'date_from' => $date_from,
					'date_to'   => $date_to,
				]
			)
		);
		?>
	</div>
</div>

<?php
require REPUVIO_ROOT . '/templates/app-footer.php';
