<?php
/**
 * Super Admin: "Trimite Raport Lunar ROI (Test / Manual)". Sends the ROI / Value Generated email
 * immediately to one business owner or to all of them, for the previous month (default),
 * the current month or a custom date range. Also previews the aggregated numbers.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$admin   = require_super_admin();
$owners  = db_all( "SELECT id, name, email, status, roi_report_enabled, last_roi_report_period FROM users WHERE role = 'user' ORDER BY name ASC" );
$types   = get_report_period_types();
$errors  = [];
$results = [];
$form    = [
	'target'    => 'all',
	'period'    => 'previous_month',
	'date_from' => date( 'Y-m-01', strtotime( 'first day of last month' ) ),
	'date_to'   => date( 'Y-m-t', strtotime( 'first day of last month' ) ),
];

if ( is_post() && 'send' === input_post( 'action' ) ) {
	$form['target']    = 'all' === input_post( 'target' ) ? 'all' : (string) sanitize_int_range( input_post( 'target' ), 0, PHP_INT_MAX, 0 );
	$form['period']    = array_key_exists( input_post( 'period' ), $types ) ? input_post( 'period' ) : 'previous_month';
	$form['date_from'] = sanitize_date( input_post( 'date_from' ) );
	$form['date_to']   = sanitize_date( input_post( 'date_to' ) );
	$period            = get_report_period( $form['period'], $form['date_from'], $form['date_to'] );

	if ( null === $period ) {
		$errors[] = 'Intervalul personalizat nu este valid (maxim 366 de zile, data de final după cea de început).';
	}

	if ( 'all' === $form['target'] ) {
		$recipients = db_all( "SELECT * FROM users WHERE role = 'user' AND status = 'active' AND EXISTS (SELECT 1 FROM companies c WHERE c.user_id = users.id) ORDER BY name ASC" );
	} else {
		$target     = get_user_by_id( (int) $form['target'] );
		$recipients = $target && 'user' === $target['role'] ? [ $target ] : [];

		if ( empty( $recipients ) ) {
			$errors[] = 'Alege un Business Owner valid.';
		}
	}

	if ( rate_limit_is_locked( 'roi_manual' ) ) {
		$errors[] = 'Prea multe trimiteri manuale. Așteaptă 10 minute.';
	}

	if ( empty( $errors ) ) {
		rate_limit_hit( 'roi_manual', 20, 600, 600 );

		foreach ( $recipients as $recipient ) {
			$result    = send_roi_report_email( $recipient, $period, [ 'force' => true ] );
			$results[] = [
				'name'    => $recipient['name'],
				'email'   => $recipient['email'],
				'success' => $result['success'],
				'error'   => $result['error'],
				'data'    => get_roi_report_data( $recipient, $period['from'], $period['to'] )['totals'],
			];
		}

		audit_log( 'admin_roi_report_sent', 'target=' . $form['target'] . ' period=' . $form['period'] . ' count=' . count( $results ) );
	}
}

$page_title       = 'Rapoarte ROI';
$active_nav       = 'roi';
$page_breadcrumbs = [ [ 'Super Admin', app_url( 'admin/index.php' ) ], [ 'Rapoarte ROI', '' ] ];

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
	<div class="col-xl-5">
		<form method="post" class="rm-card" novalidate>
			<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<input type="hidden" name="action" value="send">
			<div class="rm-card-header"><h2 class="rm-card-title"><i class="bi bi-send"></i> Trimite Raport Lunar ROI (Test / Manual)</h2></div>
			<div class="rm-card-body">
				<div class="mb-3">
					<label class="form-label" for="target">Destinatar</label>
					<select class="form-select" id="target" name="target">
						<option value="all"<?php echo 'all' === $form['target'] ? ' selected' : ''; ?>>Toți Business Owners activi (cu cel puțin o companie)</option>
						<?php foreach ( $owners as $owner ) : ?>
							<option value="<?php echo esc_attr( $owner['id'] ); ?>"<?php echo (string) $owner['id'] === $form['target'] ? ' selected' : ''; ?>><?php echo esc_html( $owner['name'] . ' — ' . $owner['email'] . ( 'active' !== $owner['status'] ? ' (suspendat)' : '' ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="mb-3">
					<label class="form-label" for="period">Perioadă</label>
					<select class="form-select" id="period" name="period" data-toggle-custom="#customRange">
						<?php foreach ( $types as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"<?php echo $key === $form['period'] ? ' selected' : ''; ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="row g-2 mb-3<?php echo 'custom' === $form['period'] ? '' : ' d-none'; ?>" id="customRange">
					<div class="col-6">
						<label class="form-label small" for="date_from">De la</label>
						<input type="date" class="form-control form-control-sm" id="date_from" name="date_from" value="<?php echo esc_attr( $form['date_from'] ); ?>">
					</div>
					<div class="col-6">
						<label class="form-label small" for="date_to">Până la</label>
						<input type="date" class="form-control form-control-sm" id="date_to" name="date_to" value="<?php echo esc_attr( $form['date_to'] ); ?>">
					</div>
				</div>
				<div class="small text-muted">Trimiterea manuală nu modifică marcajul lunar folosit de cron, deci clientul primește în continuare raportul automat la începutul lunii.</div>
			</div>
			<div class="rm-card-body border-top">
				<button type="submit" class="btn btn-dark"><i class="bi bi-envelope-check"></i> Trimite Email ROI Acum</button>
			</div>
		</form>

		<div class="rm-card rm-card-body mt-3 small">
			<div class="fw-semibold text-dark mb-1">Trimitere automată</div>
			<div class="text-muted">Status: <strong><?php echo esc_html( is_email_template_enabled( 'monthly_roi_report' ) ? 'activă' : 'oprită' ); ?></strong> · rulează din <code>cron.php</code> la începutul fiecărei luni, pentru luna precedentă. Textul și comutatorul ON/OFF: <a href="<?php echo esc_url( app_url( 'admin/emails.php', [ 'tab' => 'templates', 'edit' => 'monthly_roi_report' ] ) ); ?>">Email & Șabloane → Raport lunar ROI</a>. Trimiterea manuală de aici funcționează și când șablonul este oprit.</div>
		</div>
	</div>

	<div class="col-xl-7">
		<?php if ( ! empty( $results ) ) : ?>
			<div class="rm-card mb-3">
				<div class="rm-card-header"><h2 class="rm-card-title">Rezultat trimitere (<?php echo esc_html( count( $results ) ); ?>)</h2></div>
				<div class="table-responsive">
					<table class="table rm-table">
						<thead><tr><th>Destinatar</th><th class="text-end">Vizualizări</th><th class="text-end">Google</th><th class="text-end">Interceptate</th><th>Status</th></tr></thead>
						<tbody>
							<?php foreach ( $results as $result ) : ?>
								<tr>
									<td><div class="fw-medium"><?php echo esc_html( $result['name'] ); ?></div><div class="small text-muted"><?php echo esc_html( $result['email'] ); ?></div></td>
									<td class="text-end"><?php echo esc_html( format_number( $result['data']['views'] ) ); ?></td>
									<td class="text-end text-success fw-semibold"><?php echo esc_html( format_number( $result['data']['redirects'] ) ); ?></td>
									<td class="text-end text-danger"><?php echo esc_html( format_number( $result['data']['feedback'] ) ); ?></td>
									<td><span class="rm-pill <?php echo esc_attr( $result['success'] ? 'rm-pill-positive' : 'rm-pill-negative' ); ?>" title="<?php echo esc_attr( $result['error'] ); ?>"><?php echo esc_html( $result['success'] ? 'Trimis' : 'Eșuat' ); ?></span></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		<?php endif; ?>

		<div class="rm-card">
			<div class="rm-card-header"><h2 class="rm-card-title">Business Owners</h2></div>
			<div class="table-responsive">
				<table class="table rm-table">
					<thead><tr><th>Utilizator</th><th>Raport automat</th><th>Ultima lună trimisă</th></tr></thead>
					<tbody>
						<?php foreach ( $owners as $owner ) : ?>
							<tr>
								<td><a class="fw-medium" href="<?php echo esc_url( app_url( 'admin/user-edit.php', [ 'id' => (int) $owner['id'] ] ) ); ?>"><?php echo esc_html( $owner['name'] ); ?></a><div class="small text-muted"><?php echo esc_html( $owner['email'] ); ?></div></td>
								<td><span class="rm-pill <?php echo esc_attr( 1 === (int) $owner['roi_report_enabled'] ? 'rm-pill-positive' : 'rm-pill-muted' ); ?>"><?php echo esc_html( 1 === (int) $owner['roi_report_enabled'] ? 'Abonat' : 'Dezabonat' ); ?></span></td>
								<td class="small text-muted"><?php echo esc_html( $owner['last_roi_report_period'] ?? '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
						<?php if ( empty( $owners ) ) : ?>
							<tr><td colspan="3" class="text-center text-muted py-4">Niciun Business Owner.</td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</div>

<?php
require REPUVIO_ROOT . '/templates/app-footer.php';
