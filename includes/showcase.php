<?php
/**
 * Sales showcase ("Conturi demo pentru prezentări"): one business owner on the Pro plan and one
 * operator, rebuilt from scratch every day by cron.php (or on demand from Admin → Setări → Cont demo).
 *
 * The owner gets a single company without logo / cover (the presenter uploads them live), 90 days of
 * funnel views and reviews and two employee QR codes that tell a clear story: one employee hands out
 * the code to every guest (many, good reviews), the other avoids it (few scans, weaker ratings).
 *
 * Showcase accounts are flagged with users.is_showcase = 1. Emails addressed to them never leave:
 * send_mail() redirects them to the "showcase_notify_email" setting (or drops them when it is empty).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

/**
 * Default showcase settings.
 *
 * @return array
 */
function get_showcase_defaults() {
	return [
		'showcase_enabled'        => '0',
		'showcase_owner_email'    => 'proprietar@repuvio.ro',
		'showcase_owner_name'     => 'Alexandru Marin',
		'showcase_operator_email' => 'operator@repuvio.ro',
		'showcase_operator_name'  => 'Ioana Radu',
		'showcase_company_name'   => 'Bistro Livada Demo',
		'showcase_google_url'     => '',
		'showcase_notify_email'   => '',
		'showcase_password_hash'  => '',
		'showcase_last_reset'     => '',
	];
}

/**
 * Reads a showcase setting with its default.
 *
 * @param string $key Setting key.
 * @return string
 */
function get_showcase_setting( $key ) {
	$defaults = get_showcase_defaults();

	return (string) get_setting( $key, $defaults[ $key ] ?? '' );
}

/**
 * Whether the daily showcase reset is switched on.
 *
 * @return bool
 */
function is_showcase_enabled() {
	return '1' === get_showcase_setting( 'showcase_enabled' );
}

/**
 * Whether a user row is a showcase (sales demo) account.
 *
 * @param array|null $user User row.
 * @return bool
 */
function is_showcase_user( $user ) {
	return is_array( $user ) && ! empty( $user['is_showcase'] );
}

/**
 * Lower-cased emails of every showcase account (cached per request).
 *
 * @param bool $refresh Reload from the database.
 * @return string[]
 */
function get_showcase_emails( $refresh = false ) {
	static $emails = null;

	if ( null === $emails || $refresh ) {
		try {
			$emails = array_map( 'strtolower', array_column( db_all( 'SELECT email FROM users WHERE is_showcase = 1' ), 'email' ) );
		} catch ( Throwable $e ) {
			$emails = []; // Column missing before migration v8.
		}
	}

	return $emails;
}

/**
 * Replaces showcase recipients with the configured notification address. Used by send_mail().
 *
 * @param string[] $recipients Sanitized recipients.
 * @return array{recipients:string[],redirected:bool}
 */
function showcase_filter_recipients( array $recipients ) {
	$showcase = get_showcase_emails();

	if ( empty( $showcase ) ) {
		return [
			'recipients' => $recipients,
			'redirected' => false,
		];
	}

	$notify     = sanitize_email_address( get_showcase_setting( 'showcase_notify_email' ) );
	$filtered   = [];
	$redirected = false;

	foreach ( $recipients as $recipient ) {
		if ( in_array( strtolower( $recipient ), $showcase, true ) ) {
			$redirected = true;

			if ( '' !== $notify ) {
				$filtered[] = $notify;
			}

			continue;
		}

		$filtered[] = $recipient;
	}

	return [
		'recipients' => array_values( array_unique( $filtered ) ),
		'redirected' => $redirected,
	];
}

/**
 * Rebuilds the showcase on the first cron run of each day.
 *
 * @return bool True when a reset ran.
 */
function maybe_reset_showcase_demo() {
	if ( ! is_showcase_enabled() || date( 'Y-m-d' ) === substr( get_showcase_setting( 'showcase_last_reset' ), 0, 10 ) ) {
		return false;
	}

	$result = reset_showcase_demo();

	if ( ! $result['success'] ) {
		write_log( 'cron.log', 'Showcase reset failed: ' . $result['error'] );
	}

	return $result['success'];
}

/**
 * Finds or creates one showcase account and resets its profile fields.
 *
 * @param string $email   Email.
 * @param array  $fields  Column => value for the reset.
 * @return int User ID.
 * @throws RuntimeException When the email belongs to a real account.
 */
function showcase_upsert_user( $email, array $fields ) {
	$existing = db_row( 'SELECT id, is_showcase FROM users WHERE email = ?', [ $email ] );

	if ( $existing && 1 !== (int) $existing['is_showcase'] ) {
		throw new RuntimeException( 'Adresa ' . $email . ' aparține unui cont real. Alege altă adresă pentru contul demo.' );
	}

	$fields['email']       = $email;
	$fields['is_showcase'] = 1;
	$columns               = array_keys( $fields );

	if ( $existing ) {
		db_query(
			'UPDATE users SET ' . implode( ', ', array_map( fn( $column ) => $column . ' = ?', $columns ) ) . ' WHERE id = ?',
			array_merge( array_values( $fields ), [ (int) $existing['id'] ] )
		);

		return (int) $existing['id'];
	}

	db_query(
		'INSERT INTO users (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', array_fill( 0, count( $columns ), '?' ) ) . ')',
		array_values( $fields )
	);

	return db_insert_id();
}

/**
 * Fictitious, checksum-valid Romanian fiscal code for the showcase company.
 *
 * @param string $seed Seed text.
 * @return string
 */
function showcase_cui( $seed ) {
	$body = (string) ( 1000000 + hexdec( substr( hash( 'sha256', 'showcase-' . $seed ), 0, 6 ) ) % 8999999 );

	for ( $control = 0; 9 >= $control; $control++ ) {
		if ( is_valid_romanian_cui( $body . $control ) ) {
			return $body . $control;
		}
	}

	return $body . '0';
}

/**
 * Picks a rating using weights for 1..5 stars.
 *
 * @param int[] $weights Five weights.
 * @return int
 */
function showcase_rating( array $weights ) {
	$roll = mt_rand( 1, array_sum( $weights ) );

	foreach ( $weights as $index => $weight ) {
		$roll -= $weight;

		if ( 0 >= $roll ) {
			return $index + 1;
		}
	}

	return 5;
}

/**
 * Random moment during opening hours, N days ago (never in the future).
 *
 * @param int $days_ago Days ago.
 * @return string
 */
function showcase_datetime( $days_ago ) {
	$timestamp = strtotime( 'today' ) - $days_ago * 86400 + mt_rand( 11 * 3600, 23 * 3600 );

	return date( 'Y-m-d H:i:s', min( $timestamp, time() - mt_rand( 300, 5400 ) ) );
}

/**
 * Story behind the demo data: who scans, how often and how happy the guests are.
 * views = average QR scans per day, rate = % of scans that end with a star rating.
 *
 * @return array
 */
function get_showcase_story() {
	return [
		[
			'label'    => 'Ospătar Andreea',
			'views'    => 3.4,
			'rate'     => 62,
			'weights'  => [ 1, 1, 2, 10, 38 ],
			'messages' => [
				'Mâncarea a fost bună, dar desertul a întârziat destul de mult.',
				'Porția de paste a fost mai mică decât ne așteptam pentru preț.',
				'Masa noastră era lângă ușă și a fost curent tot timpul.',
			],
		],
		[
			'label'    => 'Ospătar Mihai',
			'views'    => 0.75,
			'rate'     => 55,
			'weights'  => [ 6, 6, 7, 8, 7 ],
			'messages' => [
				'Am așteptat 25 de minute pe terasă până să ne ia cineva comanda.',
				'Ospătarul a fost nepoliticos când am cerut să schimbăm masa.',
				'Am primit nota greșită și a durat mult până s-a corectat.',
				'Nimeni nu a mai trecut pe la masă după ce am primit mâncarea.',
				'Comanda a venit incompletă, iar băuturile au venit la final.',
				'Ospătarul părea grăbit și nu ne-a recomandat nimic din meniu.',
			],
		],
		[
			'label'    => '',
			'views'    => 2.2,
			'rate'     => 52,
			'weights'  => [ 1, 2, 3, 12, 26 ],
			'messages' => [
				'Muzica a fost prea tare, nu ne puteam auzi.',
				'Toaleta nu era curată.',
				'Locurile de parcare din spate erau toate ocupate.',
			],
		],
	];
}

/**
 * Deletes and rebuilds the whole showcase (accounts, company, QR codes, 90 days of data).
 *
 * @return array{success:bool,error:string,summary:string}
 */
function reset_showcase_demo() {
	$owner_email    = sanitize_email_address( get_showcase_setting( 'showcase_owner_email' ) );
	$operator_email = sanitize_email_address( get_showcase_setting( 'showcase_operator_email' ) );
	$password_hash  = get_showcase_setting( 'showcase_password_hash' );
	$plans          = array_column( get_plans(), null, 'slug' );
	$plan           = $plans['pro'] ?? null;

	if ( ! $plan ) {
		foreach ( $plans as $candidate ) {
			if ( 1 === (int) $candidate['allow_ref_tags'] ) {
				$plan = $candidate;
				break;
			}
		}
	}

	$error = '';

	if ( '' === $owner_email || '' === $operator_email || strtolower( $owner_email ) === strtolower( $operator_email ) ) {
		$error = 'Setează două adrese de email diferite pentru contul de proprietar și cel de operator.';
	} elseif ( '' === $password_hash ) {
		$error = 'Setează parola conturilor demo înainte de prima resetare.';
	} elseif ( ! $plan ) {
		$error = 'Nu există un plan cu etichete QR pe angajat (Pro).';
	}

	if ( '' !== $error ) {
		return [
			'success' => false,
			'error'   => $error,
			'summary' => '',
		];
	}

	// Data changes every day, but stays stable within the same day.
	mt_srand( crc32( 'showcase-' . date( 'Y-m-d' ) ) );

	$story        = get_showcase_story();
	$company_name = sanitize_text( get_showcase_setting( 'showcase_company_name' ), 150 );
	$company_name = '' !== $company_name ? $company_name : get_showcase_defaults()['showcase_company_name'];
	$google       = parse_google_review_input( get_showcase_setting( 'showcase_google_url' ) );
	$cycle_start  = now_db( '-12 days' );
	$admin_id     = (int) db_value( "SELECT id FROM users WHERE role = 'super_admin' ORDER BY id ASC LIMIT 1" );
	$names        = [ 'Andrei Pavel', 'Mara Ionescu', 'Cătălin Dobre', 'Irina Stoica', 'Victor Nistor', 'Larisa Grigore', '', '' ];
	$notes        = [
		'Am sunat clientul și ne-am cerut scuze. I-am oferit un desert din partea casei la următoarea vizită.',
		'Discutat cu echipa de tură. Am stabilit să verificăm mesele de pe terasă la fiecare 10 minute.',
		'Clientul a revenit și a fost mulțumit. Caz închis.',
		'Am trimis un email cu explicații și o invitație pentru două persoane.',
	];
	$uploads      = [];
	$summary      = [];

	try {
		db()->beginTransaction();

		// Uploads and data from the previous day (logo / cover uploaded during a presentation, extra operators).
		$owner_row = db_row( 'SELECT id FROM users WHERE email = ? AND is_showcase = 1', [ $owner_email ] );

		foreach ( db_all( 'SELECT u.id FROM users u WHERE u.is_showcase = 1 AND u.email NOT IN (?, ?)', [ $owner_email, $operator_email ] ) as $stale ) {
			foreach ( db_all( 'SELECT logo_path, cover_path FROM companies WHERE user_id = ?', [ (int) $stale['id'] ] ) as $files ) {
				$uploads[] = $files['logo_path'];
				$uploads[] = $files['cover_path'];
			}
			db_query( 'DELETE FROM users WHERE id = ?', [ (int) $stale['id'] ] );
		}

		if ( $owner_row ) {
			$owner_id = (int) $owner_row['id'];

			foreach ( db_all( 'SELECT logo_path, cover_path FROM companies WHERE user_id = ?', [ $owner_id ] ) as $files ) {
				$uploads[] = $files['logo_path'];
				$uploads[] = $files['cover_path'];
			}

			db_query( 'DELETE FROM users WHERE parent_id = ? AND email <> ?', [ $owner_id, $operator_email ] );
			db_query( 'DELETE FROM companies WHERE user_id = ?', [ $owner_id ] );
			db_query( 'DELETE FROM payments WHERE user_id = ?', [ $owner_id ] );
			db_query( 'DELETE FROM subscription_requests WHERE user_id = ?', [ $owner_id ] );
			db_query( 'DELETE FROM print_orders WHERE user_id = ?', [ $owner_id ] );
			db_query( 'DELETE FROM password_resets WHERE user_id = ?', [ $owner_id ] );
		}

		$owner_id = showcase_upsert_user(
			$owner_email,
			[
				'role'                    => 'user',
				'parent_id'               => null,
				'name'                    => sanitize_text( get_showcase_setting( 'showcase_owner_name' ), 150 ),
				'password_hash'           => $password_hash,
				'status'                  => 'active',
				'must_change_password'    => 0,
				'plan_id'                 => (int) $plan['id'],
				'billing_cycle'           => 'monthly',
				'custom_max_reviews'      => null,
				'custom_max_companies'    => null,
				'subscription_status'     => 'active',
				'trial_ends_at'           => now_db( '-60 days' ),
				'subscription_ends_at'    => now_db( '+18 days' ),
				'quota_cycle_start'       => $cycle_start,
				'reviews_used_this_cycle' => 0,
				'quota_alert_80_sent'     => 0,
				'quota_alert_100_sent'    => 0,
				'trial_reminder_sent'     => 1,
				'roi_report_enabled'      => 1,
				'last_roi_report_period'  => date( 'Y-m', strtotime( 'first day of last month' ) ),
				'onboarding_dismissed'    => 0,
				'onboarding_materials_at' => null,
				'terms_accepted_at'       => now_db( '-95 days' ),
				'created_at'              => now_db( '-95 days' ),
			]
		);

		$operator_id = showcase_upsert_user(
			$operator_email,
			[
				'role'                 => 'operator',
				'parent_id'            => $owner_id,
				'name'                 => sanitize_text( get_showcase_setting( 'showcase_operator_name' ), 150 ),
				'password_hash'        => $password_hash,
				'status'               => 'active',
				'must_change_password' => 0,
				'plan_id'              => null,
				'subscription_status'  => 'active',
				'quota_cycle_start'    => $cycle_start,
				'terms_accepted_at'    => now_db( '-80 days' ),
				'created_at'           => now_db( '-80 days' ),
			]
		);

		db_query( 'DELETE FROM password_resets WHERE user_id = ?', [ $operator_id ] );

		db_query(
			'INSERT INTO companies (user_id, name, tax_id, slug, description, logo_path, cover_path, google_place_id, google_review_url, notification_emails, min_star_threshold, redirect_mode, cta_text, white_label_enabled, created_at)
			VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, 4, ?, ?, 0, ?)',
			[
				$owner_id,
				$company_name,
				showcase_cui( $company_name ),
				generate_unique_slug( $company_name ),
				'Mulțumim că ai trecut pe la noi! Cum ți s-a părut vizita de azi?',
				'' !== $google['place_id'] ? $google['place_id'] : ( '' === $google['url'] ? 'ChIJ' . substr( hash( 'sha256', 'showcase-' . $company_name ), 0, 23 ) : '' ),
				$google['url'],
				$owner_email,
				'helper',
				'Scanează codul și spune-ne cum a fost!',
				now_db( '-95 days' ),
			]
		);

		$company_id = db_insert_id();

		db_query( 'INSERT INTO operator_companies (operator_id, company_id) VALUES (?, ?)', [ $operator_id, $company_id ] );

		$counted = 0;
		$totals  = [];

		foreach ( $story as $person ) {
			$slug = '';

			if ( '' !== $person['label'] ) {
				$tag  = create_ref_tag( $company_id, $person['label'] );
				$slug = $tag['slug'];
				db_query( 'UPDATE ref_tags SET created_at = ? WHERE company_id = ? AND slug = ?', [ now_db( '-92 days' ), $company_id, $slug ] );
			}

			$key            = '' === $person['label'] ? 'Coduri generale' : $person['label'];
			$totals[ $key ] = 0;

			for ( $day = 90; 0 <= $day; $day-- ) {
				$weekday = (int) date( 'N', strtotime( '-' . $day . ' days' ) );
				$expect  = $person['views'] * ( 5 <= $weekday ? 1.5 : 0.85 );
				$views   = (int) floor( $expect ) + ( mt_rand( 1, 100 ) <= ( $expect - floor( $expect ) ) * 100 ? 1 : 0 );

				for ( $v = 0; $v < $views; $v++ ) {
					$when        = showcase_datetime( $day );
					$source      = '' === $slug ? ( 0 === mt_rand( 0, 2 ) ? 'qr_a4' : 'qr_sticker' ) : 'qr_card';
					$ip_hash     = hash_hmac( 'sha256', 'showcase-' . mt_rand(), APP_KEY );
					$device_hash = hash_hmac( 'sha256', 'showcase-device-' . mt_rand(), APP_KEY );

					db_query( 'INSERT INTO funnel_views (company_id, source, ref_tag, ip_hash, created_at) VALUES (?, ?, ?, ?, ?)', [ $company_id, $source, $slug, $ip_hash, $when ] );

					if ( mt_rand( 1, 100 ) > $person['rate'] ) {
						continue;
					}

					$rating   = showcase_rating( $person['weights'] );
					$in_cycle = strtotime( $when ) >= strtotime( $cycle_start );
					$counted += $in_cycle ? 1 : 0;
					++$totals[ $key ];

					if ( 4 <= $rating ) {
						db_query(
							"INSERT INTO reviews (company_id, rating, action_taken, source, ref_tag, ip_hash, device_hash, privacy_consent, counted_in_quota, created_at) VALUES (?, ?, 'redirected_google', ?, ?, ?, ?, 0, 1, ?)",
							[ $company_id, $rating, $source, $slug, $ip_hash, $device_hash, $when ]
						);
						continue;
					}

					// Private feedback: recent tickets are waiting for the operator, older ones were handled.
					$customer = $names[ array_rand( $names ) ];
					$contact  = '' === $customer ? '' : ( 0 === mt_rand( 0, 1 )
						? '07' . mt_rand( 20, 99 ) . ' ' . mt_rand( 100, 999 ) . ' ' . mt_rand( 100, 999 )
						: strtolower( str_replace( [ ' ', 'ă', 'ț', 'ș', 'î', 'â' ], [ '.', 'a', 't', 's', 'i', 'a' ], $customer ) ) . '@example.com' );

					if ( 8 > $day ) {
						$status = 'new';
					} elseif ( 21 > $day ) {
						$status = 0 === mt_rand( 0, 1 ) ? 'in_progress' : 'new';
					} else {
						$status = 'resolved';
					}

					db_query(
						"INSERT INTO reviews (company_id, rating, action_taken, customer_name, customer_contact, message, source, ref_tag, ip_hash, device_hash, privacy_consent, counted_in_quota, ticket_status, created_at)
						VALUES (?, ?, 'private_feedback', ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, ?)",
						[ $company_id, $rating, $customer, $contact, $person['messages'][ array_rand( $person['messages'] ) ], $source, $slug, $ip_hash, $device_hash, $status, $when ]
					);

					$review_id = db_insert_id();

					if ( 'new' !== $status ) {
						db_query(
							'INSERT INTO review_notes (review_id, user_id, note, created_at) VALUES (?, ?, ?, ?)',
							[ $review_id, 0 === mt_rand( 0, 2 ) ? $owner_id : $operator_id, $notes[ array_rand( $notes ) ], date( 'Y-m-d H:i:s', min( time() - 60, strtotime( $when ) + mt_rand( 1800, 30000 ) ) ) ]
						);
					}
				}
			}
		}

		db_query( 'UPDATE users SET reviews_used_this_cycle = ? WHERE id = ?', [ $counted, $owner_id ] );

		// Two paid months, so Abonament shows a real billing history.
		for ( $p = 2; 1 <= $p; $p-- ) {
			$start = date( 'Y-m-d', strtotime( '+18 days -' . $p . ' months' ) );
			$end   = date( 'Y-m-d', strtotime( '+18 days -' . ( $p - 1 ) . ' months' ) );

			db_query(
				"INSERT INTO payments (user_id, plan_id, amount_ron, period_start, period_end, method, reference, notes, recorded_by, created_at) VALUES (?, ?, ?, ?, ?, 'bank_transfer', ?, 'Cont demo', ?, ?)",
				[ $owner_id, (int) $plan['id'], number_format( get_plan_price( $plan, 'monthly' ), 2, '.', '' ), $start, $end, 'DEMO-' . $owner_id . '-' . $p, $admin_id ?: null, $start . ' 10:00:00' ]
			);
		}

		db()->commit();
	} catch ( Throwable $e ) {
		if ( db()->inTransaction() ) {
			db()->rollBack();
		}

		mt_srand();

		return [
			'success' => false,
			'error'   => $e->getMessage(),
			'summary' => '',
		];
	}

	mt_srand();

	foreach ( array_filter( $uploads ) as $path ) {
		delete_upload( $path );
	}

	foreach ( $totals as $label => $count ) {
		$summary[] = $label . ': ' . $count;
	}

	update_setting( 'showcase_last_reset', now_db() );
	get_showcase_emails( true );
	audit_log( 'showcase_reset', implode( ', ', $summary ) );

	return [
		'success' => true,
		'error'   => '',
		'summary' => implode( ', ', $summary ),
	];
}
