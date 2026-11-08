<?php
/**
 * Monthly "ROI / Value Generated" report: data aggregation, branded HTML email and the
 * automatic monthly run used by cron.php (the Super Admin can also send it manually).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

/**
 * Romanian month name.
 *
 * @param int $month 1-12.
 * @return string
 */
function ro_month_name( $month ) {
	$names = [ 1 => 'ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie' ];

	return $names[ (int) $month ] ?? '';
}

/**
 * Report period types.
 *
 * @return array
 */
function get_report_period_types() {
	return [
		'previous_month' => 'Luna precedentă',
		'current_month'  => 'Luna curentă',
		'custom'         => 'Interval personalizat',
	];
}

/**
 * Resolves a report period.
 *
 * @param string $type previous_month|current_month|custom.
 * @param string $from Custom start (Y-m-d).
 * @param string $to   Custom end (Y-m-d).
 * @return array{from:string,to:string,label:string,key:string}|null Null for an invalid custom range.
 */
function get_report_period( $type, $from = '', $to = '' ) {
	if ( 'custom' === $type ) {
		$from = sanitize_date( $from );
		$to   = sanitize_date( $to );

		if ( '' === $from || '' === $to || $to < $from || strtotime( $to ) - strtotime( $from ) > 366 * 86400 ) {
			return null;
		}

		return [
			'from'  => $from . ' 00:00:00',
			'to'    => $to . ' 23:59:59',
			'label' => date( 'd.m.Y', strtotime( $from ) ) . ' – ' . date( 'd.m.Y', strtotime( $to ) ),
			'key'   => '',
		];
	}

	$start = 'current_month' === $type ? new DateTime( 'first day of this month 00:00:00' ) : new DateTime( 'first day of last month 00:00:00' );
	$end   = ( clone $start )->modify( 'last day of this month 23:59:59' );

	return [
		'from'  => $start->format( 'Y-m-d H:i:s' ),
		'to'    => $end->format( 'Y-m-d H:i:s' ),
		'label' => ro_month_name( (int) $start->format( 'n' ) ) . ' ' . $start->format( 'Y' ) . ( 'current_month' === $type ? ' (până azi)' : '' ),
		'key'   => $start->format( 'Y-m' ),
	];
}

/**
 * Aggregates the ROI report of a business owner for a period.
 *
 * @param array  $owner Owner row.
 * @param string $from  Start datetime.
 * @param string $to    End datetime.
 * @return array{totals:array,companies:array,tags:array,show_tags:bool}
 */
function get_roi_report_data( array $owner, $from, $to ) {
	$params = [
		'user_id' => (int) $owner['id'],
		'from'    => $from,
		'to'      => $to,
	];

	$views = array_column(
		db_all(
			'SELECT v.company_id, COUNT(*) AS total FROM funnel_views v INNER JOIN companies c ON c.id = v.company_id
			WHERE c.user_id = :user_id AND v.created_at BETWEEN :from AND :to GROUP BY v.company_id',
			$params
		),
		'total',
		'company_id'
	);

	$reviews = [];

	foreach ( db_all(
		"SELECT r.company_id,
			SUM(r.action_taken = 'redirected_google') AS redirects,
			SUM(r.action_taken = 'private_feedback') AS feedback,
			SUM(r.action_taken = 'private_feedback' AND r.ticket_status = 'resolved') AS resolved,
			AVG(r.rating) AS avg_rating
		FROM reviews r INNER JOIN companies c ON c.id = r.company_id
		WHERE c.user_id = :user_id AND r.created_at BETWEEN :from AND :to GROUP BY r.company_id",
		$params
	) as $row ) {
		$reviews[ (int) $row['company_id'] ] = $row;
	}

	$companies = [];
	$totals    = [
		'views'      => 0,
		'redirects'  => 0,
		'feedback'   => 0,
		'resolved'   => 0,
		'avg_rating' => 0.0,
		'conversion' => 0,
	];
	$rating_sum   = 0.0;
	$rating_count = 0;

	foreach ( get_user_companies( (int) $owner['id'] ) as $company ) {
		$id  = (int) $company['id'];
		$row = [
			'name'       => $company['name'],
			'views'      => (int) ( $views[ $id ] ?? 0 ),
			'redirects'  => (int) ( $reviews[ $id ]['redirects'] ?? 0 ),
			'feedback'   => (int) ( $reviews[ $id ]['feedback'] ?? 0 ),
			'resolved'   => (int) ( $reviews[ $id ]['resolved'] ?? 0 ),
			'avg_rating' => isset( $reviews[ $id ]['avg_rating'] ) ? round( (float) $reviews[ $id ]['avg_rating'], 2 ) : 0.0,
		];

		$companies[] = $row;

		$totals['views']     += $row['views'];
		$totals['redirects'] += $row['redirects'];
		$totals['feedback']  += $row['feedback'];
		$totals['resolved']  += $row['resolved'];

		$count = $row['redirects'] + $row['feedback'];

		if ( 0 < $count ) {
			$rating_sum   += $row['avg_rating'] * $count;
			$rating_count += $count;
		}
	}

	$totals['avg_rating'] = 0 < $rating_count ? round( $rating_sum / $rating_count, 2 ) : 0.0;
	$totals['conversion'] = 0 < $totals['views'] ? (int) round( ( $totals['redirects'] + $totals['feedback'] ) / $totals['views'] * 100 ) : 0;

	$show_tags = user_can_use_ref_tags( $owner );
	$tags      = [];

	if ( $show_tags ) {
		$tags = db_all(
			"SELECT r.ref_tag, COALESCE(t.label, r.ref_tag) AS label, c.name AS company_name,
				SUM(r.action_taken = 'redirected_google') AS redirects,
				SUM(r.action_taken = 'private_feedback') AS feedback,
				AVG(r.rating) AS avg_rating
			FROM reviews r
			INNER JOIN companies c ON c.id = r.company_id
			LEFT JOIN ref_tags t ON t.company_id = r.company_id AND t.slug = r.ref_tag
			WHERE c.user_id = :user_id AND r.ref_tag <> '' AND r.created_at BETWEEN :from AND :to
			GROUP BY r.company_id, r.ref_tag, t.label, c.name
			ORDER BY redirects DESC, avg_rating DESC
			LIMIT 5",
			$params
		);
	}

	return [
		'totals'    => $totals,
		'companies' => $companies,
		'tags'      => $tags,
		'show_tags' => $show_tags,
	];
}

/**
 * Builds and sends the ROI report email (`monthly_roi_report` template) to a business owner.
 *
 * @param array $owner   Owner row.
 * @param array $period  get_report_period() result.
 * @param array $options send_templated_email() options (queue for cron, force for the admin manual send).
 * @return array send_templated_email() result.
 */
function send_roi_report_email( array $owner, array $period, array $options = [] ) {
	$data      = get_roi_report_data( $owner, $period['from'], $period['to'] );
	$totals    = $data['totals'];
	$cell      = 'border-top:1px solid #E2E8F0;';
	$locations = '';
	$tags      = '';

	if ( 1 < count( $data['companies'] ) ) {
		$locations = '<h2 style="font-size:16px;margin:22px 0 8px;color:#0F172A;">Pe locații</h2>'
			. '<table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="border:1px solid #E2E8F0;border-radius:10px;font-size:13px;border-collapse:separate;">'
			. '<tr style="background:#F8FAFC;color:#64748B;"><td>Locație</td><td align="right">Vizualizări</td><td align="right">Google</td><td align="right">Feedback</td></tr>';

		foreach ( $data['companies'] as $company ) {
			$locations .= '<tr><td style="' . $cell . '">' . esc_html( $company['name'] ) . '</td>'
				. '<td align="right" style="' . $cell . '">' . esc_html( format_number( $company['views'] ) ) . '</td>'
				. '<td align="right" style="' . $cell . 'color:#15803D;font-weight:600;">' . esc_html( format_number( $company['redirects'] ) ) . '</td>'
				. '<td align="right" style="' . $cell . 'color:#B91C1C;">' . esc_html( format_number( $company['feedback'] ) ) . '</td></tr>';
		}

		$locations .= '</table>';
	}

	if ( $data['show_tags'] ) {
		$tags = '<h2 style="font-size:16px;margin:22px 0 8px;color:#0F172A;">🏆 Top angajați / coduri QR</h2>';

		if ( empty( $data['tags'] ) ) {
			$tags .= '<p style="font-size:13px;color:#64748B;">Nu există încă evaluări pe codurile echipei. Creează coduri QR dedicate pentru angajați, mese sau departamente în Print Studio.</p>';
		} else {
			$tags .= '<table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="border:1px solid #E2E8F0;border-radius:10px;font-size:13px;border-collapse:separate;">'
				. '<tr style="background:#F8FAFC;color:#64748B;"><td>Cod</td><td align="right">Google</td><td align="right">Feedback</td><td align="right">Notă</td></tr>';

			foreach ( $data['tags'] as $tag ) {
				$tags .= '<tr><td style="' . $cell . '"><strong>' . esc_html( $tag['label'] ) . '</strong><br><span style="color:#64748B;font-size:12px;">' . esc_html( $tag['company_name'] ) . '</span></td>'
					. '<td align="right" style="' . $cell . 'color:#15803D;font-weight:600;">' . esc_html( format_number( $tag['redirects'] ) ) . '</td>'
					. '<td align="right" style="' . $cell . 'color:#B91C1C;">' . esc_html( format_number( $tag['feedback'] ) ) . '</td>'
					. '<td align="right" style="' . $cell . '">' . esc_html( number_format( (float) $tag['avg_rating'], 1, ',', '.' ) ) . '★</td></tr>';
			}

			$tags .= '</table>';
		}
	}

	return send_templated_email(
		'monthly_roi_report',
		$owner['email'],
		[
			'user_name'            => $owner['name'],
			'period_label'         => $period['label'],
			'total_views'          => format_number( $totals['views'] ),
			'google_redirects'     => format_number( $totals['redirects'] ),
			'saved_negatives'      => format_number( $totals['feedback'] ),
			'resolved_count'       => format_number( $totals['resolved'] ),
			'avg_rating'           => 0.0 < $totals['avg_rating'] ? number_format( $totals['avg_rating'], 2, ',', '.' ) . '★' : '—',
			'conversion'           => $totals['conversion'] . '%',
			'locations_table_html' => $locations,
			'top_ref_tags_html'    => $tags,
			'dashboard_url'        => app_url( 'dashboard/index.php' ),
		],
		(int) $owner['id'],
		$options
	);
}

/**
 * Sends the previous month's ROI report to every eligible owner once (cron).
 *
 * @return int Number of reports queued (sent by process_email_queue()).
 */
function send_monthly_roi_reports() {
	// The automatic run follows the template switch in Email & Șabloane (formerly the roi_reports_enabled setting).
	if ( ! is_email_template_enabled( 'monthly_roi_report' ) ) {
		return 0;
	}

	$period = get_report_period( 'previous_month' );
	$sent   = 0;
	$owners = db_all(
		"SELECT u.* FROM users u
		WHERE u.role = 'user' AND u.status = 'active' AND u.roi_report_enabled = 1
			AND (u.last_roi_report_period IS NULL OR u.last_roi_report_period <> ?)
			AND u.created_at <= ?
			AND EXISTS (SELECT 1 FROM companies c WHERE c.user_id = u.id)",
		[ $period['key'], $period['to'] ]
	);

	foreach ( $owners as $owner ) {
		// Flag first so concurrent cron runs never send twice.
		$flipped = db_query(
			'UPDATE users SET last_roi_report_period = ? WHERE id = ? AND (last_roi_report_period IS NULL OR last_roi_report_period <> ?)',
			[ $period['key'], (int) $owner['id'], $period['key'] ]
		)->rowCount();

		if ( 1 === $flipped ) {
			send_roi_report_email( $owner, $period, [ 'queue' => true ] );
			++$sent;
		}
	}

	return $sent;
}

/**
 * Allowed periods (days => label) of the team report.
 *
 * @return array
 */
function get_team_report_periods() {
	return [
		30  => 'Ultimele 30 de zile',
		90  => 'Ultimele 3 luni',
		180 => 'Ultimele 6 luni',
	];
}

/**
 * Romanian count with the "de" preposition required from 20 up ("5 evaluări", "23 de evaluări").
 *
 * @param int    $number Count.
 * @param string $noun   Plural noun.
 * @return string
 */
function ro_count( $number, $noun ) {
	$number = (int) $number;
	$rest   = $number % 100;

	return format_number( $number ) . ( 20 <= $rest || ( 0 === $rest && 0 < $number ) ? ' de ' : ' ' ) . $noun;
}

/**
 * Formats a star average the Romanian way ("4,6").
 *
 * @param float $value Average.
 * @return string
 */
function format_stars( $value ) {
	return number_format( (float) $value, 1, ',', '.' );
}

/*
 * Team report thresholds. The explanation at the bottom of dashboard/team-report.php reads the same
 * constants, so the rules shown to owners always match the calculation.
 */
const TEAM_REPORT_MIN_LEADER  = 10;  // Evaluations the busiest code needs before codes are compared.
const TEAM_REPORT_MIN_REVIEWS = 5;   // Evaluations a code needs before its rating is judged.
const TEAM_REPORT_MIN_DAYS    = 14;  // Age a code needs before it is compared with the others.
const TEAM_REPORT_FEW_RATIO   = 0.5; // "Puține evaluări" = at most this share of the leader's daily pace.
const TEAM_REPORT_LOW_GAP     = 0.4; // "Note slabe" = at least this many stars below the company average.

/**
 * Guesses what a QR code label stands for, so the advice fits: a person ("Ospătar Mihai",
 * "Dr. Popescu"), a place ("Masa 4", "Terasa", "Vitrina") or a team / department / anything else.
 *
 * @param string $label Tag label.
 * @return string person|place|team
 */
function ref_tag_kind( $label ) {
	$text = ' ' . mb_strtolower( (string) $label ) . ' ';

	$person = 'ospătar|ospatar|chelner|chelneriță|barman|barista|dr\.?|doctor|doctoriță|medic|asistent|asistentă|stilist|stilistă|frizer|coafeză|coafor|cosmetician|cosmeticiană|manichiuristă|terapeut|maseur|maseuză|instructor|antrenor|vânzător|vanzator|vânzătoare|consultant|consultantă|agent|agentă|tehnician|mecanic|casier|casieră|livrator|curier|șofer|sofer|manager|recepționer|receptioner|recepționeră|angajat|angajată|domnul|doamna';
	$place  = 'masa|masă|mese|terasa|terasă|sala|sală|bar|camera|cameră|etaj|intrare|vitrina|vitrină|casa de marcat|raft|tejghea|parcare|lift|hol|lobby|zona|zonă|stand|ghișeu|ghiseu|afiș|afis|poster|flyer|sticker|autocolant|meniu|ușă|usa|geam|perete|toaletă|toaleta';

	if ( preg_match( '/[\s„"(](?:' . $person . ')(?=[\s.,)”"]|\d)/u', $text ) ) {
		return 'person';
	}

	if ( preg_match( '/[\s„"(](?:' . $place . ')(?=[\s.,)”"]|\d)/u', $text ) ) {
		return 'place';
	}

	return 'team';
}

/**
 * Team report ("Rapoarte echipă"): one row per employee / department QR code of a company plus
 * the general codes (no ?ref=), with scans, ratings, sentiment split, monthly evolution and
 * plain-language conclusions for non-technical owners.
 *
 * Safeguards against misleading conclusions:
 * - no comparison until the busiest code has TEAM_REPORT_MIN_LEADER evaluations;
 * - codes are compared by evaluations per day of existence, and codes younger than
 *   TEAM_REPORT_MIN_DAYS are not compared at all;
 * - ratings are judged only from TEAM_REPORT_MIN_REVIEWS evaluations up;
 * - the advice depends on what the code stands for (ref_tag_kind()).
 *
 * @param int $owner_id   Account owner ID.
 * @param int $company_id Company ID (must belong to the owner).
 * @param int $days       Period length in days.
 * @return array{rows:array,months:array,days:int,total_reviews:int,total_views:int,company_avg:float,tagged_count:int,comparing:bool,insights:array}
 */
function get_team_report( $owner_id, $company_id, $days ) {
	$days   = array_key_exists( (int) $days, get_team_report_periods() ) ? (int) $days : 90;
	$since  = date( 'Y-m-d 00:00:00', strtotime( '-' . ( $days - 1 ) . ' days' ) );
	$params = [ (int) $company_id, (int) $owner_id, $since ];
	$rows   = [];

	// Month buckets of the period (oldest first).
	$months = [];
	$cursor = strtotime( date( 'Y-m-01', strtotime( $since ) ) );

	// Partial months are labelled, so a short first / current month is not read as a drop.
	while ( $cursor <= time() ) {
		$label = mb_convert_case( ro_month_name( (int) date( 'n', $cursor ) ), MB_CASE_TITLE ) . ( date( 'Y', $cursor ) !== date( 'Y' ) ? ' ' . date( 'Y', $cursor ) : '' );

		if ( date( 'Y-m', $cursor ) === date( 'Y-m' ) ) {
			$label .= ' (până azi)';
		} elseif ( date( 'Y-m', $cursor ) === date( 'Y-m', strtotime( $since ) ) && '01' !== date( 'd', strtotime( $since ) ) ) {
			$label .= ' (din ' . (int) date( 'j', strtotime( $since ) ) . ')';
		}

		$months[ date( 'Y-m', $cursor ) ] = $label;
		$cursor                           = strtotime( '+1 month', $cursor );
	}

	$empty_row = function ( $slug, $label, $is_general, $created_at = null ) use ( $months, $days, $since ) {
		// Days the code existed inside the period (codes created before the period count the whole period).
		$active_days = $days;

		if ( null !== $created_at && strtotime( $created_at ) > strtotime( $since ) ) {
			$active_days = max( 1, (int) ceil( ( time() - strtotime( $created_at ) ) / 86400 ) );
		}

		return [
			'slug'        => $slug,
			'label'       => $label,
			'is_general'  => $is_general,
			'kind'        => $is_general ? 'general' : ref_tag_kind( $label ),
			'active_days' => min( $days, $active_days ),
			'is_new'      => $active_days < $days,
			'views'       => 0,
			'reviews'     => 0,
			'redirects'   => 0,
			'feedback'    => 0,
			'avg'         => 0.0,
			'positive'    => 0,
			'neutral'     => 0,
			'negative'    => 0,
			'months'      => array_fill_keys( array_keys( $months ), 0 ),
		];
	};

	foreach ( db_all( 'SELECT t.slug, t.label, t.is_active, t.created_at FROM ref_tags t INNER JOIN companies c ON c.id = t.company_id WHERE t.company_id = ? AND c.user_id = ? ORDER BY t.label ASC', [ (int) $company_id, (int) $owner_id ] ) as $tag ) {
		$rows[ $tag['slug'] ]              = $empty_row( $tag['slug'], $tag['label'], false, $tag['created_at'] );
		$rows[ $tag['slug'] ]['is_active'] = 1 === (int) $tag['is_active'];
	}

	$review_rows = db_all(
		"SELECT r.ref_tag, DATE_FORMAT(r.created_at, '%Y-%m') AS ym, COUNT(*) AS total,
			SUM(r.action_taken = 'redirected_google') AS redirects,
			SUM(r.action_taken = 'private_feedback') AS feedback,
			SUM(r.rating) AS rating_sum,
			SUM(r.rating >= 4) AS positive,
			SUM(r.rating = 3) AS neutral,
			SUM(r.rating <= 2) AS negative
		FROM reviews r INNER JOIN companies c ON c.id = r.company_id
		WHERE r.company_id = ? AND c.user_id = ? AND r.created_at >= ?
		GROUP BY r.ref_tag, ym",
		$params
	);

	$rating_sums = [];

	foreach ( $review_rows as $row ) {
		$slug = (string) $row['ref_tag'];

		if ( ! isset( $rows[ $slug ] ) ) {
			$rows[ $slug ] = '' === $slug ? $empty_row( '', 'Coduri generale (fără angajat)', true ) : $empty_row( $slug, format_ref_tag( $slug ), false );
		}

		$rows[ $slug ]['reviews']   += (int) $row['total'];
		$rows[ $slug ]['redirects'] += (int) $row['redirects'];
		$rows[ $slug ]['feedback']  += (int) $row['feedback'];
		$rows[ $slug ]['positive']  += (int) $row['positive'];
		$rows[ $slug ]['neutral']   += (int) $row['neutral'];
		$rows[ $slug ]['negative']  += (int) $row['negative'];
		$rating_sums[ $slug ]        = ( $rating_sums[ $slug ] ?? 0 ) + (int) $row['rating_sum'];

		if ( isset( $rows[ $slug ]['months'][ $row['ym'] ] ) ) {
			$rows[ $slug ]['months'][ $row['ym'] ] += (int) $row['total'];
		}
	}

	foreach ( db_all( 'SELECT v.ref_tag, COUNT(*) AS views FROM funnel_views v INNER JOIN companies c ON c.id = v.company_id WHERE v.company_id = ? AND c.user_id = ? AND v.created_at >= ? GROUP BY v.ref_tag', $params ) as $row ) {
		$slug = (string) $row['ref_tag'];

		if ( ! isset( $rows[ $slug ] ) ) {
			$rows[ $slug ] = '' === $slug ? $empty_row( '', 'Coduri generale (fără angajat)', true ) : $empty_row( $slug, format_ref_tag( $slug ), false );
		}

		$rows[ $slug ]['views'] = (int) $row['views'];
	}

	$total_reviews = 0;
	$total_views   = 0;
	$total_rating  = 0;

	foreach ( $rows as $slug => &$row ) {
		$row['avg']     = 0 < $row['reviews'] ? round( $rating_sums[ $slug ] / $row['reviews'], 2 ) : 0.0;
		$total_reviews += $row['reviews'];
		$total_views   += $row['views'];
		$total_rating  += $rating_sums[ $slug ] ?? 0;
	}
	unset( $row );

	// Employees first (most reviews first), general codes last; inactive codes without data are hidden.
	$rows = array_values(
		array_filter(
			$rows,
			function ( $row ) {
				return $row['is_general'] || ! isset( $row['is_active'] ) || $row['is_active'] || 0 < $row['reviews'] + $row['views'];
			}
		)
	);

	usort(
		$rows,
		function ( $a, $b ) {
			return [ $a['is_general'], $b['reviews'] ] <=> [ $b['is_general'], $a['reviews'] ];
		}
	);

	$company_avg = 0 < $total_reviews ? round( $total_rating / $total_reviews, 2 ) : 0.0;

	foreach ( $rows as &$row ) {
		$row['share']     = 0 < $total_reviews ? (int) round( $row['reviews'] / $total_reviews * 100 ) : 0;
		$row['happy_pct'] = 0 < $row['reviews'] ? (int) round( $row['positive'] / $row['reviews'] * 100 ) : 0;
		$row['flags']     = [];
		$row['pace']      = $row['reviews'] / max( 1, $row['active_days'] );
		$row['per_week']  = round( $row['views'] / max( 1, $row['active_days'] / 7 ), 1 );
		$row['too_new']   = ! $row['is_general'] && $row['active_days'] < TEAM_REPORT_MIN_DAYS;
		$row['reliable']  = $row['reviews'] >= TEAM_REPORT_MIN_REVIEWS;
	}
	unset( $row );

	$tagged      = array_values(
		array_filter(
			$rows,
			function ( $row ) {
				return ! $row['is_general'];
			}
		)
	);
	$comparable  = array_values(
		array_filter(
			$tagged,
			function ( $row ) {
				return ! $row['too_new'];
			}
		)
	);
	$tag_reviews = array_sum( array_column( $tagged, 'reviews' ) );

	// The leader sets the pace: the comparable code with the most evaluations per day of existence.
	usort(
		$comparable,
		function ( $a, $b ) {
			return [ $b['pace'], $b['reviews'] ] <=> [ $a['pace'], $a['reviews'] ];
		}
	);

	$leader    = $comparable[0] ?? null;
	$comparing = 2 <= count( $comparable ) && $leader['reviews'] >= TEAM_REPORT_MIN_LEADER;
	$flags     = [];
	$insights  = [];

	if ( 2 > count( $tagged ) ) {
		$insights[] = [
			'tone'  => 'info',
			'icon'  => 'bi-qr-code',
			'title' => 'Creează câte un cod QR pentru fiecare angajat sau departament',
			'text'  => 'Raportul compară codurile între ele. Cu cel puțin două coduri (ex: un cod pe ospătar sau pe tură) vezi imediat cine oferă codul clienților și unde sunt clienții mai puțin mulțumiți.',
		];
	} elseif ( 0 === $tag_reviews ) {
		$insights[] = [
			'tone'  => 'info',
			'icon'  => 'bi-hourglass-split',
			'title' => 'Încă nu există evaluări pe codurile echipei în această perioadă',
			'text'  => 'Printează codurile din Print Studio și roagă fiecare coleg să îl ofere clienților la final. Primele rezultate apar aici imediat după primele scanări.',
		];
	} elseif ( ! $comparing ) {
		$insights[] = [
			'tone'  => 'info',
			'icon'  => 'bi-hourglass-split',
			'title' => 'Încă prea puține evaluări pentru o comparație corectă',
			'text'  => 2 > count( $comparable )
				? 'Codurile sunt prea noi: le comparăm după ce au cel puțin ' . TEAM_REPORT_MIN_DAYS . ' zile. Până atunci, cifrele de mai jos sunt orientative.'
				: 'Codul cu cele mai multe evaluări („' . $leader['label'] . '”) are ' . ro_count( $leader['reviews'], 'evaluări' ) . '. Concluziile apar automat de la ' . TEAM_REPORT_MIN_LEADER . ' evaluări în perioada aleasă; până atunci, cifrele de mai jos sunt orientative și o diferență mică poate fi doar întâmplare.',
		];
	} else {
		// With codes of different ages the leader is the fastest, not necessarily the one with the biggest total.
		$most_total = max( array_column( $comparable, 'reviews' ) ) === $leader['reviews'];
		$per_day    = ' pe zi';

		$insights[] = [
			'tone'  => 'good',
			'icon'  => 'bi-trophy',
			'title' => '„' . $leader['label'] . '” aduce cele mai multe evaluări' . ( $most_total ? '' : $per_day ),
			'text'  => ro_count( $leader['reviews'], 'evaluări' ) . ( $leader['is_new'] ? ' în ' . ro_count( $leader['active_days'], 'zile' ) . ' de când există codul' : '' ) . ', cu o notă medie de ' . format_stars( $leader['avg'] ) . '★. ' . ( 'place' === $leader['kind'] ? 'Codul este scanat constant.' : 'Codul este oferit constant clienților.' ),
		];

		foreach ( $comparable as $row ) {
			if ( $row['slug'] === $leader['slug'] || $row['pace'] > $leader['pace'] * TEAM_REPORT_FEW_RATIO ) {
				continue;
			}

			$flags[ $row['slug'] ][] = 'few';
			$ratio                   = 0 < $row['pace'] ? $leader['pace'] / $row['pace'] : 0;
			$advice                  = [
				'person' => 'Cel mai probabil codul nu este oferit tuturor clienților. Discută cu colegul și stabiliți un moment fix în care îl prezintă (ex: la aducerea notei).',
				'place'  => 'Cel mai probabil codul nu se vede bine sau clienții nu ajung ușor la el. Verifică dacă este la vedere, curat și nedeteriorat.',
				'team'   => 'Cel mai probabil codul nu este prezentat tuturor clienților. Stabiliți împreună cu echipa un moment fix în care este oferit.',
			];

			$insights[] = [
				'tone'  => 'warning',
				'icon'  => 'bi-eye-slash',
				'title' => 0 < $row['reviews']
					? '„' . $row['label'] . '” aduce de ' . format_number( $ratio, $ratio < 10 ? 1 : 0 ) . ' ori mai puține evaluări' . ( $row['is_new'] || $leader['is_new'] ? $per_day : '' ) . ' decât „' . $leader['label'] . '”'
					: '„' . $row['label'] . '” nu a adus nicio evaluare',
				'text'  => 'Codul a fost scanat de ' . ro_count( $row['views'], 'ori' ) . ( $row['is_new'] ? ' în cele ' . ro_count( $row['active_days'], 'zile' ) . ' de când există' : '' ) . ' (≈ ' . format_number( $row['per_week'], 1 ) . ' pe săptămână), față de ≈ ' . format_number( $leader['per_week'], 1 ) . ' pe săptămână la „' . $leader['label'] . '”. ' . $advice[ $row['kind'] ],
			];
		}

		$best = null;

		foreach ( $comparable as $row ) {
			if ( $row['reliable'] && ( null === $best || $row['avg'] > $best['avg'] ) ) {
				$best = $row;
			}
		}

		if ( $best && $best['slug'] !== $leader['slug'] && $best['avg'] > $leader['avg'] ) {
			$insights[] = [
				'tone'  => 'good',
				'icon'  => 'bi-star',
				'title' => '„' . $best['label'] . '” are cei mai mulțumiți clienți',
				'text'  => 'Notă medie ' . format_stars( $best['avg'] ) . '★ și ' . $best['happy_pct'] . '% evaluări de 4–5 stele.',
			];
		}
	}

	// Ratings are judged for every code with enough evaluations, new ones included.
	if ( 2 <= count( $tagged ) ) {
		foreach ( $tagged as $row ) {
			if ( ! $row['reliable'] || $row['avg'] > $company_avg - TEAM_REPORT_LOW_GAP + 0.0001 ) {
				continue;
			}

			$flags[ $row['slug'] ][] = 'low';
			$unhappy                 = $row['negative'] + $row['neutral'];

			$insights[] = [
				'tone'  => 'danger',
				'icon'  => 'bi-emoji-frown',
				'title' => 'Clienții care au scanat codul „' . $row['label'] . '” sunt mai puțin mulțumiți',
				'text'  => 'Notă medie ' . format_stars( $row['avg'] ) . '★, față de media companiei de ' . format_stars( $company_avg ) . '★. ' . ro_count( $unhappy, 'clienți' ) . ' din ' . format_number( $row['reviews'] ) . ' au dat 3 stele sau mai puțin. Citește ce au scris ca să afli ce trebuie îmbunătățit.',
				'link'  => [ 'Citește feedback-ul primit', app_url( 'dashboard/reviews.php', [ 'company_id' => (int) $company_id, 'ref' => $row['slug'], 'type' => 'private_feedback' ] ) ],
			];
		}

		foreach ( $tagged as $row ) {
			if ( $row['too_new'] ) {
				$insights[] = [
					'tone'  => 'info',
					'icon'  => 'bi-stars',
					'title' => '„' . $row['label'] . '” este un cod nou',
					'text'  => 'Există de ' . ro_count( $row['active_days'], 'zile' ) . '. Îl comparăm cu restul codurilor după ' . TEAM_REPORT_MIN_DAYS . ' zile, ca să nu tragem concluzii pripite.',
				];
			}
		}
	}

	if ( $comparing && 1 === count( $insights ) ) {
		$insights[] = [
			'tone'  => 'good',
			'icon'  => 'bi-people',
			'title' => 'Echipa este echilibrată',
			'text'  => 'Toate codurile comparate aduc evaluări într-un ritm apropiat, iar notele sunt apropiate de media companiei (' . format_stars( $company_avg ) . '★). Continuați așa!',
		];
	}

	// Ranking labels: problems first, then "new" / "little data" / "too early", otherwise "ok".
	foreach ( $rows as &$row ) {
		if ( $row['is_general'] ) {
			continue;
		}

		$row['flags'] = $flags[ $row['slug'] ] ?? [];

		if ( $row['too_new'] ) {
			$row['flags'][] = 'new';
		} elseif ( ! $row['reliable'] ) {
			$row['flags'][] = 'little_data';
		} elseif ( ! $comparing ) {
			$row['flags'][] = 'pending';
		}

		if ( empty( $row['flags'] ) ) {
			$row['flags'][] = 'ok';
		}
	}
	unset( $row );

	return [
		'rows'          => $rows,
		'months'        => $months,
		'days'          => $days,
		'total_reviews' => $total_reviews,
		'total_views'   => $total_views,
		'company_avg'   => $company_avg,
		'tagged_count'  => count( $tagged ),
		'comparing'     => $comparing,
		'insights'      => $insights,
	];
}
