<?php
/**
 * Review / feedback queries (tenant-scoped, operator-restricted), CRM filters, plan history window,
 * dashboard analytics and the employee / source tag breakdown.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

/**
 * Ticket status labels.
 *
 * @return array
 */
function get_ticket_statuses() {
	return [
		'new'         => 'Nou',
		'in_progress' => 'În lucru',
		'resolved'    => 'Rezolvat',
	];
}

/**
 * Action labels.
 *
 * @return array
 */
function get_review_actions() {
	return [
		'redirected_google' => 'Redirecționat Google',
		'private_feedback'  => 'Feedback privat',
	];
}

/**
 * Loads a review only if the current user owns its company (super admins bypass).
 * Operators only reach reviews of their assigned companies.
 *
 * @param int $review_id Review ID.
 * @return array|null Review row with company_name, company_user_id, company_slug, ref_label.
 */
function get_owned_review( $review_id ) {
	$user = current_user();

	if ( ! $user || 0 >= (int) $review_id ) {
		return null;
	}

	$sql = 'SELECT r.*, c.name AS company_name, c.user_id AS company_user_id, c.slug AS company_slug, t.label AS ref_label
		FROM reviews r INNER JOIN companies c ON c.id = r.company_id
		LEFT JOIN ref_tags t ON t.company_id = r.company_id AND t.slug = r.ref_tag
		WHERE r.id = :review_id';

	$params = [ 'review_id' => (int) $review_id ];

	if ( 'super_admin' !== $user['role'] ) {
		$sql              .= ' AND c.user_id = :user_id';
		$params['user_id'] = get_account_owner_id( $user );

		list( $restriction, $restriction_params ) = company_restriction_sql( 'c' );

		$sql   .= $restriction;
		$params = array_merge( $params, $restriction_params );
	}

	return db_row( $sql, $params );
}

/**
 * Feedback history window of the owner's plan in months (0 = unlimited).
 *
 * @param int $user_id Owner ID.
 * @return int
 */
function get_history_months( $user_id ) {
	static $cache = [];

	if ( ! isset( $cache[ $user_id ] ) ) {
		$owner              = get_user_by_id( (int) $user_id );
		$cache[ $user_id ] = $owner ? (int) get_user_limits( $owner )['history_months'] : 0;
	}

	return $cache[ $user_id ];
}

/**
 * Builds the WHERE clause for CRM filters. All values are bound; never interpolated.
 * Applies the operator company restriction and the plan's feedback history window.
 *
 * @param int   $user_id Owner ID.
 * @param array $filters company_id, rating, type, status, ref, date_from, date_to, search.
 * @return array{0:string,1:array}
 */
function build_review_filters( $user_id, array $filters ) {
	list( $restriction, $restriction_params ) = company_restriction_sql( 'c' );

	$where  = [ 'c.user_id = :user_id' . $restriction ];
	$params = array_merge( [ 'user_id' => (int) $user_id ], $restriction_params );

	$history = get_history_months( (int) $user_id );

	if ( 0 < $history ) {
		$where[]           = 'r.created_at >= NOW() - INTERVAL :history MONTH';
		$params['history'] = $history;
	}

	if ( ! empty( $filters['ref'] ) ) {
		$where[]       = 'r.ref_tag = :ref';
		$params['ref'] = $filters['ref'];
	}

	if ( ! empty( $filters['company_id'] ) ) {
		$where[]              = 'r.company_id = :company_id';
		$params['company_id'] = (int) $filters['company_id'];
	}

	if ( ! empty( $filters['rating'] ) ) {
		$where[]          = 'r.rating = :rating';
		$params['rating'] = (int) $filters['rating'];
	}

	if ( ! empty( $filters['type'] ) && array_key_exists( $filters['type'], get_review_actions() ) ) {
		$where[]        = 'r.action_taken = :type';
		$params['type'] = $filters['type'];
	}

	if ( ! empty( $filters['status'] ) && array_key_exists( $filters['status'], get_ticket_statuses() ) ) {
		$where[]          = "r.action_taken = 'private_feedback' AND r.ticket_status = :status";
		$params['status'] = $filters['status'];
	}

	if ( ! empty( $filters['date_from'] ) ) {
		$where[]             = 'r.created_at >= :date_from';
		$params['date_from'] = $filters['date_from'] . ' 00:00:00';
	}

	if ( ! empty( $filters['date_to'] ) ) {
		$where[]           = 'r.created_at <= :date_to';
		$params['date_to'] = $filters['date_to'] . ' 23:59:59';
	}

	if ( ! empty( $filters['search'] ) ) {
		// Locked reviews are excluded from text search so masked content cannot be probed.
		$where[]          = '(r.is_locked = 0 AND (r.customer_name LIKE :search OR r.customer_contact LIKE :search2 OR r.message LIKE :search3))';
		$like             = '%' . str_replace( [ '\\', '%', '_' ], [ '\\\\', '\\%', '\\_' ], $filters['search'] ) . '%';
		$params['search'] = $like;
		$params['search2'] = $like;
		$params['search3'] = $like;
	}

	return [ implode( ' AND ', $where ), $params ];
}

/**
 * Reads and sanitizes CRM filters from the query string.
 *
 * @return array
 */
function get_review_filters_from_request() {
	return [
		'company_id' => sanitize_int_range( input_get( 'company_id', '0' ), 0, PHP_INT_MAX, 0 ),
		'rating'     => sanitize_int_range( input_get( 'rating', '0' ), 0, 5, 0 ),
		'type'       => array_key_exists( input_get( 'type' ), get_review_actions() ) ? input_get( 'type' ) : '',
		'status'     => array_key_exists( input_get( 'status' ), get_ticket_statuses() ) ? input_get( 'status' ) : '',
		'ref'        => sanitize_ref_tag( input_get( 'ref' ) ),
		'date_from'  => sanitize_date( input_get( 'date_from' ) ),
		'date_to'    => sanitize_date( input_get( 'date_to' ) ),
		'search'     => sanitize_text( input_get( 'search' ), 100 ),
	];
}

/**
 * Counts reviews matching filters.
 *
 * @param int   $user_id Owner.
 * @param array $filters Filters.
 * @return int
 */
function count_reviews( $user_id, array $filters ) {
	list( $where, $params ) = build_review_filters( $user_id, $filters );

	return (int) db_value( 'SELECT COUNT(*) FROM reviews r INNER JOIN companies c ON c.id = r.company_id WHERE ' . $where, $params );
}

/**
 * Lists reviews matching filters.
 *
 * @param int   $user_id Owner.
 * @param array $filters Filters.
 * @param int   $limit   Limit.
 * @param int   $offset  Offset.
 * @return array
 */
function get_reviews( $user_id, array $filters, $limit = 25, $offset = 0 ) {
	list( $where, $params ) = build_review_filters( $user_id, $filters );

	$params['limit']  = (int) $limit;
	$params['offset'] = (int) $offset;

	return db_all(
		'SELECT r.*, c.name AS company_name, t.label AS ref_label FROM reviews r
		INNER JOIN companies c ON c.id = r.company_id
		LEFT JOIN ref_tags t ON t.company_id = r.company_id AND t.slug = r.ref_tag
		WHERE ' . $where . '
		ORDER BY r.created_at DESC, r.id DESC
		LIMIT :limit OFFSET :offset',
		$params
	);
}

/**
 * Notes of a review (ownership must be checked before calling).
 *
 * @param int $review_id Review ID.
 * @return array
 */
function get_review_notes( $review_id ) {
	return db_all(
		'SELECT n.*, u.name AS author_name FROM review_notes n LEFT JOIN users u ON u.id = n.user_id WHERE n.review_id = ? ORDER BY n.created_at ASC',
		[ (int) $review_id ]
	);
}

/**
 * Scope clause for analytics (owner + optional company).
 *
 * @param int $user_id    Owner.
 * @param int $company_id Company (0 = all).
 * @return array{0:string,1:array}
 */
function analytics_scope( $user_id, $company_id ) {
	list( $restriction, $restriction_params ) = company_restriction_sql( 'c' );

	$sql    = 'c.user_id = :user_id' . $restriction;
	$params = array_merge( [ 'user_id' => (int) $user_id ], $restriction_params );

	if ( 0 < (int) $company_id ) {
		$sql                 .= ' AND c.id = :company_id';
		$params['company_id'] = (int) $company_id;
	}

	return [ $sql, $params ];
}

/**
 * Percentage change between two values.
 *
 * @param float $current  Current period.
 * @param float $previous Previous period.
 * @return int
 */
function trend_percent( $current, $previous ) {
	if ( 0.0 === (float) $previous ) {
		return 0.0 < (float) $current ? 100 : 0;
	}

	return (int) round( ( $current - $previous ) / $previous * 100 );
}

/**
 * KPI statistics for the last N days vs the previous N days.
 *
 * @param int $user_id    Owner.
 * @param int $company_id Company (0 = all).
 * @param int $days       Period length.
 * @return array
 */
function get_dashboard_stats( $user_id, $company_id, $days = 30 ) {
	list( $scope, $params ) = analytics_scope( $user_id, $company_id );

	$params['d1'] = (int) $days;
	$params['d2'] = (int) $days * 2;
	$params['d3'] = (int) $days;

	$views = db_row(
		'SELECT
			SUM(v.created_at >= NOW() - INTERVAL :d1 DAY) AS cur,
			SUM(v.created_at < NOW() - INTERVAL :d3 DAY) AS prev
		FROM funnel_views v INNER JOIN companies c ON c.id = v.company_id
		WHERE ' . $scope . ' AND v.created_at >= NOW() - INTERVAL :d2 DAY',
		$params
	);

	$reviews = db_row(
		"SELECT
			SUM(r.action_taken = 'redirected_google' AND r.created_at >= NOW() - INTERVAL :d1 DAY) AS redirects_cur,
			SUM(r.action_taken = 'redirected_google' AND r.created_at < NOW() - INTERVAL :d3 DAY) AS redirects_prev,
			SUM(r.action_taken = 'private_feedback' AND r.created_at >= NOW() - INTERVAL :d1b DAY) AS feedback_cur,
			SUM(r.action_taken = 'private_feedback' AND r.created_at < NOW() - INTERVAL :d3b DAY) AS feedback_prev,
			AVG(CASE WHEN r.created_at >= NOW() - INTERVAL :d1c DAY THEN r.rating END) AS avg_cur,
			AVG(CASE WHEN r.created_at < NOW() - INTERVAL :d3c DAY THEN r.rating END) AS avg_prev
		FROM reviews r INNER JOIN companies c ON c.id = r.company_id
		WHERE " . $scope . ' AND r.created_at >= NOW() - INTERVAL :d2 DAY',
		array_merge(
			$params,
			[
				'd1b' => (int) $days,
				'd3b' => (int) $days,
				'd1c' => (int) $days,
				'd3c' => (int) $days,
			]
		)
	);

	$views_cur      = (int) ( $views['cur'] ?? 0 );
	$views_prev     = (int) ( $views['prev'] ?? 0 );
	$redirects_cur  = (int) ( $reviews['redirects_cur'] ?? 0 );
	$redirects_prev = (int) ( $reviews['redirects_prev'] ?? 0 );
	$feedback_cur   = (int) ( $reviews['feedback_cur'] ?? 0 );
	$feedback_prev  = (int) ( $reviews['feedback_prev'] ?? 0 );
	$avg_cur        = null === ( $reviews['avg_cur'] ?? null ) ? 0.0 : round( (float) $reviews['avg_cur'], 2 );
	$avg_prev       = null === ( $reviews['avg_prev'] ?? null ) ? 0.0 : round( (float) $reviews['avg_prev'], 2 );

	$conversion = 0 < $views_cur ? round( ( $redirects_cur + $feedback_cur ) / $views_cur * 100 ) : 0;

	return [
		'views'           => $views_cur,
		'views_trend'     => trend_percent( $views_cur, $views_prev ),
		'redirects'       => $redirects_cur,
		'redirects_trend' => trend_percent( $redirects_cur, $redirects_prev ),
		'feedback'        => $feedback_cur,
		'feedback_trend'  => trend_percent( $feedback_cur, $feedback_prev ),
		'avg_rating'      => $avg_cur,
		'avg_trend'       => trend_percent( $avg_cur, $avg_prev ),
		'conversion'      => (int) $conversion,
	];
}

/**
 * Daily series for the area chart.
 *
 * @param int $user_id    Owner.
 * @param int $company_id Company (0 = all).
 * @param int $days       Number of days.
 * @return array{labels:array,views:array,redirects:array,feedback:array}
 */
function get_daily_series( $user_id, $company_id, $days = 30 ) {
	list( $scope, $params ) = analytics_scope( $user_id, $company_id );

	$params['days'] = (int) $days - 1;

	$view_rows = db_all(
		'SELECT DATE(v.created_at) AS day, COUNT(*) AS total
		FROM funnel_views v INNER JOIN companies c ON c.id = v.company_id
		WHERE ' . $scope . ' AND v.created_at >= CURDATE() - INTERVAL :days DAY
		GROUP BY DATE(v.created_at)',
		$params
	);

	$review_rows = db_all(
		"SELECT DATE(r.created_at) AS day,
			SUM(r.action_taken = 'redirected_google') AS redirects,
			SUM(r.action_taken = 'private_feedback') AS feedback
		FROM reviews r INNER JOIN companies c ON c.id = r.company_id
		WHERE " . $scope . ' AND r.created_at >= CURDATE() - INTERVAL :days DAY
		GROUP BY DATE(r.created_at)',
		$params
	);

	$views_by_day   = array_column( $view_rows, 'total', 'day' );
	$reviews_by_day = [];

	foreach ( $review_rows as $row ) {
		$reviews_by_day[ $row['day'] ] = $row;
	}

	$series = [
		'labels'    => [],
		'views'     => [],
		'redirects' => [],
		'feedback'  => [],
	];

	for ( $i = $days - 1; 0 <= $i; $i-- ) {
		$day = date( 'Y-m-d', strtotime( '-' . $i . ' days' ) );

		$series['labels'][]    = date( 'd.m', strtotime( $day ) );
		$series['views'][]     = (int) ( $views_by_day[ $day ] ?? 0 );
		$series['redirects'][] = (int) ( $reviews_by_day[ $day ]['redirects'] ?? 0 );
		$series['feedback'][]  = (int) ( $reviews_by_day[ $day ]['feedback'] ?? 0 );
	}

	return $series;
}

/**
 * Star distribution (1-5) for the last N days.
 *
 * @param int $user_id    Owner.
 * @param int $company_id Company.
 * @param int $days       Days.
 * @return array<int,int> Index 0 => 1 star ... index 4 => 5 stars.
 */
function get_star_distribution( $user_id, $company_id, $days = 30 ) {
	list( $scope, $params ) = analytics_scope( $user_id, $company_id );

	$params['days'] = (int) $days;

	$rows = db_all(
		'SELECT r.rating, COUNT(*) AS total FROM reviews r INNER JOIN companies c ON c.id = r.company_id
		WHERE ' . $scope . ' AND r.created_at >= NOW() - INTERVAL :days DAY GROUP BY r.rating',
		$params
	);

	$distribution = [ 0, 0, 0, 0, 0 ];

	foreach ( $rows as $row ) {
		$rating = (int) $row['rating'];
		if ( 1 <= $rating && 5 >= $rating ) {
			$distribution[ $rating - 1 ] = (int) $row['total'];
		}
	}

	return $distribution;
}

/**
 * Most recent reviews for the dashboard table.
 *
 * @param int $user_id    Owner.
 * @param int $company_id Company.
 * @param int $limit      Limit.
 * @return array
 */
function get_recent_reviews( $user_id, $company_id, $limit = 8 ) {
	return get_reviews( $user_id, [ 'company_id' => (int) $company_id ], $limit, 0 );
}

/**
 * New (unhandled) private feedback for the notification bell.
 *
 * @param int $user_id Owner.
 * @param int $limit   Limit.
 * @return array{count:int,items:array}
 */
function get_feedback_notifications( $user_id, $limit = 5 ) {
	list( $scope, $params ) = analytics_scope( $user_id, 0 );

	$count = (int) db_value(
		"SELECT COUNT(*) FROM reviews r INNER JOIN companies c ON c.id = r.company_id
		WHERE " . $scope . " AND r.action_taken = 'private_feedback' AND r.ticket_status = 'new'",
		$params
	);

	$params['limit'] = (int) $limit;

	$items = db_all(
		"SELECT r.id, r.rating, r.is_locked, r.created_at, c.name AS company_name
		FROM reviews r INNER JOIN companies c ON c.id = r.company_id
		WHERE " . $scope . " AND r.action_taken = 'private_feedback' AND r.ticket_status = 'new'
		ORDER BY r.created_at DESC LIMIT :limit",
		$params
	);

	return [
		'count' => $count,
		'items' => $items,
	];
}

/**
 * Performance per employee / department / source tag (?ref=) for the last N days.
 *
 * @param int $user_id    Owner.
 * @param int $company_id Company (0 = all).
 * @param int $days       Days.
 * @return array Rows: company_name, ref_tag, label, views, redirects, feedback, avg_rating.
 */
function get_ref_tag_breakdown( $user_id, $company_id, $days = 30 ) {
	list( $scope, $params ) = analytics_scope( $user_id, $company_id );

	$params['days'] = (int) $days;

	$review_rows = db_all(
		"SELECT r.company_id, r.ref_tag, c.name AS company_name,
			SUM(r.action_taken = 'redirected_google') AS redirects,
			SUM(r.action_taken = 'private_feedback') AS feedback,
			AVG(r.rating) AS avg_rating
		FROM reviews r INNER JOIN companies c ON c.id = r.company_id
		WHERE " . $scope . " AND r.ref_tag <> '' AND r.created_at >= NOW() - INTERVAL :days DAY
		GROUP BY r.company_id, r.ref_tag, c.name",
		$params
	);

	$view_rows = db_all(
		"SELECT v.company_id, v.ref_tag, c.name AS company_name, COUNT(*) AS views
		FROM funnel_views v INNER JOIN companies c ON c.id = v.company_id
		WHERE " . $scope . " AND v.ref_tag <> '' AND v.created_at >= NOW() - INTERVAL :days DAY
		GROUP BY v.company_id, v.ref_tag, c.name",
		$params
	);

	$rows = [];

	foreach ( $view_rows as $row ) {
		$key          = $row['company_id'] . '|' . $row['ref_tag'];
		$rows[ $key ] = [
			'company_id'   => (int) $row['company_id'],
			'company_name' => $row['company_name'],
			'ref_tag'      => $row['ref_tag'],
			'views'        => (int) $row['views'],
			'redirects'    => 0,
			'feedback'     => 0,
			'avg_rating'   => 0.0,
		];
	}

	foreach ( $review_rows as $row ) {
		$key = $row['company_id'] . '|' . $row['ref_tag'];

		if ( ! isset( $rows[ $key ] ) ) {
			$rows[ $key ] = [
				'company_id'   => (int) $row['company_id'],
				'company_name' => $row['company_name'],
				'ref_tag'      => $row['ref_tag'],
				'views'        => 0,
			];
		}

		$rows[ $key ]['redirects']  = (int) $row['redirects'];
		$rows[ $key ]['feedback']   = (int) $row['feedback'];
		$rows[ $key ]['avg_rating'] = round( (float) $row['avg_rating'], 2 );
	}

	$labels = [];

	foreach ( db_all( 'SELECT t.company_id, t.slug, t.label FROM ref_tags t INNER JOIN companies c ON c.id = t.company_id WHERE ' . $scope, analytics_scope( $user_id, $company_id )[1] ) as $tag ) {
		$labels[ $tag['company_id'] . '|' . $tag['slug'] ] = $tag['label'];
	}

	foreach ( $rows as $key => &$row ) {
		$row['label'] = format_ref_tag( $row['ref_tag'], $labels[ $key ] ?? null );
	}
	unset( $row );

	usort(
		$rows,
		function ( $a, $b ) {
			return ( $b['redirects'] + $b['feedback'] ) <=> ( $a['redirects'] + $a['feedback'] );
		}
	);

	return $rows;
}

/**
 * Rating badge HTML (escaped).
 *
 * @param int $rating Rating.
 * @return string
 */
function rating_badge( $rating ) {
	$rating = (int) $rating;
	$class  = 4 <= $rating ? 'rm-pill-positive' : ( 3 === $rating ? 'rm-pill-neutral' : 'rm-pill-negative' );

	return '<span class="rm-pill ' . esc_attr( $class ) . '"><i class="bi bi-star-fill"></i> ' . esc_html( $rating ) . '</span>';
}

/**
 * Status badge HTML (escaped).
 *
 * @param string $status Ticket status.
 * @return string
 */
function ticket_status_badge( $status ) {
	$labels  = get_ticket_statuses();
	$classes = [
		'new'         => 'rm-pill-trial',
		'in_progress' => 'rm-pill-neutral',
		'resolved'    => 'rm-pill-positive',
	];

	return '<span class="rm-pill ' . esc_attr( $classes[ $status ] ?? 'rm-pill-muted' ) . '">' . esc_html( $labels[ $status ] ?? $status ) . '</span>';
}
