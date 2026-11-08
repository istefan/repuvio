<?php
/**
 * Notification senders built on the email template engine (send_templated_email()), review
 * notification delivery modes (instant / smart hybrid / daily digest) and the Daily Digest of the
 * previous calendar day.
 *
 * Every function returns the send_templated_email() result. Each email can be switched off and
 * edited in Admin → Email & Șabloane.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

/**
 * Wraps free-form content (no template) in the master layout: test email and other ad-hoc messages.
 *
 * @param string $title     Heading (plain text).
 * @param string $body_html Body HTML (already escaped).
 * @param string $cta_url   Optional button URL.
 * @param string $cta_label Optional button label.
 * @return string
 */
function render_email( $title, $body_html, $cta_url = '', $cta_label = '' ) {
	$accent = get_email_layout_settings()['accent_color'];
	$button = '';

	if ( '' !== $cta_url && '' !== $cta_label ) {
		$button = '<p style="margin:28px 0 8px;"><a href="' . esc_url( $cta_url ) . '" style="display:inline-block;background:' . esc_attr( $accent ) . ';color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">' . esc_html( $cta_label ) . '</a></p>';
	}

	return render_email_layout( '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">' . esc_html( $title ) . '</h1>' . $body_html . $button, $title );
}

/*
 * -----------------------------------------------------------------------------
 * Delivery modes for review notifications
 * -----------------------------------------------------------------------------
 */

/**
 * Review notification delivery modes (slug => [ label, description ]).
 *
 * @return array
 */
function get_review_delivery_modes() {
	return [
		'instant_all'       => [ 'Instantaneu pentru toate', 'Un email imediat la fiecare evaluare: feedback negativ și, dacă șablonul este pornit, redirecționările pozitive.' ],
		'smart_hybrid'      => [ 'Hibrid Smart (recomandat)', 'Feedback-ul negativ pleacă imediat, ca proprietarul să poată suna clientul. Restul activității vine o dată pe zi, dimineața, în rezumatul zilei precedente.' ],
		'daily_digest_only' => [ 'Doar rezumat zilnic', 'Niciun email în timpul zilei. Dimineața pleacă un singur email cu statisticile și toate mesajele private din ziua precedentă.' ],
	];
}

/**
 * Platform-wide delivery mode (Admin → Email & Șabloane → Reguli de expediere).
 *
 * @return string
 */
function get_global_delivery_mode() {
	$mode = (string) get_setting( 'review_email_delivery_mode', 'smart_hybrid' );

	return array_key_exists( $mode, get_review_delivery_modes() ) ? $mode : 'smart_hybrid';
}

/**
 * Effective delivery mode of a company (its own override, else the global one).
 *
 * @param array $company Company row.
 * @return string
 */
function get_company_delivery_mode( array $company ) {
	$mode = (string) ( $company['email_delivery_mode'] ?? 'global' );

	return array_key_exists( $mode, get_review_delivery_modes() ) ? $mode : get_global_delivery_mode();
}

/**
 * Returns notification recipients for a company, capped at the plan alert email count (fallback: owner email).
 *
 * @param array $company Company row.
 * @return array
 */
function get_company_notification_recipients( array $company ) {
	$owner  = get_user_by_id( (int) $company['user_id'] );
	$limit  = $owner ? get_user_limits( $owner )['max_alert_emails'] : 1;
	$emails = parse_email_list( $company['notification_emails'] ?? '', $limit );

	if ( empty( $emails ) && $owner ) {
		$emails[] = $owner['email'];
	}

	return $emails;
}

/**
 * Platform notification recipients: support email (+ optional extras) and every active super admin.
 *
 * @param array $extra Additional addresses (e.g. the legal contact email).
 * @return array
 */
function get_admin_notification_recipients( array $extra = [] ) {
	$recipients = parse_email_list( implode( ',', array_merge( [ (string) get_setting( 'support_email' ) ], $extra ) ), 10 );

	foreach ( db_all( "SELECT email FROM users WHERE role = 'super_admin' AND status = 'active'" ) as $admin ) {
		if ( ! in_array( $admin['email'], $recipients, true ) ) {
			$recipients[] = $admin['email'];
		}
	}

	return $recipients;
}

/**
 * Label of the QR code (?ref=) a review came from.
 *
 * @param int    $company_id Company ID.
 * @param string $ref_tag    Stored ref slug.
 * @return string
 */
function get_review_ref_label( $company_id, $ref_tag ) {
	if ( '' === (string) $ref_tag ) {
		return 'Cod general';
	}

	// Inactive tags keep their label in notifications.
	$label = db_value( 'SELECT label FROM ref_tags WHERE company_id = ? AND slug = ?', [ (int) $company_id, (string) $ref_tag ] );

	return format_ref_tag( $ref_tag, false === $label || null === $label ? null : (string) $label );
}

/**
 * Stars with the numeric value, e.g. "★★☆☆☆ (2/5)".
 *
 * @param int $rating Rating.
 * @return string
 */
function format_stars_label( $rating ) {
	return stars_text( (int) $rating ) . ' (' . (int) $rating . '/5)';
}

/**
 * Sends the right instant notification for a new review, following the company delivery mode:
 * - locked private feedback (quota / subscription) → quota_exceeded_softlock, always instant;
 * - private feedback → instant_negative_feedback in instant_all and smart_hybrid;
 * - Google redirect → instant_positive_review in instant_all (template off by default).
 * Everything else waits for the Daily Digest.
 *
 * @param array $company Company row.
 * @param array $review  Review row.
 * @return array|null send result, or null when nothing is sent now.
 */
function notify_new_review( array $company, array $review ) {
	$mode       = get_company_delivery_mode( $company );
	$owner      = get_user_by_id( (int) $company['user_id'] );
	$recipients = get_company_notification_recipients( $company );
	$owner_id   = $owner ? (int) $owner['id'] : null;

	if ( 'private_feedback' === $review['action_taken'] && 1 === (int) $review['is_locked'] ) {
		return send_templated_email(
			'quota_exceeded_softlock',
			$recipients,
			[
				'user_name'    => $owner['name'] ?? '',
				'company_name' => $company['name'],
				'stars'        => format_stars_label( $review['rating'] ),
				'plan_name'    => $owner ? get_user_limits( $owner )['plan_name'] : '',
				'billing_url'  => app_url( 'dashboard/billing.php' ),
			],
			$owner_id
		);
	}

	if ( 'private_feedback' === $review['action_taken'] ) {
		if ( 'daily_digest_only' === $mode ) {
			return null;
		}

		return send_templated_email(
			'instant_negative_feedback',
			$recipients,
			[
				'company_name'     => $company['name'],
				'stars'            => format_stars_label( $review['rating'] ),
				'customer_name'    => '' !== $review['customer_name'] ? $review['customer_name'] : '—',
				'customer_contact' => '' !== $review['customer_contact'] ? $review['customer_contact'] : '—',
				'message'          => (string) $review['message'],
				'ref_tag'          => get_review_ref_label( (int) $company['id'], $review['ref_tag'] ),
				'created_at'       => format_datetime( $review['created_at'] ),
				'feedback_url'     => app_url( 'dashboard/review-view.php', [ 'id' => (int) $review['id'] ] ),
			],
			$owner_id,
			[ 'reply_to' => sanitize_email_address( $review['customer_contact'] ) ]
		);
	}

	if ( 'instant_all' !== $mode || ! is_email_template_enabled( 'instant_positive_review' ) ) {
		return null;
	}

	return send_templated_email(
		'instant_positive_review',
		$recipients,
		[
			'company_name'  => $company['name'],
			'stars'         => format_stars_label( $review['rating'] ),
			'ref_tag'       => get_review_ref_label( (int) $company['id'], $review['ref_tag'] ),
			'created_at'    => format_datetime( $review['created_at'] ),
			'dashboard_url' => app_url( 'dashboard/index.php' ),
		],
		$owner_id
	);
}

/*
 * -----------------------------------------------------------------------------
 * Account, billing and admin emails
 * -----------------------------------------------------------------------------
 */

/**
 * Password reset email.
 *
 * @param array  $user  User row.
 * @param string $token Plain token.
 * @return array
 */
function send_password_reset_email( array $user, $token ) {
	return send_templated_email(
		'password_reset',
		$user['email'],
		[
			'user_name'  => $user['name'],
			'reset_url'  => app_url( 'auth/reset-password.php', [ 'token' => $token ] ),
			'expires_in' => '60 de minute',
			'ip_address' => PHP_SAPI === 'cli' ? '—' : client_ip(),
		],
		(int) $user['id']
	);
}

/**
 * Welcome email after registration, plus the "[Admin] Utilizator nou" alert.
 *
 * @param array $user User row.
 * @return array Result of the welcome email.
 */
function send_welcome_email( array $user ) {
	$result = send_templated_email(
		'welcome_trial',
		$user['email'],
		[
			'user_name'      => $user['name'],
			'user_email'     => $user['email'],
			'trial_days'     => (string) get_setting_int( 'trial_days', 30 ),
			'trial_ends_at'  => ! empty( $user['trial_ends_at'] ) ? format_date( $user['trial_ends_at'] ) : '—',
			'dashboard_url'  => app_url( 'dashboard/index.php' ),
			'onboarding_url' => app_url( 'dashboard/onboarding.php' ),
		],
		(int) $user['id']
	);

	$admins = get_admin_notification_recipients();

	if ( ! empty( $admins ) ) {
		send_templated_email(
			'admin_new_user_alert',
			$admins,
			[
				'user_name'       => $user['name'],
				'user_email'      => $user['email'],
				'registered_at'   => format_datetime( $user['created_at'] ?? now_db() ),
				'admin_users_url' => app_url( 'admin/users.php' ),
			],
			null,
			[ 'reply_to' => $user['email'] ]
		);
	}

	return $result;
}

/**
 * Invitation for a new operator account (initial password, forced change at first login).
 *
 * @param array  $operator      Operator row (name, email, id).
 * @param array  $owner         Owner row.
 * @param array  $company_names Assigned company names.
 * @param string $temp_password Initial password.
 * @return array
 */
function send_operator_invite_email( array $operator, array $owner, array $company_names, $temp_password ) {
	return send_templated_email(
		'operator_invite',
		$operator['email'],
		[
			'operator_name'  => $operator['name'],
			'operator_email' => $operator['email'],
			'owner_name'     => $owner['name'],
			'company_name'   => implode( ', ', $company_names ),
			'login_url'      => app_url( 'auth/login.php' ),
			'temp_password'  => $temp_password,
		],
		(int) $operator['id'],
		[ 'reply_to' => $owner['email'] ]
	);
}

/**
 * Quota threshold alert (80% or 100%).
 *
 * @param array $user    User row.
 * @param int   $percent 80 or 100.
 * @param int   $used    Used reviews.
 * @param int   $limit   Monthly limit.
 * @return array
 */
function send_quota_alert_email( array $user, $percent, $used, $limit ) {
	return send_templated_email(
		100 <= $percent ? 'quota_reached_100' : 'quota_warning_80',
		$user['email'],
		[
			'user_name'    => $user['name'],
			'plan_name'    => get_user_limits( $user )['plan_name'],
			'used_reviews' => format_number( $used ),
			'max_reviews'  => format_number( $limit ),
			'billing_url'  => app_url( 'dashboard/billing.php' ),
		],
		(int) $user['id']
	);
}

/**
 * Reminder 3 days before the trial or the paid subscription ends.
 *
 * @param array $user       User row.
 * @param int   $days_left  Days left.
 * @param string $expires_at Expiry datetime.
 * @param array $options    send_templated_email() options (queue).
 * @return array
 */
function send_trial_ending_email( array $user, $days_left, $expires_at = '', array $options = [] ) {
	$is_trial = 'trialing' === $user['subscription_status'];

	return send_templated_email(
		'trial_expiring_soon',
		$user['email'],
		[
			'user_name'   => $user['name'],
			'plan_name'   => $is_trial ? 'Perioada de probă' : 'Abonamentul ' . get_user_limits( $user )['plan_name'],
			'expires_at'  => format_date( '' !== $expires_at ? $expires_at : ( $is_trial ? $user['trial_ends_at'] : $user['subscription_ends_at'] ) ),
			'days_left'   => (string) max( 1, (int) $days_left ),
			'billing_url' => app_url( 'dashboard/billing.php' ),
		],
		(int) $user['id'],
		$options
	);
}

/**
 * Subscription (or trial) expired notice.
 *
 * @param array $user    User row.
 * @param array $options send_templated_email() options (queue).
 * @return array
 */
function send_subscription_expired_email( array $user, array $options = [] ) {
	return send_templated_email(
		'subscription_expired',
		$user['email'],
		[
			'user_name'   => $user['name'],
			'plan_name'   => empty( $user['plan_id'] ) ? 'Perioada de probă' : 'Abonamentul ' . get_user_limits( $user )['plan_name'],
			'billing_url' => app_url( 'dashboard/billing.php' ),
		],
		(int) $user['id'],
		$options
	);
}

/**
 * Platform admin notification (contact message, print order, plan request) via `admin_contact_or_order`.
 *
 * @param string $subject_type  What happened, e.g. "Comandă printuri #12".
 * @param array  $sender        name, email, phone.
 * @param string $details_html  Escaped HTML details.
 * @param string $admin_url     Link into the admin panel.
 * @param array  $extra_to      Extra recipients (e.g. the legal contact email).
 * @return array
 */
function send_admin_notification( $subject_type, array $sender, $details_html, $admin_url, array $extra_to = [] ) {
	$recipients = get_admin_notification_recipients( $extra_to );

	if ( empty( $recipients ) ) {
		return [
			'success'    => false,
			'error'      => 'Nu există destinatari administratori (setează Email suport).',
			'transcript' => [],
			'skipped'    => false,
			'queued'     => false,
		];
	}

	return send_templated_email(
		'admin_contact_or_order',
		$recipients,
		[
			'sender_name'  => $sender['name'] ?? '',
			'sender_email' => $sender['email'] ?? '',
			'sender_phone' => '' !== ( $sender['phone'] ?? '' ) ? $sender['phone'] : '—',
			'subject_type' => $subject_type,
			'details_html' => $details_html,
			'admin_url'    => $admin_url,
		],
		null,
		[ 'reply_to' => $sender['email'] ?? '' ]
	);
}

/**
 * Test email for the settings page (free-form, not a template).
 *
 * @param string $to Recipient.
 * @return array
 */
function send_test_email( $to ) {
	$body = '<p>Acesta este un email de test trimis la ' . esc_html( date( 'd.m.Y H:i:s' ) ) . ' folosind driverul <strong>' . esc_html( get_setting( 'mail_driver' ) ) . '</strong>.</p><p>Dacă îl citești, configurarea funcționează. ✅</p>';

	return send_mail( $to, 'Email de test — ' . app_name(), render_email( 'Email de test', $body ), '', [ 'debug' => true ] );
}

/*
 * -----------------------------------------------------------------------------
 * Daily Digest (previous calendar day)
 * -----------------------------------------------------------------------------
 */

/**
 * HTML table of private feedback for the digest (all values escaped).
 *
 * @param array $rows time, rating, name, contact, tag, message, locked, url.
 * @return string
 */
function build_feedback_digest_table( array $rows ) {
	if ( empty( $rows ) ) {
		return '<p style="font-size:14px;color:#64748B;margin:0;">Niciun feedback privat în această zi. 🎉</p>';
	}

	$cell = 'border-top:1px solid #E2E8F0;font-size:13px;vertical-align:top;';
	$html = '<table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="border:1px solid #E2E8F0;border-radius:10px;border-collapse:separate;">'
		. '<tr style="background:#F8FAFC;color:#64748B;font-size:12px;"><td>Ora</td><td>Stele</td><td>Client</td><td>Mesaj</td></tr>';

	foreach ( $rows as $row ) {
		$client = '' !== $row['name'] ? esc_html( $row['name'] ) : '—';

		if ( '' !== $row['contact'] ) {
			$client .= '<br><span style="color:#64748B;">' . esc_html( $row['contact'] ) . '</span>';
		}

		if ( '' !== $row['tag'] ) {
			$client .= '<br><span style="color:#64748B;font-size:12px;">Cod: ' . esc_html( $row['tag'] ) . '</span>';
		}

		$message = $row['locked']
			? '<em style="color:#64748B;">Mesaj blocat: limita lunară a fost atinsă sau abonamentul nu este activ.</em>'
			: esc_html( mb_strimwidth( (string) $row['message'], 0, 300, '…' ) ) . '<br><a href="' . esc_url( $row['url'] ) . '" style="color:#2563EB;font-size:12px;">Deschide tichetul</a>';

		$html .= '<tr><td style="' . $cell . 'white-space:nowrap;">' . esc_html( $row['time'] ) . '</td>'
			. '<td style="' . $cell . 'color:#B91C1C;white-space:nowrap;">' . esc_html( stars_text( (int) $row['rating'] ) ) . '</td>'
			. '<td style="' . $cell . '">' . $client . '</td>'
			. '<td style="' . $cell . '">' . $message . '</td></tr>';
	}

	return $html . '</table>';
}

/**
 * Activity of one company on one calendar day.
 *
 * @param array  $company Company row.
 * @param string $date    Y-m-d.
 * @return array{views:int,reviews:int,redirects:int,feedback:int,avg:float,rows:array}
 */
function get_daily_digest_data( array $company, $date ) {
	$from   = $date . ' 00:00:00';
	$to     = $date . ' 23:59:59';
	$params = [ (int) $company['id'], $from, $to ];
	$totals = db_row(
		"SELECT COUNT(*) AS reviews, SUM(action_taken = 'redirected_google') AS redirects, SUM(action_taken = 'private_feedback') AS feedback, AVG(rating) AS avg_rating
		FROM reviews WHERE company_id = ? AND created_at BETWEEN ? AND ?",
		$params
	);
	$rows   = [];

	foreach ( db_all( "SELECT * FROM reviews WHERE company_id = ? AND created_at BETWEEN ? AND ? AND action_taken = 'private_feedback' ORDER BY created_at ASC", $params ) as $review ) {
		$rows[] = [
			'time'    => date( 'H:i', strtotime( $review['created_at'] ) ),
			'rating'  => (int) $review['rating'],
			'name'    => 1 === (int) $review['is_locked'] ? '' : (string) $review['customer_name'],
			'contact' => 1 === (int) $review['is_locked'] ? '' : (string) $review['customer_contact'],
			'tag'     => '' !== $review['ref_tag'] ? get_review_ref_label( (int) $company['id'], $review['ref_tag'] ) : '',
			'message' => (string) $review['message'],
			'locked'  => 1 === (int) $review['is_locked'],
			'url'     => app_url( 'dashboard/review-view.php', [ 'id' => (int) $review['id'] ] ),
		];
	}

	return [
		'views'     => (int) db_value( 'SELECT COUNT(*) FROM funnel_views WHERE company_id = ? AND created_at BETWEEN ? AND ?', $params ),
		'reviews'   => (int) $totals['reviews'],
		'redirects' => (int) $totals['redirects'],
		'feedback'  => (int) $totals['feedback'],
		'avg'       => (float) $totals['avg_rating'],
		'rows'      => $rows,
	];
}

/**
 * Sends (or queues) the Daily Digest of one company for one day.
 *
 * Zero-spam rule: a day without any evaluation is not sent, unless options.force (manual admin test).
 *
 * @param array  $company Company row.
 * @param string $date    Y-m-d (default: yesterday).
 * @param array  $options recipients (override), force (bool), queue (bool), subject_prefix (string).
 * @return array send result + empty (bool).
 */
function send_daily_digest( array $company, $date = '', array $options = [] ) {
	$date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ? $date : date( 'Y-m-d', strtotime( 'yesterday' ) );
	$data = get_daily_digest_data( $company, $date );

	if ( 0 === $data['reviews'] && empty( $options['force'] ) ) {
		return [
			'success'    => true,
			'error'      => '',
			'transcript' => [],
			'skipped'    => true,
			'queued'     => false,
			'empty'      => true,
		];
	}

	$owner      = get_user_by_id( (int) $company['user_id'] );
	$recipients = $options['recipients'] ?? get_company_notification_recipients( $company );

	$result = send_templated_email(
		'daily_digest',
		$recipients,
		[
			'user_name'               => $owner['name'] ?? '',
			'company_name'            => $company['name'],
			'date_yesterday'          => format_date( $date ),
			'total_views'             => format_number( $data['views'] ),
			'google_redirects'        => format_number( $data['redirects'] ),
			'private_feedbacks_count' => format_number( $data['feedback'] ),
			'avg_rating'              => 0 < $data['reviews'] ? number_format( $data['avg'], 1, ',', '.' ) . '★' : '—',
			'feedbacks_table_html'    => build_feedback_digest_table( $data['rows'] ),
			'dashboard_url'           => app_url( 'dashboard/index.php' ),
		],
		$owner ? (int) $owner['id'] : null,
		[
			'queue'          => ! empty( $options['queue'] ),
			'force'          => ! empty( $options['force'] ),
			'subject_prefix' => $options['subject_prefix'] ?? '',
		]
	);

	return array_merge( $result, [ 'empty' => 0 === $data['reviews'] ] );
}

/**
 * Queues the Daily Digest of the given day for every eligible company: active owner (not a sales
 * showcase account), active company, delivery mode smart_hybrid or daily_digest_only.
 *
 * @param string $date Y-m-d.
 * @return array{queued:int,empty:int,skipped:int}
 */
function run_daily_digests( $date ) {
	$report = [
		'queued'  => 0,
		'empty'   => 0,
		'skipped' => 0,
	];

	if ( ! is_email_template_enabled( 'daily_digest' ) ) {
		return $report;
	}

	$companies = db_all(
		"SELECT c.* FROM companies c INNER JOIN users u ON u.id = c.user_id
		WHERE u.role = 'user' AND u.status = 'active' AND u.is_showcase = 0 AND c.is_active = 1
		ORDER BY c.id ASC"
	);

	foreach ( $companies as $company ) {
		if ( 'instant_all' === get_company_delivery_mode( $company ) ) {
			continue;
		}

		$result = send_daily_digest( $company, $date, [ 'queue' => true ] );

		if ( ! empty( $result['empty'] ) ) {
			++$report['empty'];
		} elseif ( $result['queued'] ) {
			++$report['queued'];
		} else {
			++$report['skipped'];
		}
	}

	return $report;
}

/**
 * Cron entry: once per day, from the configured hour, queues the digest of the previous day.
 *
 * @return array|null run_daily_digests() report, or null when it is not time yet / already done.
 */
function maybe_run_daily_digests() {
	$today = date( 'Y-m-d' );
	$hour  = max( 0, min( 23, get_setting_int( 'daily_digest_hour', 8 ) ) );

	if ( (int) date( 'G' ) < $hour || $today === get_setting( 'last_daily_digest_run_date', '' ) ) {
		return null;
	}

	// Mark first, so overlapping cron runs never queue the same day twice.
	update_setting( 'last_daily_digest_run_date', $today );

	return run_daily_digests( date( 'Y-m-d', strtotime( 'yesterday' ) ) );
}
