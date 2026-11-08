<?php
/**
 * Super Admin: subscription plan list (delete only when unused, otherwise deactivate).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

require_super_admin();

if ( is_post() && 'delete' === input_post( 'action' ) ) {
	$plan = get_plan( (int) input_post( 'plan_id' ) );

	if ( $plan ) {
		$users_on_plan = (int) db_value( 'SELECT COUNT(*) FROM users WHERE plan_id = ?', [ (int) $plan['id'] ] );

		if ( 0 < $users_on_plan ) {
			db_query( 'UPDATE plans SET is_active = 0 WHERE id = ?', [ (int) $plan['id'] ] );
			flash( 'warning', 'Planul are ' . $users_on_plan . ' utilizatori și a fost doar dezactivat.' );
		} else {
			db_query( 'DELETE FROM plans WHERE id = ?', [ (int) $plan['id'] ] );
			flash( 'success', 'Planul a fost șters.' );
		}

		audit_log( 'admin_plan_deleted', 'plan=' . (int) $plan['id'] );
	}

	redirect( app_url( 'admin/plans.php' ) );
}

$plans = db_all(
	'SELECT p.*, (SELECT COUNT(*) FROM users u WHERE u.plan_id = p.id) AS users_count FROM plans p ORDER BY p.sort_order ASC, p.price_ron ASC'
);

$page_title       = 'Planuri tarifare';
$active_nav       = 'plans';
$page_breadcrumbs = [ [ 'Super Admin', app_url( 'admin/index.php' ) ], [ 'Planuri tarifare', '' ] ];

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
	<p class="text-muted mb-0">Planurile active sunt afișate clienților în pagina Abonament.</p>
	<a class="btn btn-dark" href="<?php echo esc_url( app_url( 'admin/plan-edit.php' ) ); ?>"><i class="bi bi-plus-lg"></i> Plan nou</a>
</div>

<div class="rm-card">
	<div class="table-responsive">
		<table class="table rm-table">
			<thead><tr><th>Plan</th><th>Preț lunar</th><th>Preț anual</th><th>Recenzii / lună</th><th>Locații</th><th>QR angajați</th><th>Utilizatori</th><th>Stare</th><th></th></tr></thead>
			<tbody>
				<?php foreach ( $plans as $plan ) : ?>
					<tr>
						<td><div class="fw-medium"><?php echo esc_html( $plan['name'] ); ?></div><div class="small text-muted"><?php echo esc_html( $plan['slug'] ); ?></div></td>
						<td class="text-nowrap fw-semibold"><?php echo esc_html( ( 1 === (int) $plan['price_is_starting'] ? 'de la ' : '' ) . format_ron( $plan['price_ron'] ) ); ?></td>
						<td class="text-nowrap small"><?php echo esc_html( format_ron( get_plan_price( $plan, 'yearly' ) ) ); ?></td>
						<td><?php echo esc_html( format_number( $plan['max_reviews_per_month'] ) ); ?></td>
						<td><?php echo esc_html( $plan['max_companies'] ); ?></td>
						<td><?php echo esc_html( 1 === (int) $plan['allow_ref_tags'] ? 'Da' : '—' ); ?></td>
						<td><?php echo esc_html( $plan['users_count'] ); ?></td>
						<td><span class="rm-pill <?php echo esc_attr( 1 === (int) $plan['is_active'] ? 'rm-pill-positive' : 'rm-pill-muted' ); ?>"><?php echo esc_html( 1 === (int) $plan['is_active'] ? 'Activ' : 'Inactiv' ); ?></span></td>
						<td class="text-end text-nowrap">
							<a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'admin/plan-edit.php', [ 'id' => (int) $plan['id'] ] ) ); ?>"><i class="bi bi-pencil"></i></a>
							<form method="post" class="d-inline" data-confirm="Ștergi acest plan? Dacă are utilizatori va fi doar dezactivat.">
								<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<input type="hidden" name="action" value="delete">
								<input type="hidden" name="plan_id" value="<?php echo esc_attr( $plan['id'] ); ?>">
								<button type="submit" class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ( empty( $plans ) ) : ?>
					<tr><td colspan="9" class="text-center text-muted py-4">Niciun plan definit.</td></tr>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>

<?php
require REPUVIO_ROOT . '/templates/app-footer.php';
