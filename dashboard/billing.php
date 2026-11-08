<?php
/**
 * Subscription & billing overview: plan, trial, quota, locked feedback, Starter / Pro / Enterprise
 * plans (monthly or yearly with 2 months free), universal features, the owner's plan requests and payment history.
 * Every plan request is stored in `subscription_requests` first, so it reaches the Super Admin even if the email fails.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$user = require_owner();

if ( is_post() && 'request_plan' === input_post( 'action' ) ) {
	$plan    = get_plan( (int) input_post( 'plan_id' ) );
	$cycle   = 'yearly' === input_post( 'cycle' ) ? 'yearly' : 'monthly';
	$missing = get_companies_missing_tax_id( (int) $user['id'] );

	if ( rate_limit_is_locked( 'plan_request' ) ) {
		flash( 'warning', 'Ai trimis deja mai multe cereri. Te contactăm în curând.' );
	} elseif ( is_showcase_user( $user ) ) {
		flash( 'info', 'Cont demo: comanda nu a fost trimisă. Într-un cont real cererea ajunge la noi, primești factura proformă pe email, iar abonamentul se activează după plată.' );
	} elseif ( 0 === count_user_companies( (int) $user['id'] ) ) {
		flash( 'warning', 'Pentru facturare adaugă mai întâi compania ta, cu codul fiscal (CUI / CIF).' );
	} elseif ( ! empty( $missing ) ) {
		flash( 'warning', 'Pentru facturare completează codul fiscal (CUI / CIF) la: ' . implode( ', ', array_column( $missing, 'name' ) ) . '.' );
	} elseif ( $plan && 1 === (int) $plan['is_active'] ) {
		rate_limit_hit( 'plan_request', 5, 3600, 3600 );

		$request_id = create_subscription_request( $user, $plan, $cycle );
		$details    = '<p style="margin-top:14px;">Solicită abonamentul <strong>' . esc_html( $plan['name'] ) . '</strong>, facturare <strong>' . esc_html( 'yearly' === $cycle ? 'anuală' : 'lunară' ) . '</strong> (' . esc_html( ( 1 === (int) $plan['price_is_starting'] ? 'de la ' : '' ) . format_ron( get_plan_price( $plan, $cycle ) ) ) . '). ID utilizator: ' . (int) $user['id'] . '.</p>'
			. render_billing_companies_html( (int) $user['id'] );
		$mail       = send_admin_notification(
			'Cerere abonament #' . $request_id . ': ' . $plan['name'],
			[
				'name'  => $user['name'],
				'email' => $user['email'],
			],
			$details,
			app_url( 'admin/subscriptions.php', [ 'tab' => 'requests' ] )
		);

		if ( $mail['success'] ) {
			db_query( 'UPDATE subscription_requests SET email_sent = 1 WHERE id = ?', [ $request_id ] );
		}

		audit_log( 'plan_requested', 'request=' . $request_id . ' plan=' . (int) $plan['id'] . ' cycle=' . $cycle, (int) $user['id'] );
		flash( 'success', 'Cererea pentru abonamentul ' . $plan['name'] . ' (' . ( 'yearly' === $cycle ? 'anual' : 'lunar' ) . ') a fost trimisă. Vei primi factura pe email; abonamentul se activează la confirmarea plății.' );
	}

	redirect( app_url( 'dashboard/billing.php' ) );
}

unlock_pending_reviews( (int) $user['id'] );
$user = current_user( true );

$limits       = get_user_limits( $user );
$usage        = get_quota_usage( $user );
$summary      = get_subscription_summary( $user );
$locked_count = count_locked_reviews( (int) $user['id'] );
$missing_tax  = get_companies_missing_tax_id( (int) $user['id'] );
$requests     = db_all( 'SELECT * FROM subscription_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 5', [ (int) $user['id'] ] );
$req_statuses = get_request_statuses();
$plans        = get_plans( true );
$payments     = db_all(
	'SELECT p.*, pl.name AS plan_name FROM payments p LEFT JOIN plans pl ON pl.id = p.plan_id WHERE p.user_id = ? ORDER BY p.created_at DESC LIMIT 24',
	[ (int) $user['id'] ]
);
$bank_details = get_setting( 'bank_details' );

$page_title       = 'Abonament';
$active_nav       = 'billing';
$page_breadcrumbs = [ [ 'Acasă', app_url( 'dashboard/index.php' ) ], [ 'Abonament', '' ] ];

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<div class="row g-3 mb-3">
	<div class="col-lg-6">
		<div class="rm-card rm-card-body h-100">
			<div class="d-flex justify-content-between align-items-start gap-2 mb-3">
				<div>
					<div class="small text-muted">Abonament curent</div>
					<div class="h4 mb-0"><?php echo esc_html( $limits['plan_name'] ); ?></div>
				</div>
				<span class="rm-pill <?php echo esc_attr( $summary['badge'] ); ?>"><?php echo esc_html( $summary['label'] ); ?></span>
			</div>
			<div class="small">
				<?php if ( ! empty( $summary['ends_at'] ) ) : ?>
					<div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted"><?php echo esc_html( 'trialing' === $user['subscription_status'] ? 'Perioada de probă se încheie' : 'Valabil până la' ); ?></span><span class="fw-semibold"><?php echo esc_html( format_date( $summary['ends_at'] ) ); ?><?php echo null !== $summary['days_left'] ? esc_html( ' (' . $summary['days_left'] . ' zile)' ) : ''; ?></span></div>
				<?php endif; ?>
				<?php if ( ! $limits['is_trial_limits'] ) : ?>
					<div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Facturare</span><span class="fw-semibold"><?php echo esc_html( get_billing_cycles()[ $user['billing_cycle'] ] ?? 'Lunar' ); ?></span></div>
				<?php endif; ?>
				<div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Recenzii colectate incluse / lună</span><span class="fw-semibold"><?php echo esc_html( $limits['unlimited_reviews'] ? 'Nelimitat' : format_number( $limits['max_reviews'] ) ); ?></span></div>
				<div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Locații / firme incluse</span><span class="fw-semibold"><?php echo esc_html( count_user_companies( (int) $user['id'] ) . ' / ' . $limits['max_companies'] ); ?></span></div>
				<div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Emailuri pentru alerte</span><span class="fw-semibold"><?php echo esc_html( $limits['max_alert_emails'] ); ?></span></div>
				<div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Istoric feedback</span><span class="fw-semibold"><?php echo esc_html( 0 === $limits['history_months'] ? 'Nelimitat' : 'Ultimele ' . $limits['history_months'] . ' luni' ); ?></span></div>
				<div class="d-flex justify-content-between py-1"><span class="text-muted">QR pe angajat / departament</span><span class="fw-semibold"><?php echo esc_html( $limits['allow_ref_tags'] ? 'Inclus' : 'Doar Pro / Enterprise' ); ?></span></div>
			</div>
		</div>
	</div>
	<div class="col-lg-6">
		<div class="rm-card rm-card-body h-100">
			<div class="small text-muted">Utilizare ciclu curent</div>
			<div class="h4 mb-2"><?php echo esc_html( format_quota_usage( $usage ) ); ?> <span class="fs-6 text-muted fw-normal">recenzii</span></div>
			<div class="progress rm-progress mb-2" role="progressbar" aria-valuenow="<?php echo esc_attr( $usage['percent'] ); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Cotă">
				<div class="progress-bar<?php echo esc_attr( 100 <= $usage['percent'] ? ' is-danger' : ( 80 <= $usage['percent'] ? ' is-warning' : '' ) ); ?>" data-width="<?php echo esc_attr( $usage['percent'] ); ?>"></div>
			</div>
			<div class="small text-muted">Ciclu: <?php echo esc_html( format_date( $usage['cycle_start'] ) . ' – ' . format_date( $usage['cycle_end'] ) ); ?></div>
			<div class="small text-muted mt-1"><i class="bi bi-info-circle"></i> O recenzie = orice evaluare completă cu stele pe pagina ta (redirecționare pe Google sau feedback privat). Simplele deschideri ale paginii sunt doar vizualizări și nu consumă cota.</div>
			<?php if ( 0 < $locked_count ) : ?>
				<div class="alert rm-alert-danger small mt-3 mb-0"><i class="bi bi-lock-fill"></i> <strong><?php echo esc_html( $locked_count ); ?></strong> feedback-uri negative blocate așteaptă deblocarea. Ele se deblochează automat după activarea unui abonament sau la începutul noului ciclu.</div>
			<?php endif; ?>
		</div>
	</div>
</div>

<?php if ( ! empty( $requests ) ) : ?>
	<div class="rm-card mb-3">
		<div class="rm-card-header"><h2 class="rm-card-title">Solicitările tale de abonament</h2></div>
		<div class="table-responsive">
			<table class="table rm-table">
				<thead><tr><th>#</th><th>Data</th><th>Abonament</th><th>Facturare</th><th class="text-end">Preț</th><th>Status</th></tr></thead>
				<tbody>
					<?php foreach ( $requests as $request ) : ?>
						<tr>
							<td class="fw-semibold">#<?php echo esc_html( (int) $request['id'] ); ?></td>
							<td class="small text-nowrap"><?php echo esc_html( format_datetime( $request['created_at'] ) ); ?></td>
							<td><?php echo esc_html( $request['plan_name'] ); ?></td>
							<td class="small"><?php echo esc_html( get_billing_cycles()[ $request['billing_cycle'] ] ?? '' ); ?></td>
							<td class="text-end text-nowrap"><?php echo esc_html( format_ron( $request['price_ron'] ) ); ?></td>
							<td><span class="rm-pill <?php echo esc_attr( $req_statuses[ $request['status'] ][1] ?? 'rm-pill-muted' ); ?>"><?php echo esc_html( $req_statuses[ $request['status'] ][0] ?? $request['status'] ); ?></span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
<?php endif; ?>

<?php if ( ! empty( $missing_tax ) ) : ?>
	<div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2">
		<div><i class="bi bi-receipt"></i> <strong>Lipsește codul fiscal (CUI / CIF)</strong>, necesar pentru facturare, la: <?php echo esc_html( implode( ', ', array_column( $missing_tax, 'name' ) ) ); ?>.</div>
		<a class="btn btn-sm btn-dark" href="<?php echo esc_url( app_url( 'dashboard/company-edit.php', [ 'id' => (int) $missing_tax[0]['id'] ] ) ); ?>">Completează acum</a>
	</div>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
	<div>
		<h2 class="h5 mb-1">Alege abonamentul</h2>
		<p class="text-muted small mb-0">Plata anuală include <strong>2 luni gratuite</strong>.</p>
	</div>
	<div class="rm-segmented" role="radiogroup" aria-label="Perioadă de facturare">
		<label><input type="radio" name="billing_view" value="monthly" data-cycle-toggle checked><span>Lunar</span></label>
		<label><input type="radio" name="billing_view" value="yearly" data-cycle-toggle><span>Anual <em>-2 luni</em></span></label>
	</div>
</div>
<div class="row g-3 mb-3">
	<?php foreach ( $plans as $plan ) : ?>
		<?php
		$is_current = (int) $plan['id'] === (int) $user['plan_id'] && 'active' === $user['subscription_status'];
		$from       = 1 === (int) $plan['price_is_starting'] ? 'de la ' : '';
		$monthly    = get_plan_price( $plan, 'monthly' );
		$yearly     = get_plan_price( $plan, 'yearly' );
		?>
		<div class="col-md-6 col-xl-4">
			<div class="rm-card rm-card-body rm-plan-card<?php echo $is_current ? ' is-current' : ''; ?>">
				<div class="d-flex justify-content-between align-items-center">
					<h3 class="h5 mb-0"><?php echo esc_html( $plan['name'] ); ?></h3>
					<?php if ( $is_current ) : ?>
						<span class="rm-pill rm-pill-positive">Curent</span>
					<?php elseif ( 1 === (int) $plan['allow_ref_tags'] && 0 === (int) $plan['includes_onboarding'] ) : ?>
						<span class="rm-pill rm-pill-neutral">Popular</span>
					<?php endif; ?>
				</div>
				<div class="mt-3" data-cycle-show="monthly">
					<span class="small text-muted"><?php echo esc_html( ucfirst( $from ) ); ?></span><span class="h3 fw-bold text-dark"><?php echo esc_html( format_number( $monthly, 0 ) ); ?></span> <span class="text-muted">RON / lună</span>
					<div class="small text-muted mb-3">facturat lunar<?php echo 1 === (int) $plan['price_is_starting'] ? ' · negociabil' : ''; ?></div>
				</div>
				<div class="mt-3 d-none" data-cycle-show="yearly">
					<span class="small text-muted"><?php echo esc_html( ucfirst( $from ) ); ?></span><span class="h3 fw-bold text-dark"><?php echo esc_html( format_number( $yearly, 0 ) ); ?></span> <span class="text-muted">RON / an</span>
					<div class="small text-muted mb-3">echivalent <?php echo esc_html( format_number( $yearly / 12, 0 ) ); ?> RON / lună · economisești <?php echo esc_html( format_number( max( 0, $monthly * 12 - $yearly ), 0 ) ); ?> RON<?php echo 1 === (int) $plan['price_is_starting'] ? ' · negociabil' : ''; ?></div>
				</div>
				<p class="small text-muted mb-2"><?php echo esc_html( $plan['description'] ); ?></p>
				<ul class="list-unstyled small mb-3">
					<li class="mb-1"><i class="bi bi-check2 text-success"></i> <strong><?php echo esc_html( format_number( $plan['max_reviews_per_month'] ) . ( 1 === (int) $plan['price_is_starting'] ? '+' : '' ) ); ?></strong> recenzii colectate / lună</li>
					<li class="mb-1"><i class="bi bi-check2 text-success"></i> <?php echo esc_html( 1 === (int) $plan['max_companies'] ? '1 locație / firmă' : ( 'Până la ' . $plan['max_companies'] . ( 1 === (int) $plan['price_is_starting'] ? '+' : '' ) . ' locații / firme' ) ); ?></li>
					<li class="mb-1"><i class="bi bi-check2 text-success"></i> <?php echo esc_html( $plan['max_alert_emails'] . ' ' . ( 1 === (int) $plan['max_alert_emails'] ? 'adresă' : 'adrese' ) . ' email pentru alerte' ); ?></li>
					<li class="mb-1"><i class="bi bi-check2 text-success"></i> <?php echo esc_html( 0 === (int) $plan['history_months'] ? 'Istoric feedback nelimitat' : 'Istoric feedback ' . $plan['history_months'] . ' luni' ); ?></li>
					<?php if ( 1 === (int) $plan['allow_ref_tags'] ) : ?>
						<li class="mb-1"><i class="bi bi-star-fill text-warning"></i> <strong>Coduri QR multiple</strong> pe angajat / departament / masă</li>
					<?php else : ?>
						<li class="mb-1 text-muted"><i class="bi bi-x"></i> Coduri QR pe angajat (doar Pro / Enterprise)</li>
					<?php endif; ?>
					<?php if ( 1 === (int) $plan['includes_onboarding'] ) : ?>
						<li class="mb-1"><i class="bi bi-check2 text-success"></i> Onboarding asistat</li>
					<?php endif; ?>
				</ul>
				<?php if ( '' !== $plan['target_description'] ) : ?>
					<p class="small text-muted border-top pt-2"><i class="bi bi-bullseye"></i> <?php echo esc_html( $plan['target_description'] ); ?></p>
				<?php endif; ?>
				<button type="button" class="btn <?php echo esc_attr( $is_current ? 'btn-light' : 'btn-dark' ); ?> w-100 mt-auto" data-bs-toggle="modal" data-bs-target="#rmPlanModal"
					data-plan-id="<?php echo esc_attr( $plan['id'] ); ?>"
					data-plan-name="<?php echo esc_attr( $plan['name'] ); ?>"
					data-price-monthly="<?php echo esc_attr( ucfirst( $from ) . format_ron( $monthly ) . ' / lună' ); ?>"
					data-price-yearly="<?php echo esc_attr( ucfirst( $from ) . format_ron( $yearly ) . ' / an' ); ?>">
					<i class="bi bi-bag-check"></i> <?php echo esc_html( $is_current ? 'Reînnoiește abonamentul' : 'Comandă abonamentul' ); ?>
				</button>
			</div>
		</div>
	<?php endforeach; ?>
</div>

<div class="modal fade" id="rmPlanModal" tabindex="-1" aria-labelledby="rmPlanModalTitle" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered">
		<form method="post" class="modal-content rm-modal">
			<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<input type="hidden" name="action" value="request_plan">
			<input type="hidden" name="plan_id" value="" data-modal-plan-id>
			<input type="hidden" name="cycle" value="monthly" data-modal-cycle>
			<div class="modal-header">
				<h2 class="modal-title h5" id="rmPlanModalTitle">Confirmă comanda</h2>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
			</div>
			<div class="modal-body">
				<div class="rm-order-summary">
					<div class="d-flex justify-content-between py-2 border-bottom"><span class="text-muted">Abonament</span><strong data-modal-plan-name></strong></div>
					<div class="d-flex justify-content-between py-2 border-bottom"><span class="text-muted">Facturare</span><strong data-modal-cycle-label></strong></div>
					<div class="d-flex justify-content-between py-2"><span class="text-muted">Preț</span><strong class="text-dark" data-modal-price></strong></div>
				</div>
				<p class="small text-muted mt-3 mb-0"><i class="bi bi-info-circle"></i> Nu se face nicio plată acum. Primești pe email factura proformă, iar abonamentul se activează imediat după confirmarea plății prin transfer bancar.</p>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-light" data-bs-dismiss="modal">Renunță</button>
				<button type="submit" class="btn btn-dark"><i class="bi bi-check2-circle"></i> Confirmă comanda</button>
			</div>
		</form>
	</div>
</div>

<div class="rm-included mb-4">
	<div class="rm-included-head">
		<span class="rm-included-icon"><i class="bi bi-stars"></i></span>
		<div>
			<div class="fw-semibold text-dark">Incluse în toate abonamentele</div>
			<div class="small text-muted">Fără costuri suplimentare, inclusiv în perioada de probă.</div>
		</div>
	</div>
	<div class="row g-3">
		<?php
		$included = [
			[ 'bi-eye-slash', 'White-label', 'Ascunzi „Protejat de ' . app_name() . '” de pe pagina publică de evaluare.' ],
			[ 'bi-file-earmark-bar-graph', 'Export CSV & raport ROI', 'Exporți feedback-ul oricând și primești lunar valoarea generată.' ],
			[ 'bi-printer', 'Materiale print brănduite', 'Afiș A4, flyer A5, cărți de vizită și stickere cu codul tău QR.' ],
			[ 'bi-window-stack', 'Widget, semnătură & operatori', 'Insignă pentru site, semnătură email și echipă de suport.' ],
		];
		?>
		<?php foreach ( $included as $feature ) : ?>
			<div class="col-sm-6 col-xl-3">
				<div class="rm-included-item">
					<i class="bi <?php echo esc_attr( $feature[0] ); ?>"></i>
					<div>
						<div class="fw-semibold text-dark small"><?php echo esc_html( $feature[1] ); ?></div>
						<div class="small text-muted"><?php echo esc_html( $feature[2] ); ?></div>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
</div>

<div class="row g-3">
	<div class="col-lg-5">
		<div class="rm-card h-100">
			<div class="rm-card-header"><h2 class="rm-card-title">Plată prin transfer bancar</h2></div>
			<div class="rm-card-body small">
				<p class="text-muted">După solicitarea abonamentului primești factura proformă. Abonamentul este activat imediat după confirmarea plății.</p>
				<div class="p-3 rounded-3 border bg-light-subtle"><?php echo nl2br( esc_html( $bank_details ) ); ?></div>
				<?php if ( '' !== get_setting( 'support_email' ) ) : ?>
					<p class="mt-3 mb-0">Întrebări? <a href="<?php echo esc_url( 'mailto:' . get_setting( 'support_email' ) ); ?>"><?php echo esc_html( get_setting( 'support_email' ) ); ?></a></p>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<div class="col-lg-7">
		<div class="rm-card h-100">
			<div class="rm-card-header"><h2 class="rm-card-title">Istoric plăți</h2></div>
			<?php if ( empty( $payments ) ) : ?>
				<div class="rm-empty"><i class="bi bi-receipt"></i><p class="mt-2 mb-0">Nicio plată înregistrată.</p></div>
			<?php else : ?>
				<div class="table-responsive">
					<table class="table rm-table">
						<thead><tr><th>Data</th><th>Abonament</th><th>Perioadă</th><th>Referință</th><th class="text-end">Sumă</th></tr></thead>
						<tbody>
							<?php foreach ( $payments as $payment ) : ?>
								<tr>
									<td class="small text-nowrap"><?php echo esc_html( format_date( $payment['created_at'] ) ); ?></td>
									<td><?php echo esc_html( $payment['plan_name'] ?? '—' ); ?></td>
									<td class="small text-nowrap"><?php echo esc_html( format_date( $payment['period_start'] ) . ' – ' . format_date( $payment['period_end'] ) ); ?></td>
									<td class="small"><?php echo esc_html( '' !== $payment['reference'] ? $payment['reference'] : '—' ); ?></td>
									<td class="text-end fw-semibold text-nowrap"><?php echo esc_html( format_ron( $payment['amount_ron'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>

<?php
require REPUVIO_ROOT . '/templates/app-footer.php';
