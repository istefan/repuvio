<?php
/**
 * Subscription, free trial and monthly quota engine (with soft-lock of private feedback).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

/**
 * Subscription status labels.
 *
 * @return array
 */
function get_subscription_statuses() {
	return [
		'trialing' => 'Perioadă de probă',
		'active'   => 'Activ',
		'past_due' => 'Plată restantă',
		'expired'  => 'Expirat',
		'canceled' => 'Anulat',
	];
}

/**
 * Loads a user by ID.
 *
 * @param int $user_id User ID.
 * @return array|null
 */
function get_user_by_id( $user_id ) {
	return db_row( 'SELECT * FROM users WHERE id = ?', [ (int) $user_id ] );
}

/**
 * Loads a plan.
 *
 * @param int $plan_id Plan ID.
 * @return array|null
 */
function get_plan( $plan_id ) {
	if ( empty( $plan_id ) ) {
		return null;
	}

	return db_row( 'SELECT * FROM plans WHERE id = ?', [ (int) $plan_id ] );
}

/**
 * All plans (optionally only active ones).
 *
 * @param bool $only_active Only active.
 * @return array
 */
function get_plans( $only_active = false ) {
	$sql = 'SELECT * FROM plans' . ( $only_active ? ' WHERE is_active = 1' : '' ) . ' ORDER BY sort_order ASC, price_ron ASC';

	return db_all( $sql );
}

/**
 * Billing cycle labels.
 *
 * @return array
 */
function get_billing_cycles() {
	return [
		'monthly' => 'Lunar',
		'yearly'  => 'Anual',
	];
}

/**
 * Plan price for a billing cycle.
 *
 * @param array  $plan  Plan row.
 * @param string $cycle monthly|yearly.
 * @return float
 */
function get_plan_price( array $plan, $cycle = 'monthly' ) {
	if ( 'yearly' === $cycle ) {
		return 0 < (float) $plan['price_yearly_ron'] ? (float) $plan['price_yearly_ron'] : (float) $plan['price_ron'] * 10;
	}

	return (float) $plan['price_ron'];
}

/**
 * Effective limits and features for an account: assigned plan (+ per-user overrides), otherwise trial defaults.
 * Operators inherit the limits of their business owner.
 *
 * custom_max_reviews = 0 means unlimited reviews (Enterprise custom deals).
 *
 * @param array $user User row.
 * @return array{max_reviews:int,unlimited_reviews:bool,max_companies:int,max_alert_emails:int,history_months:int,allow_ref_tags:bool,includes_onboarding:bool,plan_name:string,plan_slug:string,is_trial_limits:bool}
 */
function get_user_limits( array $user ) {
	if ( 'operator' === $user['role'] && ! empty( $user['parent_id'] ) ) {
		$owner = get_user_by_id( (int) $user['parent_id'] );

		if ( $owner ) {
			return get_user_limits( $owner );
		}
	}

	$plan = get_plan( $user['plan_id'] ?? 0 );

	if ( $plan ) {
		$limits = [
			'max_reviews'         => (int) $plan['max_reviews_per_month'],
			'unlimited_reviews'   => false,
			'max_companies'       => (int) $plan['max_companies'],
			'max_alert_emails'    => max( 1, (int) $plan['max_alert_emails'] ),
			'history_months'      => (int) $plan['history_months'],
			'allow_ref_tags'      => 1 === (int) $plan['allow_ref_tags'],
			'includes_onboarding' => 1 === (int) $plan['includes_onboarding'],
			'plan_name'           => (string) $plan['name'],
			'plan_slug'           => (string) $plan['slug'],
			'is_trial_limits'     => false,
		];
	} else {
		// Free trial: every feature unlocked (incl. employee QR tags) with trial quotas.
		$limits = [
			'max_reviews'         => get_setting_int( 'trial_max_reviews', 50 ),
			'unlimited_reviews'   => false,
			'max_companies'       => get_setting_int( 'trial_max_companies', 1 ),
			'max_alert_emails'    => max( 1, get_setting_int( 'trial_max_alert_emails', 1 ) ),
			'history_months'      => 0,
			'allow_ref_tags'      => true,
			'includes_onboarding' => false,
			'plan_name'           => 'Probă gratuită',
			'plan_slug'           => 'trial',
			'is_trial_limits'     => true,
		];
	}

	if ( isset( $user['custom_max_reviews'] ) && null !== $user['custom_max_reviews'] ) {
		$limits['max_reviews']       = (int) $user['custom_max_reviews'];
		$limits['unlimited_reviews'] = 0 === (int) $user['custom_max_reviews'];
	}

	if ( isset( $user['custom_max_companies'] ) && null !== $user['custom_max_companies'] && 0 < (int) $user['custom_max_companies'] ) {
		$limits['max_companies'] = (int) $user['custom_max_companies'];
	}

	return $limits;
}

/**
 * Whether the account may create employee / department QR tags (?ref=). Pro, Enterprise and trial.
 *
 * @param array $user User row (owner or operator).
 * @return bool
 */
function user_can_use_ref_tags( array $user ) {
	return get_user_limits( $user )['allow_ref_tags'];
}

/**
 * Whether the subscription (or trial) currently grants full access.
 *
 * @param array $user User row.
 * @return bool
 */
function is_subscription_active( array $user ) {
	if ( 'super_admin' === $user['role'] ) {
		return true;
	}

	if ( 'operator' === $user['role'] ) {
		$owner = empty( $user['parent_id'] ) ? null : get_user_by_id( (int) $user['parent_id'] );

		return $owner ? is_subscription_active( $owner ) : false;
	}

	if ( 'active' !== $user['status'] ) {
		return false;
	}

	$now = time();

	if ( 'trialing' === $user['subscription_status'] ) {
		return ! empty( $user['trial_ends_at'] ) && strtotime( $user['trial_ends_at'] ) > $now;
	}

	if ( 'active' === $user['subscription_status'] ) {
		return empty( $user['subscription_ends_at'] ) || strtotime( $user['subscription_ends_at'] ) > $now;
	}

	return false;
}

/**
 * Moves expired trials/subscriptions to "expired". Returns true when the status changed.
 *
 * @param array $user User row (updated by reference).
 * @return bool
 */
function refresh_subscription_status( array &$user ) {
	if ( 'user' !== $user['role'] ) {
		return false;
	}

	$now     = time();
	$expired = false;

	if ( 'trialing' === $user['subscription_status'] && ( empty( $user['trial_ends_at'] ) || strtotime( $user['trial_ends_at'] ) <= $now ) ) {
		$expired = true;
	}

	if ( in_array( $user['subscription_status'], [ 'active', 'past_due' ], true ) && ! empty( $user['subscription_ends_at'] ) && strtotime( $user['subscription_ends_at'] ) <= $now ) {
		$expired = true;
	}

	if ( ! $expired ) {
		return false;
	}

	db_query( "UPDATE users SET subscription_status = 'expired' WHERE id = ?", [ (int) $user['id'] ] );
	$user['subscription_status'] = 'expired';

	return true;
}

/**
 * Rolls the monthly quota cycle when a month has passed since quota_cycle_start.
 *
 * @param array $user User row (updated by reference).
 * @return bool True when the cycle was reset.
 */
function roll_quota_cycle( array &$user ) {
	$now   = new DateTime( 'now' );
	$start = new DateTime( $user['quota_cycle_start'] ?: $user['created_at'] );
	$moved = false;

	$next = ( clone $start )->modify( '+1 month' );

	while ( $next <= $now ) {
		$start = $next;
		$next  = ( clone $start )->modify( '+1 month' );
		$moved = true;
	}

	if ( ! $moved && ! empty( $user['quota_cycle_start'] ) ) {
		return false;
	}

	$start_db = $start->format( 'Y-m-d H:i:s' );

	if ( $moved ) {
		db_query(
			'UPDATE users SET quota_cycle_start = ?, reviews_used_this_cycle = 0, quota_alert_80_sent = 0, quota_alert_100_sent = 0 WHERE id = ?',
			[ $start_db, (int) $user['id'] ]
		);
		$user['reviews_used_this_cycle'] = 0;
		$user['quota_alert_80_sent']     = 0;
		$user['quota_alert_100_sent']    = 0;
	} else {
		db_query( 'UPDATE users SET quota_cycle_start = ? WHERE id = ?', [ $start_db, (int) $user['id'] ] );
	}

	$user['quota_cycle_start'] = $start_db;

	return $moved;
}

/**
 * Quota usage summary for the current cycle.
 *
 * @param array $user User row.
 * @return array{used:int,limit:int,unlimited:bool,percent:int,remaining:int,cycle_start:string,cycle_end:string}
 */
function get_quota_usage( array $user ) {
	$limits    = get_user_limits( $user );
	$used      = (int) $user['reviews_used_this_cycle'];
	$limit     = max( 0, $limits['max_reviews'] );
	$unlimited = $limits['unlimited_reviews'];
	$start     = $user['quota_cycle_start'] ?: $user['created_at'];

	if ( $unlimited ) {
		$percent   = 0;
		$remaining = PHP_INT_MAX;
	} else {
		$percent   = 0 < $limit ? (int) min( 100, round( $used / $limit * 100 ) ) : 100;
		$remaining = max( 0, $limit - $used );
	}

	return [
		'used'        => $used,
		'limit'       => $limit,
		'unlimited'   => $unlimited,
		'percent'     => $percent,
		'remaining'   => $remaining,
		'cycle_start' => $start,
		'cycle_end'   => ( new DateTime( $start ) )->modify( '+1 month' )->format( 'Y-m-d H:i:s' ),
	];
}

/**
 * Quota label such as "120 / 500" or "120 / nelimitat".
 *
 * @param array $usage get_quota_usage() result.
 * @return string
 */
function format_quota_usage( array $usage ) {
	return format_number( $usage['used'] ) . ' / ' . ( $usage['unlimited'] ? 'nelimitat' : format_number( $usage['limit'] ) );
}

/**
 * Whether new private feedback can be stored unlocked for this owner.
 *
 * @param array $user User row.
 * @return bool
 */
function can_receive_unlocked_feedback( array $user ) {
	if ( ! is_subscription_active( $user ) ) {
		return false;
	}

	$usage = get_quota_usage( $user );

	return $usage['unlimited'] || $usage['used'] < $usage['limit'];
}

/**
 * Atomically increments the quota counter and fires 80% / 100% alerts once per cycle.
 *
 * @param int $user_id Owner ID.
 * @return void
 */
function consume_quota( $user_id ) {
	db_query( 'UPDATE users SET reviews_used_this_cycle = reviews_used_this_cycle + 1 WHERE id = ?', [ (int) $user_id ] );

	$user = get_user_by_id( $user_id );

	if ( ! $user ) {
		return;
	}

	$usage = get_quota_usage( $user );

	if ( $usage['unlimited'] || 0 >= $usage['limit'] ) {
		return;
	}

	$percent = $usage['used'] / $usage['limit'] * 100;

	if ( 100 <= $percent ) {
		// Flag update is the lock: only the request that flips the flag sends the mail.
		$flipped = db_query( 'UPDATE users SET quota_alert_100_sent = 1, quota_alert_80_sent = 1 WHERE id = ? AND quota_alert_100_sent = 0', [ (int) $user_id ] )->rowCount();

		if ( 1 === $flipped ) {
			send_quota_alert_email( $user, 100, $usage['used'], $usage['limit'] );
		}
	} elseif ( 80 <= $percent ) {
		$flipped = db_query( 'UPDATE users SET quota_alert_80_sent = 1 WHERE id = ? AND quota_alert_80_sent = 0', [ (int) $user_id ] )->rowCount();

		if ( 1 === $flipped ) {
			send_quota_alert_email( $user, 80, $usage['used'], $usage['limit'] );
		}
	}
}

/**
 * Unlocks locked private feedback (oldest first) while the subscription is active and quota remains.
 *
 * @param int $user_id Owner ID.
 * @return int Number of unlocked reviews.
 */
function unlock_pending_reviews( $user_id ) {
	$user = get_user_by_id( $user_id );

	if ( ! $user || 'user' !== $user['role'] ) {
		return 0;
	}

	refresh_subscription_status( $user );
	roll_quota_cycle( $user );

	if ( ! is_subscription_active( $user ) ) {
		return 0;
	}

	$usage     = get_quota_usage( $user );
	$available = $usage['unlimited'] ? 1000 : $usage['limit'] - $usage['used'];

	if ( 0 >= $available ) {
		return 0;
	}

	$locked = db_all(
		'SELECT r.id FROM reviews r
		INNER JOIN companies c ON c.id = r.company_id
		WHERE c.user_id = ? AND r.is_locked = 1
		ORDER BY r.created_at ASC
		LIMIT ?',
		[ (int) $user_id, (int) $available ]
	);

	$unlocked = 0;

	foreach ( $locked as $row ) {
		$changed = db_query( 'UPDATE reviews SET is_locked = 0, counted_in_quota = 1 WHERE id = ? AND is_locked = 1', [ (int) $row['id'] ] )->rowCount();

		if ( 1 === $changed ) {
			consume_quota( $user_id );
			++$unlocked;
		}
	}

	return $unlocked;
}

/**
 * Number of locked reviews waiting for an owner.
 *
 * @param int $user_id Owner ID.
 * @return int
 */
function count_locked_reviews( $user_id ) {
	return (int) db_value(
		'SELECT COUNT(*) FROM reviews r INNER JOIN companies c ON c.id = r.company_id WHERE c.user_id = ? AND r.is_locked = 1',
		[ (int) $user_id ]
	);
}

/**
 * Starts the free trial for a new user.
 *
 * @param int $user_id User ID.
 * @return void
 */
function start_free_trial( $user_id ) {
	$days = max( 1, get_setting_int( 'trial_days', 30 ) );

	db_query(
		"UPDATE users SET subscription_status = 'trialing', trial_ends_at = ?, quota_cycle_start = NOW(), reviews_used_this_cycle = 0 WHERE id = ?",
		[ now_db( '+' . $days . ' days' ), (int) $user_id ]
	);
}

/**
 * Human readable subscription summary.
 *
 * @param array $user User row.
 * @return array{label:string,badge:string,ends_at:string|null,days_left:int|null} badge = rm-pill modifier class.
 */
function get_subscription_summary( array $user ) {
	$statuses = get_subscription_statuses();
	$active   = is_subscription_active( $user );
	$ends_at  = 'trialing' === $user['subscription_status'] ? $user['trial_ends_at'] : $user['subscription_ends_at'];
	$days     = null;

	if ( ! empty( $ends_at ) ) {
		$days = (int) max( 0, ceil( ( strtotime( $ends_at ) - time() ) / 86400 ) );
	}

	$label = $statuses[ $user['subscription_status'] ] ?? $user['subscription_status'];

	if ( ! $active && in_array( $user['subscription_status'], [ 'trialing', 'active' ], true ) ) {
		$label = 'Expirat';
	}

	return [
		'label'     => $label,
		'badge'     => $active ? ( 'trialing' === $user['subscription_status'] ? 'rm-pill-trial' : 'rm-pill-positive' ) : 'rm-pill-negative',
		'ends_at'   => $ends_at,
		'days_left' => $days,
	];
}

/**
 * Platform statistics for the Super Admin dashboard.
 *
 * @return array
 */
function get_platform_stats() {
	$mrr = (float) db_value(
		"SELECT COALESCE(SUM(CASE WHEN u.billing_cycle = 'yearly' THEN p.price_yearly_ron / 12 ELSE p.price_ron END), 0)
		FROM users u INNER JOIN plans p ON p.id = u.plan_id
		WHERE u.role = 'user' AND u.status = 'active' AND u.subscription_status = 'active'
		AND (u.subscription_ends_at IS NULL OR u.subscription_ends_at > NOW())"
	);

	return [
		'mrr'                  => $mrr,
		'total_users'          => (int) db_value( "SELECT COUNT(*) FROM users WHERE role = 'user'" ),
		'total_operators'      => (int) db_value( "SELECT COUNT(*) FROM users WHERE role = 'operator'" ),
		'active_trials'        => (int) db_value( "SELECT COUNT(*) FROM users WHERE role = 'user' AND subscription_status = 'trialing' AND trial_ends_at > NOW()" ),
		'active_subscriptions' => (int) db_value( "SELECT COUNT(*) FROM users WHERE role = 'user' AND subscription_status = 'active' AND (subscription_ends_at IS NULL OR subscription_ends_at > NOW())" ),
		'expired'              => (int) db_value( "SELECT COUNT(*) FROM users WHERE role = 'user' AND subscription_status IN ('expired','canceled','past_due')" ),
		'total_companies'      => (int) db_value( 'SELECT COUNT(*) FROM companies' ),
		'total_reviews'        => (int) db_value( 'SELECT COUNT(*) FROM reviews' ),
		'total_views'          => (int) db_value( 'SELECT COUNT(*) FROM funnel_views' ),
		'revenue_30d'          => (float) db_value( 'SELECT COALESCE(SUM(amount_ron), 0) FROM payments WHERE created_at >= NOW() - INTERVAL 30 DAY' ),
	];
}

/**
 * Subscription request status labels and pill classes.
 *
 * @return array status => [ label, pill class ]
 */
function get_request_statuses() {
	return [
		'pending'   => [ 'Nouă', 'rm-pill-trial' ],
		'invoiced'  => [ 'Proformă trimisă', 'rm-pill-neutral' ],
		'completed' => [ 'Activată', 'rm-pill-positive' ],
		'rejected'  => [ 'Respinsă', 'rm-pill-muted' ],
	];
}

/**
 * Stores a plan request (price and plan name are snapshotted from the DB).
 *
 * @param array  $user  Owner row.
 * @param array  $plan  Plan row.
 * @param string $cycle monthly|yearly.
 * @return int Request ID.
 */
function create_subscription_request( array $user, array $plan, $cycle ) {
	db_query(
		'INSERT INTO subscription_requests (user_id, plan_id, plan_name, billing_cycle, price_ron) VALUES (?, ?, ?, ?, ?)',
		[ (int) $user['id'], (int) $plan['id'], $plan['name'], 'yearly' === $cycle ? 'yearly' : 'monthly', number_format( get_plan_price( $plan, $cycle ), 2, '.', '' ) ]
	);

	return db_insert_id();
}

/**
 * Marks the open requests of a user as activated (called when a payment activates the subscription).
 *
 * @param int $user_id  Owner ID.
 * @param int $admin_id Super admin ID.
 * @return int Updated requests.
 */
function complete_open_subscription_requests( $user_id, $admin_id ) {
	return db_query(
		"UPDATE subscription_requests SET status = 'completed', processed_by = ?, processed_at = NOW()
		WHERE user_id = ? AND status IN ('pending', 'invoiced')",
		[ (int) $admin_id, (int) $user_id ]
	)->rowCount();
}

/**
 * Number of requests waiting for the Super Admin.
 *
 * @return int
 */
function count_open_subscription_requests() {
	return (int) db_value( "SELECT COUNT(*) FROM subscription_requests WHERE status IN ('pending', 'invoiced')" );
}

/**
 * Monthly cash-in from logged payments for the last N months (current month included).
 *
 * @param int $months Months.
 * @return array{labels:array,revenue:array,payments:array,keys:array}
 */
function get_revenue_series( $months = 12 ) {
	$rows = db_all(
		"SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, SUM(amount_ron) AS total, COUNT(*) AS payments
		FROM payments WHERE created_at >= ? GROUP BY ym",
		[ date( 'Y-m-01 00:00:00', strtotime( 'first day of -' . ( (int) $months - 1 ) . ' months' ) ) ]
	);
	$by_month = [];

	foreach ( $rows as $row ) {
		$by_month[ $row['ym'] ] = $row;
	}

	$series = [
		'labels'   => [],
		'revenue'  => [],
		'payments' => [],
		'keys'     => [],
	];

	for ( $i = (int) $months - 1; 0 <= $i; $i-- ) {
		$time = strtotime( 'first day of -' . $i . ' months' );
		$key  = date( 'Y-m', $time );

		$series['keys'][]     = $key;
		$series['labels'][]   = mb_substr( ro_month_name( (int) date( 'n', $time ) ), 0, 3 ) . ' ' . date( 'y', $time );
		$series['revenue'][]  = round( (float) ( $by_month[ $key ]['total'] ?? 0 ), 2 );
		$series['payments'][] = (int) ( $by_month[ $key ]['payments'] ?? 0 );
	}

	return $series;
}

/**
 * Active paid subscriptions and MRR contribution per plan.
 *
 * @return array Rows: name, subscribers, mrr.
 */
function get_mrr_by_plan() {
	return db_all(
		"SELECT p.name, COUNT(u.id) AS subscribers,
			COALESCE(SUM(CASE WHEN u.billing_cycle = 'yearly' THEN p.price_yearly_ron / 12 ELSE p.price_ron END), 0) AS mrr
		FROM plans p
		LEFT JOIN users u ON u.plan_id = p.id AND u.role = 'user' AND u.status = 'active' AND u.subscription_status = 'active'
			AND (u.subscription_ends_at IS NULL OR u.subscription_ends_at > NOW())
		GROUP BY p.id, p.name, p.sort_order
		ORDER BY p.sort_order ASC"
	);
}
