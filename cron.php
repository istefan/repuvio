<?php
/**
 * Maintenance job. Schedule it every hour (it is safe to run more often): every task below is
 * guarded so it happens only once per day / period, and the hourly runs drain the email queue.
 *
 * CLI:  php /path/to/cron.php
 * HTTP: https://domain/cron.php?token=CRON_TOKEN
 *
 * Tasks:
 * - rebuild the sales showcase accounts (first run of the day);
 * - expire trials / subscriptions (+ email), roll monthly quota cycles, unlock pending feedback;
 * - reminders 3 days before a trial or a paid subscription ends (once per expiry date);
 * - monthly ROI reports (previous month, once per owner);
 * - Daily Digest of the previous day (once per day, from `daily_digest_hour`);
 * - scheduled Google Drive backup (database / files) when `gdrive_next_backup_at` is due;
 * - send queued emails, at most `smtp_batch_limit_per_run` per run with `smtp_delay_ms` between them;
 * - purge stale rate limits, reset tokens and email logs older than 90 days.
 *
 * Emails created here are queued (email_logs.status = 'queued') and sent by process_email_queue().
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

define( 'REPUVIO_NO_SESSION', true );

require_once __DIR__ . '/includes/bootstrap.php';

if ( 'cli' !== PHP_SAPI ) {
	$token = input_get( 'token' );

	if ( '' === CRON_TOKEN || 'REPLACE_WITH_LONG_RANDOM_STRING' === CRON_TOKEN || ! hash_equals( CRON_TOKEN, $token ) ) {
		http_response_code( 403 );
		exit( 'Forbidden' );
	}

	header( 'Content-Type: text/plain; charset=utf-8' );
	send_no_cache_headers();
}

@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

try {
	$showcase_reset = maybe_reset_showcase_demo();
} catch ( Throwable $e ) {
	$showcase_reset = false;
	write_log( 'cron.log', 'Showcase reset failed: ' . $e->getMessage() );
}

$queue  = [ 'queue' => true ];
$report = [
	'expired'   => 0,
	'cycles'    => 0,
	'reminders' => 0,
	'unlocked'  => 0,
	'roi'       => 0,
	'digests'   => 0,
];

$owners = db_all( "SELECT * FROM users WHERE role = 'user' AND status = 'active'" );

foreach ( $owners as $owner ) {
	try {
		if ( refresh_subscription_status( $owner ) ) {
			++$report['expired'];
			send_subscription_expired_email( $owner, $queue );
		}

		if ( roll_quota_cycle( $owner ) ) {
			++$report['cycles'];
		}

		// Trial ending reminder, 3 days before (sent once).
		if ( 'trialing' === $owner['subscription_status'] && 0 === (int) $owner['trial_reminder_sent'] && ! empty( $owner['trial_ends_at'] ) ) {
			$seconds_left = strtotime( $owner['trial_ends_at'] ) - time();

			if ( 0 < $seconds_left && 3 * 86400 >= $seconds_left ) {
				$flipped = db_query( 'UPDATE users SET trial_reminder_sent = 1 WHERE id = ? AND trial_reminder_sent = 0', [ (int) $owner['id'] ] )->rowCount();

				if ( 1 === $flipped ) {
					send_trial_ending_email( $owner, (int) ceil( $seconds_left / 86400 ), $owner['trial_ends_at'], $queue );
					++$report['reminders'];
				}
			}
		}

		// Paid subscription ending reminder, 3 days before (once per expiry date: a renewal moves the date).
		if ( 'active' === $owner['subscription_status'] && ! empty( $owner['subscription_ends_at'] ) ) {
			$seconds_left = strtotime( $owner['subscription_ends_at'] ) - time();

			if ( 0 < $seconds_left && 3 * 86400 >= $seconds_left ) {
				$flipped = db_query(
					'UPDATE users SET subscription_reminder_for = ? WHERE id = ? AND (subscription_reminder_for IS NULL OR subscription_reminder_for <> ?)',
					[ $owner['subscription_ends_at'], (int) $owner['id'], $owner['subscription_ends_at'] ]
				)->rowCount();

				if ( 1 === $flipped ) {
					send_trial_ending_email( $owner, (int) ceil( $seconds_left / 86400 ), $owner['subscription_ends_at'], $queue );
					++$report['reminders'];
				}
			}
		}

		$report['unlocked'] += unlock_pending_reviews( (int) $owner['id'] );
	} catch ( Throwable $e ) {
		write_log( 'cron.log', 'User ' . (int) $owner['id'] . ' failed: ' . $e->getMessage() );
	}
}

try {
	$report['roi'] = send_monthly_roi_reports();
} catch ( Throwable $e ) {
	write_log( 'cron.log', 'ROI reports failed: ' . $e->getMessage() );
}

try {
	$digests           = maybe_run_daily_digests();
	$report['digests'] = null === $digests ? 0 : $digests['queued'];
} catch ( Throwable $e ) {
	write_log( 'cron.log', 'Daily digests failed: ' . $e->getMessage() );
}

try {
	$backup = maybe_run_scheduled_backup();
} catch ( Throwable $e ) {
	$backup = [
		'success' => false,
		'error'   => $e->getMessage(),
	];
	write_log( 'cron.log', 'Scheduled backup failed: ' . $e->getMessage() );
}

try {
	$mail_queue = process_email_queue();
} catch ( Throwable $e ) {
	$mail_queue = [
		'sent'      => 0,
		'failed'    => 0,
		'remaining' => 0,
	];
	write_log( 'cron.log', 'Email queue failed: ' . $e->getMessage() );
}

$purged_limits = db_query( 'DELETE FROM rate_limits WHERE updated_at < NOW() - INTERVAL 2 DAY AND (locked_until IS NULL OR locked_until < NOW())' )->rowCount();
$purged_resets = db_query( 'DELETE FROM password_resets WHERE expires_at < NOW() - INTERVAL 1 DAY OR used_at IS NOT NULL' )->rowCount();
$purged_emails = db_query( "DELETE FROM email_logs WHERE created_at < NOW() - INTERVAL 90 DAY AND status <> 'queued'" )->rowCount();

$summary = sprintf(
	'Cron OK: %s%s%d expired, %d quota cycles reset, %d expiry reminders, %d feedback unlocked, %d ROI reports, %d daily digests queued; emails %d sent / %d failed / %d still queued; %d rate limits / %d reset tokens / %d email logs purged.',
	$showcase_reset ? 'demo accounts reset, ' : '',
	null === $backup ? '' : ( $backup['success'] ? 'backup OK, ' : 'BACKUP FAILED (' . $backup['error'] . '), ' ),
	$report['expired'],
	$report['cycles'],
	$report['reminders'],
	$report['unlocked'],
	$report['roi'],
	$report['digests'],
	$mail_queue['sent'],
	$mail_queue['failed'],
	$mail_queue['remaining'],
	$purged_limits,
	$purged_resets,
	$purged_emails
);

write_log( 'cron.log', $summary );
echo $summary . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput -- plain text, numbers only.
