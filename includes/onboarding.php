<?php
/**
 * Onboarding: the 3-step wizard helpers and the dashboard "Checklist de Activare Rapidă".
 *
 * Checklist milestones (auto-detected, owner scope):
 * 1. A company with a Google review link or Place ID.
 * 2. A logo or cover uploaded.
 * 3. The public page tested (at least one view or review).
 * 4. Print Studio or Widget & Email visited, or marked done (users.onboarding_materials_at).
 * The card disappears when all four are done or the owner hides it (users.onboarding_dismissed).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

/**
 * Default texts pre-filled by the wizard (kept out of step 1 so the owner is not overwhelmed).
 *
 * @return array{description:string,cta_text:string}
 */
function get_onboarding_defaults() {
	return [
		'description' => 'Mulțumim că ne-ai vizitat! Cum a fost experiența ta?',
		'cta_text'    => 'Scanează codul și spune-ne cum a fost!',
	];
}

/**
 * Whether a business owner should be sent to the wizard (no company yet and not skipped this session).
 *
 * @param array $user Current user.
 * @return bool
 */
function should_start_onboarding( array $user ) {
	return 'user' === $user['role']
		&& empty( $_SESSION['onboarding_skipped'] )
		&& 0 === count_user_companies( (int) $user['id'] );
}

/**
 * Records the "materials" milestone (first visit to Print Studio or Widget & Email).
 *
 * @param int $owner_id Owner ID.
 * @return void
 */
function mark_onboarding_materials( $owner_id ) {
	db_query( 'UPDATE users SET onboarding_materials_at = NOW() WHERE id = ? AND onboarding_materials_at IS NULL', [ (int) $owner_id ] );
}

/**
 * Activation checklist for a business owner.
 *
 * @param array $owner Owner row.
 * @return array{items:array,done:int,total:int,percent:int,visible:bool}
 */
function get_onboarding_checklist( array $owner ) {
	$companies    = get_user_companies( (int) $owner['id'] );
	$first        = $companies[0] ?? null;
	$has_google   = false;
	$has_brand    = false;
	$brand_target = $first;

	foreach ( $companies as $company ) {
		if ( '' !== get_company_google_url( $company ) ) {
			$has_google = true;
		}

		if ( ! empty( $company['logo_path'] ) || ! empty( $company['cover_path'] ) ) {
			$has_brand = true;
		}
	}

	$activity = 0;

	if ( ! empty( $companies ) ) {
		$activity = (int) db_value( 'SELECT COUNT(*) FROM funnel_views v INNER JOIN companies c ON c.id = v.company_id WHERE c.user_id = ?', [ (int) $owner['id'] ] )
			+ (int) db_value( 'SELECT COUNT(*) FROM reviews r INNER JOIN companies c ON c.id = r.company_id WHERE c.user_id = ?', [ (int) $owner['id'] ] );
	}

	$items = [
		[
			'key'    => 'company',
			'title'  => 'Creează prima ta companie și setează linkul Google',
			'text'   => 'Linkul g.page sau Place ID-ul trimite clienții mulțumiți direct la recenzie.',
			'done'   => $has_google,
			'action' => $first ? [ 'Setează linkul Google', app_url( 'dashboard/company-edit.php', [ 'id' => (int) $first['id'] ] ), false ] : [ 'Pornește ghidul', app_url( 'dashboard/onboarding.php' ), false ],
		],
		[
			'key'    => 'brand',
			'title'  => 'Personalizează imaginea firmei (Logo & Copertă)',
			'text'   => 'Clienții recunosc firma imediat, iar materialele printate arată profesionist.',
			'done'   => $has_brand,
			'action' => $brand_target ? [ 'Încarcă logo / copertă', app_url( 'dashboard/company-edit.php', [ 'id' => (int) $brand_target['id'] ] ), false ] : null,
		],
		[
			'key'    => 'test',
			'title'  => 'Testează pagina ta publică de recenzie',
			'text'   => 'Deschide pagina sau scanează codul QR cu telefonul, exact ca un client.',
			'done'   => 0 < $activity,
			'action' => $first ? [ 'Testează acum', get_company_public_url( $first ), true ] : null,
		],
		[
			'key'    => 'materials',
			'title'  => 'Descarcă un șablon QR sau activează Semnătura Email / Widget-ul',
			'text'   => 'Pune codul QR la vedere și adaugă linkul în emailuri și pe site.',
			'done'   => ! empty( $owner['onboarding_materials_at'] ),
			'action' => [ 'Deschide Print Studio', app_url( 'dashboard/printables.php' ), false ],
		],
	];

	$done = count(
		array_filter(
			$items,
			function ( $item ) {
				return $item['done'];
			}
		)
	);

	return [
		'items'   => $items,
		'done'    => $done,
		'total'   => count( $items ),
		'percent' => (int) round( $done / count( $items ) * 100 ),
		'visible' => 0 === (int) $owner['onboarding_dismissed'] && $done < count( $items ),
	];
}
