<?php
/**
 * Public customer review funnel: /r/{slug} (or review.php?b={slug}).
 *
 * A page load counts only as a view ("vizualizare"). Every completed star interaction counts
 * as 1 review toward the owner's monthly quota:
 * Rating >= company threshold  -> logged as `redirected_google`, quota consumed, customer sent to Google
 *                                 (helper step, or "immediate": PRG back here with state `redirect_google`,
 *                                 then <meta refresh> + JS to Google — a direct POST→Google redirect is blocked by CSP form-action).
 * Rating <  company threshold  -> empathetic private feedback form (honeypot, CSRF, timing check),
 *                                 stored locked when the owner is over quota / inactive.
 * Owner emails go through notify_new_review(): instant or Daily Digest, per the delivery mode.
 * Optional ?src= (scan source) and ?ref= (employee / department tag, Pro / Enterprise / trial).
 * PROD mode enforces a per-company cooldown per device or IP + browser (see includes/antiabuse.php);
 * DEV mode disables rate limits and duplicate blocking for testing.
 * The end customer never sees an error caused by the owner's subscription state.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once __DIR__ . '/includes/bootstrap.php';

header( 'X-Robots-Tag: noindex, nofollow' );
send_no_cache_headers();

$company = get_public_company_by_slug( input_get( 'b' ) );

if ( ! $company ) {
	http_response_code( 404 );
}

$owner = $company ? get_user_by_id( (int) $company['user_id'] ) : null;

if ( $company && $owner ) {
	refresh_subscription_status( $owner );
	roll_quota_cycle( $owner );
	funnel_device_id();
}

$company_key = $company ? 'c' . (int) $company['id'] : '';
$threshold   = $company ? max( 1, min( 5, (int) $company['min_star_threshold'] ) ) : 3;
$google_url  = $company ? get_company_google_url( $company ) : '';
$state       = 'rate';
$rating      = 0;
$form_error  = '';
$form        = [
	'name'    => '',
	'contact' => '',
	'message' => '',
	'consent' => false,
];

/**
 * Signed form-render timestamp (bots that submit instantly are ignored).
 *
 * @return string
 */
function funnel_form_timer() {
	$time = (string) time();

	return $time . '.' . hash_hmac( 'sha256', 'funnel|' . $time, APP_KEY );
}

/**
 * Validates the signed timestamp: at least 2 seconds and at most 2 hours old.
 *
 * @param string $value Submitted value.
 * @return bool
 */
function funnel_form_timer_ok( $value ) {
	$parts = explode( '.', (string) $value );

	if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
		return false;
	}

	if ( ! hash_equals( hash_hmac( 'sha256', 'funnel|' . $parts[0], APP_KEY ), $parts[1] ) ) {
		return false;
	}

	$age = time() - (int) $parts[0];

	return 2 <= $age && 7200 >= $age;
}

/**
 * Inserts a review row (source and ref tag come from the session captured on page load).
 *
 * @param array  $company Company.
 * @param int    $rating  Rating.
 * @param string $action  Action taken.
 * @param array  $extra   customer_name, customer_contact, message, is_locked, counted_in_quota.
 * @return int Review ID.
 */
function funnel_insert_review( array $company, $rating, $action, array $extra = [] ) {
	$key = 'c' . (int) $company['id'];

	db_query(
		'INSERT INTO reviews (company_id, rating, action_taken, customer_name, customer_contact, message, source, ref_tag, ip_hash, device_hash, user_agent, is_locked, counted_in_quota, privacy_consent)
		VALUES (:company_id, :rating, :action_taken, :customer_name, :customer_contact, :message, :source, :ref_tag, :ip_hash, :device_hash, :user_agent, :is_locked, :counted_in_quota, :privacy_consent)',
		[
			'company_id'       => (int) $company['id'],
			'rating'           => (int) $rating,
			'action_taken'     => $action,
			'customer_name'    => $extra['customer_name'] ?? '',
			'customer_contact' => $extra['customer_contact'] ?? '',
			'message'          => $extra['message'] ?? null,
			'source'           => sanitize_source( $_SESSION['funnel_source'][ $key ] ?? 'direct' ),
			'ref_tag'          => sanitize_ref_tag( $_SESSION['funnel_ref'][ $key ] ?? '' ),
			'ip_hash'          => client_ip_hash(),
			'device_hash'      => funnel_device_hash(),
			'user_agent'       => client_user_agent(),
			'is_locked'        => (int) ( $extra['is_locked'] ?? 0 ),
			'counted_in_quota' => (int) ( $extra['counted_in_quota'] ?? 0 ),
			'privacy_consent'  => (int) ( $extra['privacy_consent'] ?? 0 ),
		]
	);

	return db_insert_id();
}

/**
 * Stores the result state and redirects (PRG) to the thank-you screen.
 *
 * @param array  $company Company.
 * @param string $state   State key.
 * @param int    $rating  Rating.
 * @return never
 */
function funnel_finish( array $company, $state, $rating ) {
	$_SESSION['funnel_state'][ 'c' . (int) $company['id'] ] = [
		'state'  => $state,
		'rating' => (int) $rating,
	];

	redirect( get_company_public_url( $company ) . ( PRETTY_URLS ? '?' : '&' ) . 'done=1', 303 );
}

if ( $company && $owner ) {
	if ( is_post() ) {
		$action = input_post( 'action' );
		$rating = sanitize_int_range( input_post( 'rating', '0' ), 0, 5, 0 );

		if ( 'rate' === $action && 1 <= $rating ) {
			if ( $rating >= $threshold ) {
				$recent_key = 'funnel_google_' . $company_key;
				$window     = funnel_dedupe_window( 600 );
				$is_repeat  = 0 < $window && isset( $_SESSION[ $recent_key ] ) && $window > ( time() - (int) $_SESSION[ $recent_key ] );

				// Log + count once; duplicates and rate-limited IPs are still sent to Google, just not counted.
				if ( ! $is_repeat && ! is_duplicate_review( (int) $company['id'] ) && ! funnel_rate_limit_is_locked( 'review_rate' ) ) {
					funnel_rate_limit_hit( 'review_rate', 30, 600, 600 );
					$review_id = funnel_insert_review( $company, $rating, 'redirected_google', [ 'counted_in_quota' => 1 ] );
					consume_quota( (int) $owner['id'] );
					notify_new_review( $company, db_row( 'SELECT * FROM reviews WHERE id = ?', [ $review_id ] ) );
					$_SESSION[ $recent_key ] = time();
				}

				// "Immediate" mode: never redirect a form POST straight to Google — the CSP `form-action`
				// directive would block the cross-origin redirect and the visitor would stay stuck.
				// Instead go back to this page (same origin), which then navigates to Google at once.
				if ( '' !== $google_url && 'immediate' === $company['redirect_mode'] ) {
					funnel_finish( $company, 'redirect_google', $rating );
				}

				funnel_finish( $company, '' !== $google_url ? 'thanks_google' : 'thanks', $rating );
			}

			// Low rating without JavaScript: show the feedback form inline.
			$state = 'feedback';
		} elseif ( 'feedback' === $action ) {
			$form['name']    = sanitize_text( input_post( 'customer_name' ), 150 );
			$form['contact'] = sanitize_text( input_post( 'customer_contact' ), 190 );
			$form['message'] = sanitize_textarea( input_post( 'message' ), 3000 );
			$form['consent'] = '1' === input_post( 'privacy_consent' );
			$state           = 'feedback';

			$is_spam = '' !== input_post( 'website' ) || ! funnel_form_timer_ok( input_post( 'ft' ) );

			if ( 1 > $rating || $rating >= $threshold ) {
				$state = 'rate';
			} elseif ( $is_spam ) {
				// Silently accept spam without storing it.
				funnel_finish( $company, 'thanks_feedback', $rating );
			} elseif ( is_duplicate_review( (int) $company['id'] ) ) {
				funnel_finish( $company, 'already', $rating );
			} elseif ( funnel_rate_limit_is_locked( 'review_submit' ) ) {
				$form_error = 'Ai trimis deja mai multe mesaje. Te rugăm să încerci din nou peste câteva minute.';
			} elseif ( 3 > mb_strlen( $form['message'] ) ) {
				$form_error = 'Te rugăm să ne spui pe scurt ce s-a întâmplat.';
			} elseif ( ! $form['consent'] ) {
				$form_error = 'Te rugăm să bifezi acordul privind transmiterea datelor către afacere.';
			} else {
				funnel_rate_limit_hit( 'review_submit', 10, 600, 600 );

				$unlocked = can_receive_unlocked_feedback( $owner );

				$review_id = funnel_insert_review(
					$company,
					$rating,
					'private_feedback',
					[
						'customer_name'    => $form['name'],
						'customer_contact' => $form['contact'],
						'message'          => $form['message'],
						'is_locked'        => $unlocked ? 0 : 1,
						'counted_in_quota' => $unlocked ? 1 : 0,
						'privacy_consent'  => 1,
					]
				);

				$review = db_row( 'SELECT * FROM reviews WHERE id = ?', [ $review_id ] );

				if ( $unlocked ) {
					consume_quota( (int) $owner['id'] );
				}

				// Instant email or Daily Digest, following the company delivery mode (Email & Șabloane).
				notify_new_review( $company, $review );

				funnel_finish( $company, 'thanks_feedback', $rating );
			}
		}
	} elseif ( '1' === input_get( 'done' ) && isset( $_SESSION['funnel_state'][ $company_key ] ) ) {
		$state  = $_SESSION['funnel_state'][ $company_key ]['state'];
		$rating = (int) $_SESSION['funnel_state'][ $company_key ]['rating'];
		unset( $_SESSION['funnel_state'][ $company_key ] );
	} else {
		$source = sanitize_source( input_get( 'src', 'direct' ) );
		$ref    = sanitize_ref_tag( input_get( 'ref' ) );

		// Only known, active tags of plans with employee QR codes are tracked.
		if ( '' !== $ref && ( ! user_can_use_ref_tags( $owner ) || ! get_company_ref_tag( (int) $company['id'], $ref ) ) ) {
			$ref = '';
		}

		$_SESSION['funnel_source'][ $company_key ] = $source;
		$_SESSION['funnel_ref'][ $company_key ]    = $ref;

		// Count a view once per session per 30 minutes (every load in DEV), with an IP flood guard.
		// The owner's own preview (?preview=1, e.g. the onboarding phone mock-up) is never counted.
		$view_key   = 'funnel_view_' . $company_key;
		$window     = funnel_dedupe_window( 1800 );
		$is_preview = '1' === input_get( 'preview' ) && is_logged_in() && null !== get_owned_company( (int) $company['id'] );

		if ( ! $is_preview && ( 0 === $window || $window < ( time() - (int) ( $_SESSION[ $view_key ] ?? 0 ) ) ) && ! funnel_rate_limit_is_locked( 'funnel_view' ) ) {
			funnel_rate_limit_hit( 'funnel_view', 120, 600, 600 );
			db_query( 'INSERT INTO funnel_views (company_id, source, ref_tag, ip_hash) VALUES (?, ?, ?, ?)', [ (int) $company['id'], $source, $ref, client_ip_hash() ] );
			$_SESSION[ $view_key ] = time();
		}
	}
}

$labels      = [ 1 => 'Foarte slab', 2 => 'Slab', 3 => 'Acceptabil', 4 => 'Foarte bine', 5 => 'Excelent' ];
$cover_url   = $company ? upload_url( $company['cover_path'] ) : '';
$logo_url    = $company ? upload_url( $company['logo_path'] ) : '';
$action      = $company ? get_company_public_url( $company ) : app_url();
$timer       = funnel_form_timer();
$white_label = $company && 1 === (int) $company['white_label_enabled'];
?>
<!doctype html>
<html lang="ro">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="robots" content="noindex, nofollow">
	<meta name="theme-color" content="#0F172A">
	<?php if ( 'redirect_google' === $state && '' !== $google_url ) : ?>
		<meta http-equiv="refresh" content="<?php echo esc_attr( '0;url=' . $google_url ); ?>">
	<?php endif; ?>
	<title><?php echo esc_html( $company ? 'Evaluează ' . $company['name'] : 'Pagină inexistentă' ); ?></title>
	<link rel="icon" href="<?php echo esc_url( asset_url( 'img/favicon.svg' ) ); ?>" type="image/svg+xml">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
	<link href="<?php echo esc_url( asset_url( 'css/public.css' ) ); ?>" rel="stylesheet">
</head>
<body>
<?php if ( is_dev_environment() ) : ?>
	<div class="rf-dev-flag" role="status">Mod DEV · anti-abuz dezactivat</div>
<?php endif; ?>
<main class="rf-wrap">
	<?php if ( ! $company ) : ?>
		<section class="rf-card rf-center">
			<div class="rf-emoji" aria-hidden="true">🔍</div>
			<h1 class="rf-title">Pagina nu a fost găsită</h1>
			<p class="rf-text">Linkul pe care l-ai accesat nu mai este activ. Îți mulțumim pentru intenția de a ne lăsa o părere!</p>
		</section>
	<?php else : ?>
		<section class="rf-card">
			<div class="rf-cover<?php echo '' === $cover_url ? ' is-empty' : ''; ?>">
				<?php if ( '' !== $cover_url ) : ?>
					<img src="<?php echo esc_url( $cover_url ); ?>" alt="" fetchpriority="high">
				<?php endif; ?>
			</div>
			<div class="rf-body">
				<div class="rf-logo">
					<?php if ( '' !== $logo_url ) : ?>
						<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $company['name'] ); ?>">
					<?php else : ?>
						<span><?php echo esc_html( mb_strtoupper( mb_substr( $company['name'], 0, 1 ) ) ); ?></span>
					<?php endif; ?>
				</div>
				<h1 class="rf-title"><?php echo esc_html( $company['name'] ); ?></h1>

				<?php if ( 'rate' === $state ) : ?>
					<?php if ( '' !== trim( (string) $company['description'] ) ) : ?>
						<p class="rf-text"><?php echo nl2br( esc_html( $company['description'] ) ); ?></p>
					<?php endif; ?>
					<h2 class="rf-question">Cum a fost experiența ta?</h2>
					<form method="post" action="<?php echo esc_url( $action ); ?>" id="rfRateForm" data-threshold="<?php echo esc_attr( $threshold ); ?>">
						<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<input type="hidden" name="action" value="rate">
						<div class="rf-stars" role="radiogroup" aria-label="Alege numărul de stele">
							<?php for ( $star = 5; 1 <= $star; $star-- ) : ?>
								<button type="submit" class="rf-star" name="rating" value="<?php echo esc_attr( $star ); ?>" aria-label="<?php echo esc_attr( $star . ' ' . ( 1 === $star ? 'stea' : 'stele' ) . ' — ' . $labels[ $star ] ); ?>" data-label="<?php echo esc_attr( $labels[ $star ] ); ?>">
									<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5l2.94 5.96 6.58.96-4.76 4.64 1.12 6.55L12 17.52l-5.88 3.09 1.12-6.55L2.48 9.42l6.58-.96z"/></svg>
								</button>
							<?php endfor; ?>
						</div>
						<div class="rf-star-label" id="rfStarLabel" aria-live="polite">Atinge o stea pentru a evalua</div>
					</form>

				<?php elseif ( 'feedback' === $state ) : ?>
					<div class="rf-inline-feedback">
						<?php require __DIR__ . '/templates/feedback-form.php'; ?>
					</div>

				<?php elseif ( in_array( $state, [ 'thanks_google', 'redirect_google' ], true ) ) : ?>
					<div class="rf-center">
						<div class="rf-emoji" aria-hidden="true">🎉</div>
						<h2 class="rf-question">Mulțumim pentru cele <?php echo esc_html( $rating ); ?> stele!</h2>
						<?php if ( 'redirect_google' === $state ) : ?>
							<p class="rf-text">Te ducem acum pe Google pentru a publica recenzia. Dacă pagina nu se deschide în câteva secunde, apasă butonul de mai jos.</p>
						<?php else : ?>
							<p class="rf-text">Ne-ar ajuta enorm dacă ai copia părerea ta și pe Google. Durează mai puțin de un minut.</p>
						<?php endif; ?>
						<a class="rf-btn rf-btn-google" href="<?php echo esc_url( $google_url ); ?>" rel="noopener" id="rfGoogleBtn"<?php echo 'redirect_google' === $state ? ' data-rf-autoredirect' : ''; ?>>
							<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M23.5 12.3c0-.8-.1-1.6-.2-2.3H12v4.4h6.5a5.6 5.6 0 0 1-2.4 3.6v3h3.9c2.2-2.1 3.5-5.1 3.5-8.7z"/><path fill="#34A853" d="M12 24c3.2 0 6-1.1 8-2.9l-3.9-3c-1.1.7-2.5 1.2-4.1 1.2-3.1 0-5.8-2.1-6.7-5H1.3v3.1A12 12 0 0 0 12 24z"/><path fill="#FBBC05" d="M5.3 14.3a7.2 7.2 0 0 1 0-4.6V6.6H1.3a12 12 0 0 0 0 10.8z"/><path fill="#EA4335" d="M12 4.8c1.8 0 3.3.6 4.6 1.8l3.4-3.4A12 12 0 0 0 1.3 6.6l4 3.1c.9-2.9 3.6-4.9 6.7-4.9z"/></svg>
							Scrie recenzia pe Google
						</a>
					</div>

				<?php elseif ( 'thanks_feedback' === $state ) : ?>
					<div class="rf-center">
						<div class="rf-emoji" aria-hidden="true">🙏</div>
						<h2 class="rf-question">Mulțumim că ne-ai spus!</h2>
						<p class="rf-text">Mesajul tău a ajuns direct la echipa noastră. Ne pare rău pentru experiență și vom face tot posibilul să remediem situația.</p>
					</div>

				<?php elseif ( 'already' === $state ) : ?>
					<div class="rf-center">
						<div class="rf-emoji" aria-hidden="true">💬</div>
						<h2 class="rf-question">Am primit deja părerea ta</h2>
						<p class="rf-text">Ai trimis recent o evaluare pentru această afacere de pe acest dispozitiv. Îți mulțumim! Dacă vrei să adaugi ceva, te rugăm să revii mai târziu.</p>
					</div>

				<?php else : ?>
					<div class="rf-center">
						<div class="rf-emoji" aria-hidden="true">💙</div>
						<h2 class="rf-question">Mulțumim pentru evaluare!</h2>
						<p class="rf-text">Părerea ta contează pentru noi.</p>
					</div>
				<?php endif; ?>
			</div>
		</section>

		<?php if ( 'rate' === $state ) : ?>
			<div class="rf-modal" id="rfModal" role="dialog" aria-modal="true" aria-labelledby="rfModalTitle" hidden>
				<div class="rf-modal-backdrop" data-rf-close></div>
				<div class="rf-modal-dialog">
					<button type="button" class="rf-modal-close" data-rf-close aria-label="Închide">&times;</button>
					<?php require __DIR__ . '/templates/feedback-form.php'; ?>
				</div>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( ! $white_label ) : ?>
		<footer class="rf-footer">
			<svg viewBox="0 0 24 24" aria-hidden="true" class="rf-footer-icon"><path d="M12 2l8 3v6c0 5-3.4 9.4-8 11-4.6-1.6-8-6-8-11V5z"/></svg> Protejat de <strong><?php echo esc_html( app_name() ); ?></strong> · <a href="https://www.ahoi.ro/" target="_blank" rel="noopener">Ahoi Digital</a>
		</footer>
	<?php endif; ?>
</main>
<?php if ( $company ) : ?>
	<script src="<?php echo esc_url( asset_url( 'js/review.js' ) ); ?>" defer></script>
<?php endif; ?>
</body>
</html>
