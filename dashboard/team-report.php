<?php
/**
 * "Rapoarte echipă": compares the employee / department QR codes (?ref=) of a company.
 * Shows who hands out the code (scans + evaluations) and whose guests are less happy,
 * with charts and plain-language conclusions for non-technical owners. Pro plan feature.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$user      = require_owner();
$owner_id  = (int) $user['id'];
$companies = get_user_companies( $owner_id );
$can_tag   = user_can_use_ref_tags( $user );
$periods   = get_team_report_periods();
$days      = (int) input_get( 'days', '90' );
$days      = array_key_exists( $days, $periods ) ? $days : 90;

// Company: ?company_id=, else the one selected in the header, else the first.
$company    = null;
$company_id = sanitize_int_range( input_get( 'company_id', '0' ), 0, PHP_INT_MAX, 0 );
$company_id = 0 < $company_id ? $company_id : get_current_company_id();

foreach ( $companies as $candidate ) {
	if ( (int) $candidate['id'] === $company_id ) {
		$company = $candidate;
	}
}

if ( ! $company && ! empty( $companies ) ) {
	$company = $companies[0];
}

$report  = $company && $can_tag ? get_team_report( $owner_id, (int) $company['id'], $days ) : null;
$palette = [ '#2563EB', '#D97706', '#7C3AED', '#0D9488', '#DB2777', '#4D7C0F', '#0891B2', '#B45309' ];
$general = '#94A3B8';

if ( $report ) {
	foreach ( $report['rows'] as $index => &$report_row ) {
		$report_row['color'] = $report_row['is_general'] ? $general : $palette[ $index % count( $palette ) ];
	}
	unset( $report_row );
}

/**
 * Star rating tone class, consistent with the "Note slabe" rule (grey while there is too little data).
 *
 * @param array $row     Report row.
 * @param float $company Company average.
 * @return string
 */
function team_rating_tone( array $row, $company ) {
	if ( 0.0 === (float) $row['avg'] || ! $row['reliable'] ) {
		return 'text-muted';
	}

	if ( ! $row['is_general'] && $row['avg'] <= $company - TEAM_REPORT_LOW_GAP + 0.0001 ) {
		return 'text-danger';
	}

	return 4.3 <= $row['avg'] ? 'text-success' : 'text-warning';
}

/**
 * Ranking pill per flag: [ class, icon, text, title ].
 *
 * @param string $flag Flag.
 * @param array  $row  Report row.
 * @return array
 */
function team_flag_pill( $flag, array $row ) {
	switch ( $flag ) {
		case 'few':
			return [ 'rm-pill-neutral', 'bi-eye-slash', 'Puține evaluări', 'Aduce cel mult jumătate din evaluările pe zi ale codului de pe primul loc' ];
		case 'low':
			return [ 'rm-pill-negative', 'bi-emoji-frown', 'Note slabe', 'Nota medie este cu cel puțin ' . format_stars( TEAM_REPORT_LOW_GAP ) . '★ sub media companiei' ];
		case 'new':
			return [ 'rm-pill-trial', 'bi-stars', 'Cod nou · ' . ro_count( $row['active_days'], 'zile' ), 'Este comparat cu restul după ' . TEAM_REPORT_MIN_DAYS . ' zile' ];
		case 'little_data':
			return [ 'rm-pill-muted', 'bi-hourglass-split', 'Puține date încă', 'Sub ' . TEAM_REPORT_MIN_REVIEWS . ' evaluări: nota nu este încă relevantă' ];
		case 'pending':
			return [ 'rm-pill-muted', 'bi-hourglass-split', 'Prea devreme pentru concluzii', 'Comparațiile pornesc când primul cod are ' . TEAM_REPORT_MIN_LEADER . ' evaluări' ];
		default:
			return [ 'rm-pill-positive', 'bi-check2', 'place' === $row['kind'] ? 'Scanat constant, clienți mulțumiți' : 'Oferă codul, clienți mulțumiți', 'Nicio problemă găsită' ];
	}
}

$page_title       = 'Rapoarte echipă';
$active_nav       = 'team_report';
$page_breadcrumbs = [ [ 'Acasă', app_url( 'dashboard/index.php' ) ], [ 'Rapoarte echipă', '' ] ];

if ( $report && 0 < $report['total_reviews'] ) {
	$chart_rows = array_map(
		function ( $row ) {
			return [
				'label'    => $row['is_general'] ? 'Coduri generale' : $row['label'],
				'color'    => $row['color'],
				'general'  => $row['is_general'],
				'views'    => $row['views'],
				'reviews'  => $row['reviews'],
				'avg'      => $row['avg'],
				'reliable' => $row['reliable'],
				'positive' => $row['positive'],
				'neutral'  => $row['neutral'],
				'negative' => $row['negative'],
				'months'   => array_values( $row['months'] ),
			];
		},
		$report['rows']
	);

	$page_scripts     = [ 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', asset_url( 'js/team-report.js' ) ];
	$page_inline_data = [
		'teamReport' => [
			'rows'       => $chart_rows,
			'months'     => array_values( $report['months'] ),
			'companyAvg' => $report['company_avg'],
			'lowGap'     => TEAM_REPORT_LOW_GAP,
		],
	];
}

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<?php if ( ! $can_tag ) : ?>
	<div class="rm-card rm-card-body text-center py-5">
		<div class="rm-empty-icon mx-auto mb-3"><i class="bi bi-people"></i></div>
		<h2 class="h5 text-dark">Vezi ce angajat aduce recenzii și cine are nevoie de ajutor</h2>
		<p class="text-muted mx-auto rm-measure">Cu planul Pro creezi câte un cod QR pentru fiecare angajat sau departament. Raportul îți arată pe grafic cine oferă codul clienților, cine evită să o facă și unde sunt clienții mai puțin mulțumiți.</p>
		<a class="btn btn-dark" href="<?php echo esc_url( app_url( 'dashboard/billing.php' ) ); ?>"><i class="bi bi-arrow-up-circle"></i> Vezi planul Pro</a>
	</div>
<?php elseif ( ! $company ) : ?>
	<div class="rm-card rm-empty">
		<i class="bi bi-shop"></i>
		<p class="mt-2">Adaugă mai întâi o companie.</p>
		<a class="btn btn-dark" href="<?php echo esc_url( app_url( 'dashboard/company-edit.php' ) ); ?>"><i class="bi bi-plus-lg"></i> Adaugă companie</a>
	</div>
<?php else : ?>

	<form method="get" class="rm-card rm-card-body d-flex flex-wrap align-items-end gap-3 mb-3">
		<?php if ( 1 < count( $companies ) ) : ?>
			<div>
				<label class="form-label small mb-1" for="team_company">Companie</label>
				<select class="form-select form-select-sm" id="team_company" name="company_id" data-autosubmit>
					<?php foreach ( $companies as $option ) : ?>
						<option value="<?php echo esc_attr( (int) $option['id'] ); ?>"<?php echo (int) $option['id'] === (int) $company['id'] ? ' selected' : ''; ?>><?php echo esc_html( $option['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		<?php else : ?>
			<input type="hidden" name="company_id" value="<?php echo esc_attr( (int) $company['id'] ); ?>">
		<?php endif; ?>
		<div>
			<label class="form-label small mb-1" for="team_days">Perioadă</label>
			<select class="form-select form-select-sm" id="team_days" name="days" data-autosubmit>
				<?php foreach ( $periods as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>"<?php echo $value === $days ? ' selected' : ''; ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<noscript><button type="submit" class="btn btn-sm btn-dark">Aplică</button></noscript>
		<p class="small text-muted mb-0 ms-lg-auto rm-measure">Fiecare angajat sau departament are propriul cod QR. Comparăm de câte ori a fost scanat fiecare cod, câte evaluări a adus și ce note au dat clienții.</p>
	</form>

	<div class="row g-3 mb-3">
		<div class="col-6 col-xl-3">
			<div class="rm-card rm-card-body h-100">
				<div class="rm-kpi-label"><span class="rm-kpi-icon"><i class="bi bi-qr-code-scan"></i></span> Scanări cod QR</div>
				<div class="rm-kpi-value"><?php echo esc_html( format_number( $report['total_views'] ) ); ?></div>
				<div class="small text-muted">clienți care au deschis pagina</div>
			</div>
		</div>
		<div class="col-6 col-xl-3">
			<div class="rm-card rm-card-body h-100">
				<div class="rm-kpi-label"><span class="rm-kpi-icon"><i class="bi bi-chat-square-heart"></i></span> Evaluări primite</div>
				<div class="rm-kpi-value"><?php echo esc_html( format_number( $report['total_reviews'] ) ); ?></div>
				<div class="small text-muted">clienți care au dat stele</div>
			</div>
		</div>
		<div class="col-6 col-xl-3">
			<div class="rm-card rm-card-body h-100">
				<div class="rm-kpi-label"><span class="rm-kpi-icon"><i class="bi bi-star"></i></span> Nota medie a companiei</div>
				<div class="rm-kpi-value"><?php echo esc_html( 0.0 < $report['company_avg'] ? format_stars( $report['company_avg'] ) : '—' ); ?><span class="fs-6 text-muted fw-medium"> / 5</span></div>
				<div class="small text-muted">reperul cu care comparăm echipa</div>
			</div>
		</div>
		<div class="col-6 col-xl-3">
			<div class="rm-card rm-card-body h-100">
				<div class="rm-kpi-label"><span class="rm-kpi-icon"><i class="bi bi-people"></i></span> Coduri pe angajat / departament</div>
				<div class="rm-kpi-value"><?php echo esc_html( format_number( $report['tagged_count'] ) ); ?></div>
				<a class="small" href="<?php echo esc_url( app_url( 'dashboard/printables.php' ) ); ?>">Gestionează codurile <i class="bi bi-arrow-right"></i></a>
			</div>
		</div>
	</div>

	<section class="rm-card mb-3" aria-labelledby="teamInsightsTitle">
		<div class="rm-card-header">
			<h2 class="rm-card-title" id="teamInsightsTitle"><i class="bi bi-lightbulb"></i> Pe scurt — ce înseamnă cifrele</h2>
		</div>
		<div class="rm-card-body">
			<div class="row g-3">
				<?php foreach ( $report['insights'] as $insight ) : ?>
					<div class="col-lg-6">
						<div class="rm-insight rm-insight-<?php echo esc_attr( $insight['tone'] ); ?> h-100">
							<span class="rm-insight-icon"><i class="bi <?php echo esc_attr( $insight['icon'] ); ?>"></i></span>
							<div class="min-w-0">
								<div class="fw-semibold text-dark mb-1"><?php echo esc_html( $insight['title'] ); ?></div>
								<div class="small text-muted"><?php echo esc_html( $insight['text'] ); ?></div>
								<?php if ( ! empty( $insight['link'] ) ) : ?>
									<a class="small fw-semibold d-inline-block mt-2" href="<?php echo esc_url( $insight['link'][1] ); ?>"><?php echo esc_html( $insight['link'][0] ); ?> <i class="bi bi-arrow-right"></i></a>
								<?php endif; ?>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<?php if ( 0 < $report['total_reviews'] ) : ?>
		<section class="rm-card mb-3" aria-labelledby="teamRankingTitle">
			<div class="rm-card-header">
				<h2 class="rm-card-title" id="teamRankingTitle">Clasamentul echipei</h2>
				<span class="small text-muted"><?php echo esc_html( $periods[ $days ] ); ?></span>
			</div>
			<div class="rm-card-body">
				<div class="row g-3">
					<?php foreach ( $report['rows'] as $row ) : ?>
						<div class="col-md-6 col-xxl-4">
							<div class="rm-team-card h-100<?php echo $row['is_general'] ? ' is-general' : ''; ?>">
								<div class="d-flex align-items-center gap-2 mb-1">
									<span class="rm-team-dot" data-color="<?php echo esc_attr( $row['color'] ); ?>"></span>
									<span class="fw-semibold text-dark text-truncate"><?php echo esc_html( $row['label'] ); ?></span>
								</div>
								<div class="d-flex flex-wrap gap-1 mb-3 rm-team-flags">
									<?php foreach ( $row['flags'] as $flag ) : ?>
										<?php $pill = team_flag_pill( $flag, $row ); ?>
										<span class="rm-pill <?php echo esc_attr( $pill[0] ); ?>" title="<?php echo esc_attr( $pill[3] ); ?>"><i class="bi <?php echo esc_attr( $pill[1] ); ?>"></i> <?php echo esc_html( $pill[2] ); ?></span>
									<?php endforeach; ?>
									<?php if ( $row['is_general'] ) : ?>
										<span class="rm-pill rm-pill-muted">Afișe, stickere pe mese, link</span>
									<?php endif; ?>
								</div>
								<div class="row g-2 text-center mb-2">
									<div class="col-4">
										<div class="rm-team-metric"><?php echo esc_html( format_number( $row['views'] ) ); ?></div>
										<div class="rm-team-metric-label">scanări</div>
									</div>
									<div class="col-4">
										<div class="rm-team-metric"><?php echo esc_html( format_number( $row['reviews'] ) ); ?></div>
										<div class="rm-team-metric-label">evaluări</div>
									</div>
									<div class="col-4">
										<div class="rm-team-metric <?php echo esc_attr( team_rating_tone( $row, $report['company_avg'] ) ); ?>"><?php echo esc_html( 0.0 < $row['avg'] ? format_stars( $row['avg'] ) . '★' : '—' ); ?></div>
										<div class="rm-team-metric-label">nota medie</div>
									</div>
								</div>
								<div class="d-flex justify-content-between small text-muted mb-1">
									<span><?php echo esc_html( $row['share'] . '% din toate evaluările' ); ?></span>
									<span><?php echo esc_html( $row['happy_pct'] . '% mulțumiți' ); ?></span>
								</div>
								<div class="progress rm-progress" role="progressbar" aria-label="<?php echo esc_attr( 'Pondere evaluări ' . $row['label'] ); ?>" aria-valuenow="<?php echo esc_attr( $row['share'] ); ?>" aria-valuemin="0" aria-valuemax="100">
									<div class="progress-bar" data-width="<?php echo esc_attr( $row['share'] ); ?>" data-color="<?php echo esc_attr( $row['color'] ); ?>"></div>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</section>

		<div class="row g-3 mb-3">
			<div class="col-xl-6">
				<div class="rm-card h-100">
					<div class="rm-card-header">
						<div>
							<h2 class="rm-card-title">Câte evaluări a adus fiecare cod</h2>
							<div class="small text-muted">Bară lungă = codul este oferit des clienților.</div>
						</div>
					</div>
					<div class="rm-card-body"><div class="rm-chart-box" data-team-chart-height><canvas id="rmTeamVolumeChart" aria-label="Evaluări pe angajat" role="img"></canvas></div></div>
				</div>
			</div>
			<div class="col-xl-6">
				<div class="rm-card h-100">
					<div class="rm-card-header">
						<div>
							<h2 class="rm-card-title">Nota medie primită</h2>
							<div class="small text-muted">Verde = 4,3★ sau mai mult · portocaliu = acceptabil · roșu = „Note slabe” · gri = sub <?php echo esc_html( TEAM_REPORT_MIN_REVIEWS ); ?> evaluări, încă nerelevant.</div>
						</div>
					</div>
					<div class="rm-card-body"><div class="rm-chart-box" data-team-chart-height><canvas id="rmTeamRatingChart" aria-label="Nota medie pe angajat" role="img"></canvas></div></div>
				</div>
			</div>
			<div class="col-xl-6">
				<div class="rm-card h-100">
					<div class="rm-card-header">
						<div>
							<h2 class="rm-card-title">Cum au votat clienții</h2>
							<div class="small text-muted">Din 100 de evaluări: câte au fost mulțumite, neutre sau nemulțumite.</div>
						</div>
					</div>
					<div class="rm-card-body"><div class="rm-chart-box" data-team-chart-height><canvas id="rmTeamSentimentChart" aria-label="Mulțumiți, neutri și nemulțumiți pe angajat" role="img"></canvas></div></div>
				</div>
			</div>
			<div class="col-xl-6">
				<div class="rm-card h-100">
					<div class="rm-card-header">
						<div>
							<h2 class="rm-card-title">Evoluție lună de lună</h2>
							<div class="small text-muted">Evaluări aduse de fiecare cod, în fiecare lună.</div>
						</div>
					</div>
					<div class="rm-card-body"><div class="rm-chart-box"><canvas id="rmTeamMonthlyChart" aria-label="Evaluări pe lună pe angajat" role="img"></canvas></div></div>
				</div>
			</div>
		</div>

		<div class="rm-card">
			<div class="rm-card-header"><h2 class="rm-card-title">Detalii</h2></div>
			<div class="table-responsive">
				<table class="table rm-table">
					<thead>
						<tr>
							<th>Cod QR</th>
							<th class="text-end">Scanări</th>
							<th class="text-end">Evaluări</th>
							<th class="text-end">Trimiși pe Google</th>
							<th class="text-end">Feedback privat</th>
							<th class="text-end">Nota medie</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $report['rows'] as $row ) : ?>
							<tr>
								<td class="fw-medium text-dark"><span class="rm-team-dot" data-color="<?php echo esc_attr( $row['color'] ); ?>"></span> <?php echo esc_html( $row['label'] ); ?></td>
								<td class="text-end"><?php echo esc_html( format_number( $row['views'] ) ); ?></td>
								<td class="text-end"><?php echo esc_html( format_number( $row['reviews'] ) ); ?></td>
								<td class="text-end text-success fw-semibold"><?php echo esc_html( format_number( $row['redirects'] ) ); ?></td>
								<td class="text-end text-danger"><?php echo esc_html( format_number( $row['feedback'] ) ); ?></td>
								<td class="text-end <?php echo esc_attr( team_rating_tone( $row, $report['company_avg'] ) ); ?>"><?php echo esc_html( 0.0 < $row['avg'] ? format_stars( $row['avg'] ) . '★' : '—' ); ?></td>
								<td class="text-end">
									<?php if ( ! $row['is_general'] ) : ?>
										<a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'dashboard/reviews.php', [ 'company_id' => (int) $company['id'], 'ref' => $row['slug'] ] ) ); ?>">Vezi evaluările</a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php endif; ?>

	<section class="rm-card mt-3" aria-labelledby="teamHowTitle">
		<div class="rm-card-header">
			<h2 class="rm-card-title" id="teamHowTitle"><i class="bi bi-info-circle"></i> Cum citești acest raport și cum ajungem la concluzii</h2>
		</div>
		<div class="rm-card-body">
			<div class="row g-4 small">
				<div class="col-lg-6">
					<h3 class="h6 text-dark">Ce înseamnă cifrele</h3>
					<ul class="rm-how-list">
						<li><strong>Scanare</strong> = un client a scanat codul QR (sau a deschis linkul) și a ajuns pe pagina de evaluare.</li>
						<li><strong>Evaluare</strong> = clientul a ales stelele. Cei care dau cel puțin pragul de stele setat la companie sunt trimiși pe Google, ceilalți lasă feedback privat, vizibil doar ție.</li>
						<li><strong>Nota medie a companiei</strong> = media tuturor evaluărilor din perioadă, inclusiv cele de pe codurile generale. Este reperul cu care comparăm fiecare cod.</li>
						<li><strong>Coduri generale</strong> = afișe, stickere pe mese sau linkul simplu, fără angajat. Le arătăm pentru context, dar nu le judecăm.</li>
						<li>Codurile sunt comparate după <strong>ritm</strong> (evaluări pe zi de când există codul), nu doar după total. Astfel un cod creat mai târziu nu pare automat „mai slab”.</li>
					</ul>
				</div>
				<div class="col-lg-6">
					<h3 class="h6 text-dark">Regulile după care apar etichetele</h3>
					<ul class="rm-how-list">
						<li><strong>Comparațiile pornesc</strong> doar după ce codul de pe primul loc are cel puțin <?php echo esc_html( TEAM_REPORT_MIN_LEADER ); ?> evaluări în perioada aleasă. Până atunci vezi „Prea devreme pentru concluzii”.</li>
						<li><strong>„Puține evaluări”</strong>: codul aduce cel mult <?php echo esc_html( (int) round( TEAM_REPORT_FEW_RATIO * 100 ) ); ?>% din evaluările pe zi ale codului de pe primul loc.</li>
						<li><strong>„Note slabe”</strong>: cel puțin <?php echo esc_html( TEAM_REPORT_MIN_REVIEWS ); ?> evaluări și o notă medie mai mică cu <?php echo esc_html( format_stars( TEAM_REPORT_LOW_GAP ) ); ?>★ sau mai mult decât media companiei.</li>
						<li><strong>„Cod nou”</strong>: codul există de mai puțin de <?php echo esc_html( TEAM_REPORT_MIN_DAYS ); ?> zile și nu este încă comparat cu ceilalți.</li>
						<li><strong>„Puține date încă”</strong>: sub <?php echo esc_html( TEAM_REPORT_MIN_REVIEWS ); ?> evaluări; nota lui nu este încă relevantă (apare cu gri).</li>
						<li><strong>„Oferă codul, clienți mulțumiți”</strong>: niciuna dintre situațiile de mai sus.</li>
						<li>Sfaturile se adaptează după numele codului: pentru o persoană („Ospătar Mihai”), pentru un loc („Masa 4”, „Terasa”) sau pentru o echipă / departament („Recepție”).</li>
					</ul>
				</div>
			</div>
			<div class="alert rm-alert-info small mt-3 mb-0">
				<i class="bi bi-lightbulb"></i> <strong>Interpretează în context.</strong> Raportul nu știe cine a lucrat în ture mai aglomerate, cine a fost în concediu sau cine lucrează part-time. Folosește concluziile ca punct de plecare pentru o discuție cu echipa, nu ca verdict.
			</div>
		</div>
	</section>
<?php endif; ?>

<?php require REPUVIO_ROOT . '/templates/app-footer.php'; ?>
