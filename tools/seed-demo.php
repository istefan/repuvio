<?php
/**
 * Demo data seeder (CLI only). Creates demo business owners on every plan (Starter, Pro,
 * Enterprise, trial, expired) with companies, branding, employee / department QR tags,
 * an operator account, 60 days of funnel views, reviews, private feedback tickets, notes, payments and
 * plan requests (pending with a failed email, invoiced, activated).
 *
 * Usage: php tools/seed-demo.php          (re-runnable: previous demo data is removed first)
 *        php tools/seed-demo.php --remove (only removes demo data)
 *
 * All demo accounts use the @repuvio-demo.test domain and share the password DEMO_PASSWORD.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 404 );
	exit;
}

define( 'REPUVIO_NO_SESSION', true );

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

const DEMO_DOMAIN   = 'repuvio-demo.test';
const DEMO_PASSWORD = 'Demo-2026!';

mt_srand( 2026 );

/**
 * Removes every demo user (companies, reviews, notes, payments cascade) and their uploads.
 *
 * @return int Removed users.
 */
function demo_remove() {
	$like  = '%@' . DEMO_DOMAIN;
	$files = db_all( 'SELECT c.logo_path, c.cover_path FROM companies c INNER JOIN users u ON u.id = c.user_id WHERE u.email LIKE ?', [ $like ] );

	foreach ( $files as $file ) {
		delete_upload( $file['logo_path'] );
		delete_upload( $file['cover_path'] );
	}

	return db_query( 'DELETE FROM users WHERE email LIKE ?', [ $like ] )->rowCount();
}

/**
 * Finds a TTF font for generated logos (optional).
 *
 * @return string Empty when none is available.
 */
function demo_font() {
	foreach ( [ 'C:/Windows/Fonts/segoeuib.ttf', 'C:/Windows/Fonts/arialbd.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf' ] as $font ) {
		if ( is_file( $font ) ) {
			return $font;
		}
	}

	return '';
}

/**
 * Hex colour to GD colour.
 *
 * @param GdImage $image Image.
 * @param string  $hex   #RRGGBB.
 * @return int
 */
function demo_color( $image, $hex ) {
	return imagecolorallocate( $image, hexdec( substr( $hex, 1, 2 ) ), hexdec( substr( $hex, 3, 2 ) ), hexdec( substr( $hex, 5, 2 ) ) );
}

/**
 * Saves a GD image as WebP in uploads/companies with a random name.
 *
 * @param GdImage $image Image.
 * @return string Relative path.
 */
function demo_save( $image ) {
	$path = UPLOAD_DIR . '/' . bin2hex( random_bytes( 16 ) ) . '.webp';
	imagewebp( $image, REPUVIO_ROOT . '/' . $path, 85 );

	return $path;
}

/**
 * Generates a round logo with initials.
 *
 * @param string $initials Initials.
 * @param string $color    Background colour.
 * @return string Relative path.
 */
function demo_logo( $initials, $color ) {
	$size  = 400;
	$image = imagecreatetruecolor( $size, $size );

	imagealphablending( $image, false );
	imagesavealpha( $image, true );
	imagefill( $image, 0, 0, imagecolorallocatealpha( $image, 0, 0, 0, 127 ) );
	imagealphablending( $image, true );
	imagefilledellipse( $image, 200, 200, 396, 396, demo_color( $image, $color ) );

	$white = demo_color( $image, '#FFFFFF' );
	$font  = demo_font();

	if ( '' !== $font ) {
		$box = imagettfbbox( 130, 0, $font, $initials );
		$x   = (int) ( ( $size - ( $box[2] - $box[0] ) ) / 2 - $box[0] );
		$y   = (int) ( ( $size - ( $box[1] - $box[7] ) ) / 2 - $box[7] );
		imagettftext( $image, 130, 0, $x, $y, $white, $font, $initials );
	} else {
		imagestring( $image, 5, 180, 190, $initials, $white );
	}

	return demo_save( $image );
}

/**
 * Generates a gradient cover picture.
 *
 * @param string $from Start colour.
 * @param string $to   End colour.
 * @return string Relative path.
 */
function demo_cover( $from, $to ) {
	$width  = 1600;
	$height = 600;
	$image  = imagecreatetruecolor( $width, $height );

	$r1 = hexdec( substr( $from, 1, 2 ) );
	$g1 = hexdec( substr( $from, 3, 2 ) );
	$b1 = hexdec( substr( $from, 5, 2 ) );
	$r2 = hexdec( substr( $to, 1, 2 ) );
	$g2 = hexdec( substr( $to, 3, 2 ) );
	$b2 = hexdec( substr( $to, 5, 2 ) );

	for ( $x = 0; $x < $width; $x++ ) {
		$t = $x / ( $width - 1 );
		imageline( $image, $x, 0, $x, $height, imagecolorallocate( $image, (int) ( $r1 + ( $r2 - $r1 ) * $t ), (int) ( $g1 + ( $g2 - $g1 ) * $t ), (int) ( $b1 + ( $b2 - $b1 ) * $t ) ) );
	}

	imagealphablending( $image, true );

	for ( $i = 0; 14 > $i; $i++ ) {
		$diameter = mt_rand( 80, 420 );
		imagefilledellipse( $image, mt_rand( 0, $width ), mt_rand( 0, $height ), $diameter, $diameter, imagecolorallocatealpha( $image, 255, 255, 255, mt_rand( 105, 122 ) ) );
	}

	return demo_save( $image );
}

/**
 * Builds a fictitious but checksum-valid Romanian fiscal code (CUI) from a name.
 *
 * @param string $name Company name.
 * @return string
 */
function demo_cui( $name ) {
	$body = (string) ( 1000000 + hexdec( substr( hash( 'sha256', $name ), 0, 6 ) ) % 8999999 );

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
 * @param array $weights Five weights.
 * @return int
 */
function demo_rating( array $weights ) {
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
 * Random datetime on a day offset (0 = today, never in the future).
 *
 * @param int $days_ago Days ago.
 * @return string
 */
function demo_datetime( $days_ago ) {
	$timestamp = strtotime( 'today' ) - $days_ago * 86400 + mt_rand( 8 * 3600, 22 * 3600 );

	return date( 'Y-m-d H:i:s', min( $timestamp, time() - mt_rand( 60, 3600 ) ) );
}

$demo_names    = [ 'Ana Marin', 'Radu Stan', 'Cristina Pop', 'Vlad Neagu', 'Diana Lazăr', 'Bogdan Tudor', 'Alina Matei', 'George Enache', 'Simona Rusu', 'Paul Dobre', '', '' ];
$demo_messages = [
	'Am așteptat aproape 30 de minute și nimeni nu ne-a băgat în seamă.',
	'Mâncarea a venit rece, iar ospătarul a fost nepoliticos când i-am spus.',
	'Prețurile de pe meniu nu corespundeau cu cele de pe bon.',
	'Curățenia lăsa de dorit, toaleta era murdară.',
	'Programarea mea a fost mutată fără să fiu anunțat.',
	'Personalul a fost grăbit și nu a răspuns la întrebări.',
	'Am primit altceva decât am comandat și a durat mult până s-a rezolvat.',
	'Muzica era prea tare, nu ne puteam auzi.',
	'Parcarea a fost o problemă, nu există indicații clare.',
	'Rezultatul nu a fost cel promis, sunt dezamăgit.',
];
$demo_notes    = [
	'Am sunat clientul și ne-am cerut scuze. I-am oferit un voucher de 20%.',
	'Discutat cu echipa de tură, am refăcut procedura de servire.',
	'Clientul a revenit și a fost mulțumit. Caz închis.',
	'Am trimis email cu explicații și o invitație din partea casei.',
];

// Company spec: name, initials, logo colour, cover from, cover to, threshold, redirect mode, rating weights,
// daily views, welcome text, ref tags (employee / department QR codes), white-label, cover (false = none).
$demo_users = [
	[
		'name'      => 'Andrei Ionescu',
		'email'     => 'andrei@' . DEMO_DOMAIN,
		'plan'      => 'pro',
		'status'    => 'active',
		'created'   => 75,
		'ends_in'   => 18,
		'payments'  => 2,
		'operator'  => [ 'Laura Suport', 'operator@' . DEMO_DOMAIN, [ 0, 1 ] ],
		'companies' => [
			[ 'Bistro Teiul Verde', 'TV', '#B45309', '#7C2D12', '#F59E0B', 3, 'immediate', [ 2, 2, 4, 14, 30 ], 4, 'Bine ai venit la Teiul Verde! Ne bucurăm că ai fost oaspetele nostru.', [ 'Chelner Mihai', 'Chelner Ana', 'Terasa', 'Masa 4' ], 0, true ],
			[ 'Cafeneaua Aroma', 'CA', '#78350F', '#451A03', '#D97706', 3, 'helper', [ 1, 2, 3, 12, 28 ], 3, 'Mulțumim pentru vizită! Cum ți s-a părut cafeaua de azi?', [ 'Barista Radu', 'Casa de marcat' ], 0, true ],
			[ 'Pizzeria Bella Napoli', 'BN', '#B91C1C', '#7F1D1D', '#F87171', 4, 'helper', [ 3, 3, 5, 12, 22 ], 3, 'Grazie! Spune-ne cum a fost pizza ta.', [], 0, false ],
		],
	],
	[
		'name'      => 'Ioana Dumitrescu',
		'email'     => 'ioana@' . DEMO_DOMAIN,
		'plan'      => 'enterprise',
		'status'    => 'active',
		'created'   => 120,
		'ends_in'   => 240,
		'payments'  => 1,
		'yearly'    => true,
		'companies' => [
			[ 'Clinica Dentară Zâmbet', 'ZD', '#0E7490', '#164E63', '#22D3EE', 4, 'helper', [ 1, 1, 2, 8, 35 ], 9, 'Îți mulțumim că ai ales Clinica Zâmbet. Cum a fost vizita ta?', [ 'Recepție', 'Dr. Popescu', 'Dr. Marinescu' ], 1, true ],
			[ 'Hotel Panorama Brașov', 'HP', '#1D4ED8', '#1E3A8A', '#60A5FA', 4, 'helper', [ 2, 2, 4, 15, 25 ], 12, 'Sperăm că sejurul tău la Panorama a fost minunat!', [ 'Recepție', 'Restaurant', 'Housekeeping' ], 1, true ],
		],
	],
	[
		'name'      => 'Sorin Vasile',
		'email'     => 'sorin@' . DEMO_DOMAIN,
		'plan'      => 'starter',
		'status'    => 'active',
		'created'   => 40,
		'ends_in'   => 22,
		'payments'  => 1,
		'companies' => [
			[ 'Atelier Croitorie Sorin', 'AS', '#4D7C0F', '#365314', '#A3E635', 3, 'helper', [ 1, 1, 2, 9, 25 ], 2, 'Mulțumim că ai ales atelierul nostru! Cum ți se potrivește haina?', [], 0, false ],
		],
	],
	[
		'name'      => 'Elena Popa',
		'email'     => 'elena@' . DEMO_DOMAIN,
		'plan'      => '',
		'status'    => 'trialing',
		'created'   => 18,
		'ends_in'   => 12,
		'payments'  => 0,
		'companies' => [
			[ 'Salon Elena Beauty', 'EB', '#BE185D', '#831843', '#F472B6', 3, 'helper', [ 1, 1, 3, 10, 30 ], 3, 'Mulțumim că ne-ai vizitat! Cum te simți după vizita la salon?', [ 'Stilist Maria' ], 0, true ],
		],
	],
	[
		'name'      => 'Mihai Georgescu',
		'email'     => 'mihai@' . DEMO_DOMAIN,
		'plan'      => '',
		'status'    => 'expired',
		'created'   => 45,
		'ends_in'   => -15,
		'payments'  => 0,
		'companies' => [
			[ 'Service Auto Turbo', 'AT', '#334155', '#0F172A', '#94A3B8', 3, 'immediate', [ 4, 4, 5, 10, 18 ], 4, 'Mulțumim că ți-ai adus mașina la Turbo! Cum a fost service-ul?', [], 0, true ],
		],
	],
];

$removed = demo_remove();

if ( in_array( '--remove', $argv, true ) ) {
	echo 'Removed ' . $removed . ' demo users.' . PHP_EOL;
	exit;
}

$plans       = array_column( get_plans(), null, 'slug' );
$admin_id    = (int) db_value( "SELECT id FROM users WHERE role = 'super_admin' ORDER BY id ASC LIMIT 1" );
$demo_hash   = hash_user_password( DEMO_PASSWORD );
$statuses    = [ 'new', 'new', 'in_progress', 'resolved', 'resolved' ];
$sources     = [ 'qr_a4', 'qr_a4', 'qr_a5', 'qr_card', 'qr_sticker', 'qr_sticker', 'direct' ];
$cycle_start = now_db( '-20 days' );
$summary     = [];

db()->beginTransaction();

foreach ( $demo_users as $demo ) {
	$plan       = '' !== $demo['plan'] ? $plans[ $demo['plan'] ] : null;
	$created_at = now_db( '-' . $demo['created'] . ' days' );
	$ends_at    = now_db( ( 0 <= $demo['ends_in'] ? '+' : '' ) . $demo['ends_in'] . ' days' );
	$is_trial   = in_array( $demo['status'], [ 'trialing', 'expired' ], true );

	db_query(
		"INSERT INTO users (role, name, email, password_hash, status, plan_id, billing_cycle, subscription_status, trial_ends_at, subscription_ends_at, quota_cycle_start, last_login_at, created_at)
		VALUES ('user', ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, ?)",
		[
			$demo['name'],
			$demo['email'],
			$demo_hash,
			$plan ? (int) $plan['id'] : null,
			! empty( $demo['yearly'] ) ? 'yearly' : 'monthly',
			$demo['status'],
			$is_trial ? $ends_at : now_db( '-' . ( $demo['created'] - 30 ) . ' days' ),
			$is_trial ? null : $ends_at,
			$cycle_start,
			now_db( '-' . mt_rand( 1, 30 ) . ' hours' ),
			$created_at,
		]
	);

	$user_id     = db_insert_id();
	$counted     = 0;
	$days_back   = min( 60, $demo['created'] );
	$limit       = $plan ? (int) $plan['max_reviews_per_month'] : get_setting_int( 'trial_max_reviews', 50 );
	$locked      = 0;
	$company_ids = [];
	$tag_count   = 0;

	foreach ( $demo['companies'] as $company_spec ) {
		list( $name, $initials, $logo_color, $cover_from, $cover_to, $threshold, $mode, $weights, $daily_views, $welcome, $tag_labels, $white_label, $with_cover ) = $company_spec;

		db_query(
			'INSERT INTO companies (user_id, name, tax_id, slug, description, logo_path, cover_path, google_place_id, google_review_url, notification_emails, min_star_threshold, redirect_mode, cta_text, white_label_enabled, created_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[
				$user_id,
				$name,
				( $white_label ? 'RO' : '' ) . demo_cui( $name ),
				generate_unique_slug( $name ),
				$welcome,
				demo_logo( $initials, $logo_color ),
				$with_cover ? demo_cover( $cover_from, $cover_to ) : null,
				'ChIJ' . substr( hash( 'sha256', $name ), 0, 23 ),
				'',
				$demo['email'],
				$threshold,
				$mode,
				'Scanează codul și spune-ne cum a fost!',
				$white_label,
				$created_at,
			]
		);

		$company_id    = db_insert_id();
		$company_ids[] = $company_id;
		$tag_slugs     = [];

		foreach ( $tag_labels as $tag_label ) {
			$tag         = create_ref_tag( $company_id, $tag_label );
			$tag_slugs[] = $tag['slug'];
			++$tag_count;
		}

		for ( $day = $days_back; 0 <= $day; $day-- ) {
			// Slight growth over time + weekend bump.
			$weekday = (int) date( 'N', strtotime( '-' . $day . ' days' ) );
			$views   = max( 0, (int) round( $daily_views * ( 0.6 + 0.6 * ( 1 - $day / 60 ) ) * ( 6 <= $weekday ? 1.4 : 1 ) ) + mt_rand( -1, 2 ) );

			for ( $v = 0; $v < $views; $v++ ) {
				$source      = $sources[ array_rand( $sources ) ];
				$when        = demo_datetime( $day );
				$ip_hash     = hash_hmac( 'sha256', 'demo-' . mt_rand(), APP_KEY );
				$device_hash = hash_hmac( 'sha256', 'demo-device-' . mt_rand(), APP_KEY );
				$ref_tag     = ! empty( $tag_slugs ) && 65 >= mt_rand( 1, 100 ) ? $tag_slugs[ array_rand( $tag_slugs ) ] : '';

				db_query( 'INSERT INTO funnel_views (company_id, source, ref_tag, ip_hash, created_at) VALUES (?, ?, ?, ?, ?)', [ $company_id, $source, $ref_tag, $ip_hash, $when ] );

				// About 55% of visitors leave a rating.
				if ( 55 < mt_rand( 1, 100 ) ) {
					continue;
				}

				$rating      = demo_rating( $weights );
				$in_cycle    = strtotime( $when ) >= strtotime( $cycle_start );
				$is_feedback = $rating < $threshold;

				if ( ! $is_feedback ) {
					db_query(
						"INSERT INTO reviews (company_id, rating, action_taken, source, ref_tag, ip_hash, device_hash, counted_in_quota, created_at) VALUES (?, ?, 'redirected_google', ?, ?, ?, ?, 1, ?)",
						[ $company_id, $rating, $source, $ref_tag, $ip_hash, $device_hash, $when ]
					);
					$counted += $in_cycle ? 1 : 0;
					continue;
				}

				// Expired account: feedback received in the current cycle stays locked.
				$is_locked = 'expired' === $demo['status'] && $in_cycle && strtotime( $when ) > strtotime( $ends_at );
				$customer  = $demo_names[ array_rand( $demo_names ) ];
				$contact   = '' === $customer ? '' : ( 0 === mt_rand( 0, 1 )
					? '07' . mt_rand( 20, 99 ) . ' ' . mt_rand( 100, 999 ) . ' ' . mt_rand( 100, 999 )
					: strtolower( str_replace( [ ' ', 'ă', 'ț', 'ș' ], [ '.', 'a', 't', 's' ], $customer ) ) . '@example.com' );
				$status    = $is_locked ? 'new' : ( 7 > $day ? 'new' : $statuses[ array_rand( $statuses ) ] );

				db_query(
					"INSERT INTO reviews (company_id, rating, action_taken, customer_name, customer_contact, message, source, ref_tag, ip_hash, device_hash, is_locked, counted_in_quota, ticket_status, created_at)
					VALUES (?, ?, 'private_feedback', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
					[ $company_id, $rating, $customer, $contact, $demo_messages[ array_rand( $demo_messages ) ], $source, $ref_tag, $ip_hash, $device_hash, $is_locked ? 1 : 0, $is_locked ? 0 : 1, $status, $when ]
				);

				$review_id = db_insert_id();

				if ( $is_locked ) {
					++$locked;
				} else {
					$counted += $in_cycle ? 1 : 0;
				}

				if ( 'new' !== $status ) {
					db_query(
						'INSERT INTO review_notes (review_id, user_id, note, created_at) VALUES (?, ?, ?, ?)',
						[ $review_id, $user_id, $demo_notes[ array_rand( $demo_notes ) ], date( 'Y-m-d H:i:s', min( time(), strtotime( $when ) + mt_rand( 3600, 86400 ) ) ) ]
					);
				}
			}
		}
	}

	// Quota counter for the current cycle (alerts already "sent" when thresholds were crossed).
	db_query(
		'UPDATE users SET reviews_used_this_cycle = ?, quota_alert_80_sent = ?, quota_alert_100_sent = ? WHERE id = ?',
		[ $counted, $counted >= $limit * 0.8 ? 1 : 0, $counted >= $limit ? 1 : 0, $user_id ]
	);

	// Operator (customer support) account assigned to some of the owner's companies.
	if ( ! empty( $demo['operator'] ) ) {
		list( $operator_name, $operator_email, $operator_companies ) = $demo['operator'];

		db_query(
			"INSERT INTO users (role, parent_id, name, email, password_hash, status, subscription_status, quota_cycle_start, created_at) VALUES ('operator', ?, ?, ?, ?, 'active', 'active', NOW(), ?)",
			[ $user_id, $operator_name, $operator_email, $demo_hash, now_db( '-30 days' ) ]
		);

		$operator_id = db_insert_id();

		foreach ( $operator_companies as $index ) {
			db_query( 'INSERT INTO operator_companies (operator_id, company_id) VALUES (?, ?)', [ $operator_id, $company_ids[ $index ] ] );
		}
	}

	for ( $p = $demo['payments']; 1 <= $p; $p-- ) {
		$months = ! empty( $demo['yearly'] ) ? 12 : 1;
		$start  = date( 'Y-m-d', strtotime( $ends_at . ' -' . ( $p * $months ) . ' months' ) );
		$end    = date( 'Y-m-d', strtotime( $ends_at . ' -' . ( ( $p - 1 ) * $months ) . ' months' ) );

		db_query(
			"INSERT INTO payments (user_id, plan_id, amount_ron, period_start, period_end, method, reference, recorded_by, created_at) VALUES (?, ?, ?, ?, ?, 'bank_transfer', ?, ?, ?)",
			[ $user_id, (int) $plan['id'], number_format( get_plan_price( $plan, ! empty( $demo['yearly'] ) ? 'yearly' : 'monthly' ), 2, '.', '' ), $start, $end, 'RM-DEMO-' . $user_id . '-' . $p, $admin_id ?: null, $start . ' 10:00:00' ]
		);
	}

	$summary[] = sprintf( '%-18s %-30s %-10s %-10s %d companies, %d QR tags, quota %d/%d, %d locked%s', $demo['name'], $demo['email'], $demo['status'], $plan ? $plan['name'] : 'Trial', count( $demo['companies'] ), $tag_count, $counted, $limit, $locked, empty( $demo['operator'] ) ? '' : ', operator ' . $demo['operator'][1] );
}

// Plan requests: one whose notification email failed (it still reaches the admin through the database).
foreach ( [
	[ 'elena', 'pro', 'monthly', 'pending', 0, 2, '' ],
	[ 'sorin', 'pro', 'yearly', 'invoiced', 1, 5, 'Proforma RM-DEMO-PF-102 trimisă' ],
	[ 'andrei', 'pro', 'monthly', 'completed', 1, 40, 'Plătit prin OP' ],
] as $demo_request ) {
	list( $local, $plan_slug, $cycle, $status, $email_sent, $days_ago, $note ) = $demo_request;

	$request_user = db_value( 'SELECT id FROM users WHERE email = ?', [ $local . '@' . DEMO_DOMAIN ] );
	$request_plan = $plans[ $plan_slug ];

	db_query(
		'INSERT INTO subscription_requests (user_id, plan_id, plan_name, billing_cycle, price_ron, status, email_sent, admin_note, processed_by, processed_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
		[
			(int) $request_user,
			(int) $request_plan['id'],
			$request_plan['name'],
			$cycle,
			number_format( get_plan_price( $request_plan, $cycle ), 2, '.', '' ),
			$status,
			$email_sent,
			$note,
			'pending' === $status ? null : ( $admin_id ?: null ),
			'pending' === $status ? null : now_db( '-' . max( 0, $days_ago - 1 ) . ' days' ),
			now_db( '-' . $days_ago . ' days' ),
		]
	);
}

db()->commit();

echo 'Demo data created (password for all demo accounts: see DEMO_PASSWORD in tools/seed-demo.php)' . PHP_EOL;
echo implode( PHP_EOL, $summary ) . PHP_EOL;
