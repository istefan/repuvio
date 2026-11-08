<?php
/**
 * Analytics dashboard for business owners and operators (operators see only assigned companies).
 * Owners also get the "Checklist de Activare Rapidă" until all 4 milestones are done or it is hidden;
 * owners without a company are sent to the onboarding wizard.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$user        = require_panel_access();
$is_owner    = 'user' === $user['role'];
$owner_id    = get_account_owner_id( $user );

// New business owners without a company start with the 3-step wizard (once skipped, not again this session).
if ( should_start_onboarding( $user ) ) {
	redirect( app_url( 'dashboard/onboarding.php' ) );
}

unlock_pending_reviews( $owner_id );
$user  = current_user( true );
$owner = get_account_owner( $user );

$company_id   = get_current_company_id();
$companies    = get_accessible_companies( $user );
$stats        = get_dashboard_stats( $owner_id, $company_id, 30 );
$series       = get_daily_series( $owner_id, $company_id, 30 );
$distribution = get_star_distribution( $owner_id, $company_id, 30 );
$recent       = get_recent_reviews( $owner_id, $company_id, 8 );
$usage        = get_quota_usage( $owner );
$locked_count = count_locked_reviews( $owner_id );
$summary      = get_subscription_summary( $owner );
$actions      = get_review_actions();
$can_tag      = user_can_use_ref_tags( $owner );
$tag_rows     = $can_tag ? get_ref_tag_breakdown( $owner_id, $company_id, 30 ) : [];
$checklist    = $is_owner ? get_onboarding_checklist( $owner ) : null;

/**
 * Renders a trend pill.
 *
 * @param int  $trend         Percent change.
 * @param bool $higher_is_bad Invert colours (e.g. negative feedback).
 * @return void
 */
function render_trend_pill( $trend, $higher_is_bad = false ) {
	$positive = $higher_is_bad ? 0 > $trend : 0 < $trend;
	$class    = 0 === $trend ? 'rm-pill-muted' : ( $positive ? 'rm-pill-positive' : 'rm-pill-negative' );
	$icon     = 0 === $trend ? 'bi-dash' : ( 0 < $trend ? 'bi-arrow-up-right' : 'bi-arrow-down-right' );

	echo '<span class="rm-pill ' . esc_attr( $class ) . '"><i class="bi ' . esc_attr( $icon ) . '"></i> ' . esc_html( ( 0 < $trend ? '+' : '' ) . $trend . '%' ) . '</span>';
}

$page_title       = 'Panou';
$active_nav       = 'dashboard';
$page_breadcrumbs = [ [ 'Acasă', '' ] ];
$page_scripts     = [ 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', asset_url( 'js/dashboard.js' ) ];
$page_inline_data = [
	'labels'       => $series['labels'],
	'views'        => $series['views'],
	'redirects'    => $series['redirects'],
	'feedback'     => $series['feedback'],
	'distribution' => $distribution,
];

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<?php if ( empty( $companies ) && ! $is_owner ) : ?>
	<div class="alert rm-alert-info"><i class="bi bi-info-circle"></i> Nu ai încă nicio companie alocată. Cere administratorului firmei să îți aloce companiile pe care le gestionezi.</div>
<?php elseif ( empty( $companies ) ) : ?>
	<div class="rm-card mb-4">
		<div class="rm-card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
			<div>
				<h2 class="h5 mb-1">Începe prin a adăuga prima ta companie</h2>
				<p class="text-muted mb-0">Configurează linkul Google Reviews și generează codurile QR pentru clienți.</p>
			</div>
			<a href="<?php echo esc_url( app_url( 'dashboard/company-edit.php' ) ); ?>" class="btn btn-dark"><i class="bi bi-plus-lg"></i> Adaugă companie</a>
		</div>
	</div>
<?php endif; ?>

<?php if ( 0 < $locked_count ) : ?>
	<div class="alert rm-alert-danger d-flex flex-wrap align-items-center justify-content-between gap-2">
		<div><i class="bi bi-lock-fill me-1"></i> Există <strong><?php echo esc_html( $locked_count ); ?></strong> feedback-uri negative blocate. <?php echo esc_html( $is_owner ? 'Fă upgrade sau reînnoiește abonamentul pentru a le citi.' : 'Administratorul firmei trebuie să reînnoiască abonamentul.' ); ?></div>
		<?php if ( $is_owner ) : ?>
			<a class="btn btn-sm btn-dark" href="<?php echo esc_url( app_url( 'dashboard/billing.php' ) ); ?>">Deblochează</a>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php if ( $checklist && $checklist['visible'] ) : ?>
	<section class="rm-checklist mb-3">
		<div class="rm-card-body pb-2">
			<div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
				<div>
					<h2 class="h5 mb-1"><i class="bi bi-rocket-takeoff"></i> Checklist de Activare Rapidă</h2>
					<div class="small text-muted"><?php echo esc_html( $checklist['done'] . ' din ' . $checklist['total'] . ' pași completați · ' . $checklist['percent'] . '%' ); ?></div>
				</div>
				<form method="post" action="<?php echo esc_url( app_url( 'dashboard/onboarding.php' ) ); ?>">
					<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<input type="hidden" name="action" value="dismiss_checklist">
					<button type="submit" class="btn btn-sm btn-light"><i class="bi bi-eye-slash"></i> Ascunde ghidul</button>
				</form>
			</div>
			<div class="progress rm-progress my-3" role="progressbar" aria-label="Progres activare" aria-valuenow="<?php echo esc_attr( $checklist['percent'] ); ?>" aria-valuemin="0" aria-valuemax="100">
				<div class="progress-bar" data-width="<?php echo esc_attr( $checklist['percent'] ); ?>"></div>
			</div>
			<?php foreach ( $checklist['items'] as $item ) : ?>
				<div class="rm-checklist-item<?php echo $item['done'] ? ' is-done' : ''; ?>">
					<span class="rm-checklist-check"><?php if ( $item['done'] ) : ?><i class="bi bi-check-lg"></i><?php endif; ?></span>
					<div class="flex-grow-1 min-w-0">
						<div class="rm-checklist-title fw-semibold text-dark"><?php echo esc_html( $item['title'] ); ?></div>
						<div class="small text-muted"><?php echo esc_html( $item['text'] ); ?></div>
					</div>
					<?php if ( ! $item['done'] ) : ?>
						<div class="d-flex flex-wrap gap-1 justify-content-end">
							<?php if ( $item['action'] ) : ?>
								<a class="btn btn-sm btn-dark text-nowrap" href="<?php echo esc_url( $item['action'][1] ); ?>"<?php echo $item['action'][2] ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html( $item['action'][0] ); ?></a>
							<?php endif; ?>
							<?php if ( 'materials' === $item['key'] ) : ?>
								<form method="post" action="<?php echo esc_url( app_url( 'dashboard/onboarding.php' ) ); ?>">
									<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<input type="hidden" name="action" value="mark_materials">
									<button type="submit" class="btn btn-sm btn-light text-nowrap">Marchează ca finalizat</button>
								</form>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>

<div class="row g-3 mb-3">
	<div class="col-sm-6 col-xl-3">
		<div class="rm-card rm-card-body h-100">
			<div class="rm-kpi-label"><span class="rm-kpi-icon"><i class="bi bi-qr-code-scan"></i></span> Vizualizări funnel (scanări QR)</div>
			<div class="rm-kpi-value"><?php echo esc_html( format_number( $stats['views'] ) ); ?></div>
			<div class="d-flex align-items-center gap-2 small text-muted"><?php render_trend_pill( $stats['views_trend'] ); ?> vs. 30 zile anterioare</div>
		</div>
	</div>
	<div class="col-sm-6 col-xl-3">
		<div class="rm-card rm-card-body h-100">
			<div class="rm-kpi-label"><span class="rm-kpi-icon"><i class="bi bi-google"></i></span> Redirecționări pozitive Google</div>
			<div class="rm-kpi-value"><?php echo esc_html( format_number( $stats['redirects'] ) ); ?></div>
			<div class="d-flex align-items-center gap-2 small text-muted"><?php render_trend_pill( $stats['redirects_trend'] ); ?> vs. 30 zile anterioare</div>
		</div>
	</div>
	<div class="col-sm-6 col-xl-3">
		<div class="rm-card rm-card-body h-100">
			<div class="rm-kpi-label"><span class="rm-kpi-icon"><i class="bi bi-shield-check"></i></span> Feedback negativ interceptat</div>
			<div class="rm-kpi-value"><?php echo esc_html( format_number( $stats['feedback'] ) ); ?></div>
			<div class="d-flex align-items-center gap-2 small text-muted"><?php render_trend_pill( $stats['feedback_trend'], true ); ?> reputație salvată</div>
		</div>
	</div>
	<div class="col-sm-6 col-xl-3">
		<div class="rm-card rm-card-body h-100">
			<div class="rm-kpi-label"><span class="rm-kpi-icon"><i class="bi bi-star"></i></span> Rating intern mediu</div>
			<div class="rm-kpi-value"><?php echo esc_html( 0.0 < $stats['avg_rating'] ? number_format( $stats['avg_rating'], 2, ',', '.' ) : '—' ); ?><span class="fs-6 text-muted fw-medium"> / 5</span></div>
			<div class="d-flex align-items-center gap-2 small text-muted"><?php render_trend_pill( $stats['avg_trend'] ); ?> conversie <?php echo esc_html( $stats['conversion'] ); ?>%</div>
		</div>
	</div>
</div>

<?php if ( $is_owner ) : ?>
	<div class="rm-card rm-card-body mb-3">
		<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
			<div>
				<div class="fw-semibold text-dark">Cotă lunară de recenzii — <?php echo esc_html( get_user_limits( $owner )['plan_name'] ); ?></div>
				<div class="small text-muted"><?php echo esc_html( format_quota_usage( $usage ) ); ?> recenzii folosite luna aceasta (fiecare evaluare cu stele = 1 recenzie; simplele vizualizări nu se numără) · ciclul se resetează pe <?php echo esc_html( format_date( $usage['cycle_end'] ) ); ?></div>
			</div>
			<span class="rm-pill <?php echo esc_attr( $summary['badge'] ); ?>"><?php echo esc_html( $summary['label'] ); ?><?php echo null !== $summary['days_left'] ? esc_html( ' · ' . $summary['days_left'] . ' zile rămase' ) : ''; ?></span>
		</div>
		<div class="progress rm-progress" role="progressbar" aria-valuenow="<?php echo esc_attr( $usage['percent'] ); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Cotă folosită">
			<div class="progress-bar<?php echo esc_attr( 100 <= $usage['percent'] ? ' is-danger' : ( 80 <= $usage['percent'] ? ' is-warning' : '' ) ); ?>" data-width="<?php echo esc_attr( $usage['percent'] ); ?>"></div>
		</div>
	</div>
<?php endif; ?>

<div class="row g-3 mb-3">
	<div class="col-xl-8">
		<div class="rm-card h-100">
			<div class="rm-card-header">
				<h2 class="rm-card-title">Vizualizări, redirecționări și feedback — ultimele 30 de zile</h2>
			</div>
			<div class="rm-card-body">
				<div class="rm-chart-wrap"><canvas id="rmActivityChart" aria-label="Grafic activitate" role="img"></canvas></div>
			</div>
		</div>
	</div>
	<div class="col-xl-4">
		<div class="rm-card h-100">
			<div class="rm-card-header">
				<h2 class="rm-card-title">Distribuția stelelor</h2>
			</div>
			<div class="rm-card-body">
				<?php if ( 0 === array_sum( $distribution ) ) : ?>
					<div class="rm-empty"><i class="bi bi-bar-chart"></i><p class="mt-2 mb-0">Încă nu există evaluări.</p></div>
				<?php else : ?>
					<div class="rm-chart-wrap-sm"><canvas id="rmStarsChart" aria-label="Distribuția stelelor" role="img"></canvas></div>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>

<?php if ( $can_tag ) : ?>
	<div class="rm-card mb-3">
		<div class="rm-card-header">
			<h2 class="rm-card-title">Performanță pe angajat / departament / sursă — 30 de zile</h2>
			<?php if ( $is_owner ) : ?>
				<div class="d-flex flex-wrap gap-1">
					<a class="btn btn-sm btn-dark" href="<?php echo esc_url( app_url( 'dashboard/team-report.php' ) ); ?>"><i class="bi bi-bar-chart-line"></i> Raport echipă</a>
					<a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'dashboard/printables.php' ) ); ?>"><i class="bi bi-tags"></i> Gestionează etichetele QR</a>
				</div>
			<?php endif; ?>
		</div>
		<?php if ( empty( $tag_rows ) ) : ?>
			<div class="rm-card-body small text-muted">Încă nu există scanări pe etichete. Creează coduri QR dedicate (ex: <code>?ref=chelner-mihai</code>, <code>?ref=receptie</code>, <code>?ref=masa-4</code>) din Print Studio.</div>
		<?php else : ?>
			<div class="table-responsive">
				<table class="table rm-table">
					<thead><tr><th>Etichetă</th><th>Companie</th><th class="text-end">Vizualizări</th><th class="text-end">Google</th><th class="text-end">Feedback privat</th><th class="text-end">Rating mediu</th><th></th></tr></thead>
					<tbody>
						<?php foreach ( $tag_rows as $tag_row ) : ?>
							<tr>
								<td class="fw-medium text-dark"><i class="bi bi-tag text-muted"></i> <?php echo esc_html( $tag_row['label'] ); ?></td>
								<td class="small"><?php echo esc_html( $tag_row['company_name'] ); ?></td>
								<td class="text-end"><?php echo esc_html( format_number( $tag_row['views'] ) ); ?></td>
								<td class="text-end text-success fw-semibold"><?php echo esc_html( format_number( $tag_row['redirects'] ) ); ?></td>
								<td class="text-end text-danger"><?php echo esc_html( format_number( $tag_row['feedback'] ) ); ?></td>
								<td class="text-end"><?php echo esc_html( 0.0 < $tag_row['avg_rating'] ? number_format( $tag_row['avg_rating'], 2, ',', '.' ) . '★' : '—' ); ?></td>
								<td class="text-end"><a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'dashboard/reviews.php', [ 'company_id' => (int) $tag_row['company_id'], 'ref' => $tag_row['ref_tag'] ] ) ); ?>">Vezi</a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
<?php endif; ?>

<div class="rm-card">
	<div class="rm-card-header">
		<h2 class="rm-card-title">Recenzii & feedback privat recent</h2>
		<a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'dashboard/reviews.php' ) ); ?>">Vezi tot <i class="bi bi-arrow-right"></i></a>
	</div>
	<?php if ( empty( $recent ) ) : ?>
		<div class="rm-empty"><i class="bi bi-inbox"></i><p class="mt-2 mb-0">Nicio evaluare încă. Distribuie linkul sau printează codul QR.</p></div>
	<?php else : ?>
		<div class="table-responsive">
			<table class="table rm-table">
				<thead>
					<tr>
						<th>Data</th>
						<th>Companie</th>
						<th>Rating</th>
						<th>Tip</th>
						<th>Client / Mesaj</th>
						<th>Status</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $recent as $review ) : ?>
						<?php $is_locked = 1 === (int) $review['is_locked']; ?>
						<tr>
							<td class="text-nowrap small text-muted"><?php echo esc_html( format_datetime( $review['created_at'] ) ); ?></td>
							<td class="text-nowrap"><?php echo esc_html( $review['company_name'] ); ?></td>
							<td><?php echo rating_badge( $review['rating'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper. ?></td>
							<td class="text-nowrap small"><?php echo esc_html( $actions[ $review['action_taken'] ] ); ?></td>
							<td class="rm-truncate">
								<?php if ( 'redirected_google' === $review['action_taken'] ) : ?>
									<span class="text-muted small">Trimis către Google Reviews</span>
								<?php elseif ( $is_locked ) : ?>
									<span class="rm-blur-inline"><?php echo esc_html( mask_text( 'Feedback blocat' ) ); ?></span>
									<i class="bi bi-lock-fill text-danger ms-1" title="Blocat"></i>
								<?php else : ?>
									<a href="<?php echo esc_url( app_url( 'dashboard/review-view.php', [ 'id' => (int) $review['id'] ] ) ); ?>">
										<?php echo esc_html( '' !== $review['customer_name'] ? $review['customer_name'] . ' — ' : '' ); ?><?php echo esc_html( mb_substr( (string) $review['message'], 0, 80 ) ); ?>
									</a>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( 'private_feedback' === $review['action_taken'] ) : ?>
									<?php echo ticket_status_badge( $review['ticket_status'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper. ?>
								<?php else : ?>
									<span class="text-muted">—</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>

<?php
require REPUVIO_ROOT . '/templates/app-footer.php';
