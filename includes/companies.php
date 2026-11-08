<?php
/**
 * Company helpers (tenant-scoped, operator-aware), slugs, Google review links, public URLs
 * and employee / department tracking tags (?ref=).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

/**
 * Companies owned by a user.
 *
 * @param int $user_id Owner ID.
 * @return array
 */
function get_user_companies( $user_id ) {
	return db_all( 'SELECT * FROM companies WHERE user_id = ? ORDER BY name ASC', [ (int) $user_id ] );
}

/**
 * Number of companies owned by a user.
 *
 * @param int $user_id Owner ID.
 * @return int
 */
function count_user_companies( $user_id ) {
	return (int) db_value( 'SELECT COUNT(*) FROM companies WHERE user_id = ?', [ (int) $user_id ] );
}

/**
 * Loads a company only if the current user owns it (super admins bypass the owner check).
 * Operators get only the companies assigned to them by their owner.
 *
 * @param int $company_id Company ID.
 * @return array|null
 */
function get_owned_company( $company_id ) {
	$user = current_user();

	if ( ! $user || 0 >= (int) $company_id ) {
		return null;
	}

	if ( 'super_admin' === $user['role'] ) {
		return db_row( 'SELECT * FROM companies WHERE id = ?', [ (int) $company_id ] );
	}

	if ( 'operator' === $user['role'] ) {
		if ( ! in_array( (int) $company_id, get_operator_company_ids( (int) $user['id'] ), true ) ) {
			return null;
		}

		return db_row( 'SELECT * FROM companies WHERE id = :company_id AND user_id = :user_id', [
			'company_id' => (int) $company_id,
			'user_id'    => (int) $user['parent_id'],
		] );
	}

	return db_row( 'SELECT * FROM companies WHERE id = :company_id AND user_id = :user_id', [
		'company_id' => (int) $company_id,
		'user_id'    => (int) $user['id'],
	] );
}

/**
 * Loads an active company by slug for the public funnel (includes owner subscription data).
 *
 * @param string $slug Slug.
 * @return array|null
 */
function get_public_company_by_slug( $slug ) {
	$slug = sanitize_slug( $slug, 100 );

	if ( '' === $slug ) {
		return null;
	}

	return db_row( 'SELECT * FROM companies WHERE slug = ? AND is_active = 1', [ $slug ] );
}

/**
 * Whether a slug is free (optionally ignoring a company).
 *
 * @param string $slug       Slug.
 * @param int    $exclude_id Company to ignore.
 * @return bool
 */
function is_slug_available( $slug, $exclude_id = 0 ) {
	return 0 === (int) db_value( 'SELECT COUNT(*) FROM companies WHERE slug = ? AND id <> ?', [ $slug, (int) $exclude_id ] );
}

/**
 * Generates a unique slug from a base string.
 *
 * @param string $base       Name or desired slug.
 * @param int    $exclude_id Company to ignore.
 * @return string
 */
function generate_unique_slug( $base, $exclude_id = 0 ) {
	$slug = sanitize_slug( $base, 80 );

	if ( 3 > strlen( $slug ) ) {
		$slug = trim( $slug . '-' . bin2hex( random_bytes( 2 ) ), '-' );
	}

	$candidate = $slug;
	$suffix    = 2;

	while ( ! is_slug_available( $candidate, $exclude_id ) ) {
		$candidate = $slug . '-' . $suffix;
		++$suffix;
	}

	return $candidate;
}

/**
 * Validates a Google review URL (https + Google host allow-list).
 *
 * @param string $url URL.
 * @return bool
 */
function is_valid_google_review_url( $url ) {
	$url = trim( (string) $url );

	if ( '' === $url || 500 < strlen( $url ) || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
		return false;
	}

	$parts = parse_url( $url );

	if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return false;
	}

	$allowed_hosts = [
		'search.google.com',
		'www.google.com',
		'google.com',
		'www.google.ro',
		'google.ro',
		'maps.google.com',
		'maps.google.ro',
		'business.google.com',
		'g.page',
		'maps.app.goo.gl',
		'goo.gl',
		'g.co',
	];

	return in_array( strtolower( $parts['host'] ), $allowed_hosts, true );
}

/**
 * Parses whatever the owner pasted as "Google review link": the g.page short link
 * (https://g.page/r/.../review, recommended), a writereview?placeid= URL, another Google
 * review URL, or a raw Place ID (ChIJ...). A missing https:// is added.
 *
 * @param string $input Raw input.
 * @return array{url:string,place_id:string,error:string}
 */
function parse_google_review_input( $input ) {
	$input  = trim( sanitize_text( $input, 500 ) );
	$result = [
		'url'      => '',
		'place_id' => '',
		'error'    => '',
	];

	if ( '' === $input ) {
		return $result;
	}

	if ( false === strpos( $input, '/' ) && is_valid_place_id( $input ) ) {
		$result['place_id'] = $input;

		return $result;
	}

	if ( ! preg_match( '#^https?://#i', $input ) ) {
		$input = 'https://' . ltrim( $input, '/' );
	}

	$input = preg_replace( '#^http://#i', 'https://', $input );

	if ( is_valid_google_review_url( $input ) ) {
		$result['url'] = $input;

		return $result;
	}

	$result['error'] = 'Linkul Google nu este recunoscut. Folosește linkul scurt https://g.page/r/... (butonul „Solicită recenzii” din Google), un link https://search.google.com/local/writereview?placeid=... sau Place ID-ul (ChIJ...).';

	return $result;
}

/**
 * Validates a Romanian fiscal code (CUI / CIF) with its control digit (key 753217532).
 *
 * @param string $digits 2-10 digits, without the RO prefix.
 * @return bool
 */
function is_valid_romanian_cui( $digits ) {
	if ( 1 !== preg_match( '/^[0-9]{2,10}$/', (string) $digits ) ) {
		return false;
	}

	$control = (int) substr( $digits, -1 );
	$body    = str_pad( substr( $digits, 0, -1 ), 9, '0', STR_PAD_LEFT );
	$key     = '753217532';
	$sum     = 0;

	for ( $i = 0; 9 > $i; $i++ ) {
		$sum += (int) $body[ $i ] * (int) $key[ $i ];
	}

	$expected = $sum * 10 % 11;

	return ( 10 === $expected ? 0 : $expected ) === $control;
}

/**
 * Parses the company fiscal code (required for invoicing): spaces, dots and dashes are removed,
 * the optional "RO" prefix (VAT payer) is kept in upper case, the control digit is checked.
 *
 * @param string $input Raw input.
 * @return array{value:string,error:string}
 */
function parse_tax_id( $input ) {
	$value = strtoupper( preg_replace( '/[\s.\-]+/', '', (string) $input ) );

	if ( '' === $value ) {
		return [
			'value' => '',
			'error' => 'Introdu codul fiscal (CUI / CIF) al firmei — este necesar pentru facturare.',
		];
	}

	$prefix = 0 === strpos( $value, 'RO' ) ? 'RO' : '';
	$digits = '' !== $prefix ? substr( $value, 2 ) : $value;

	if ( ! is_valid_romanian_cui( $digits ) ) {
		return [
			'value' => $value,
			'error' => 'Codul fiscal „' . mb_substr( $value, 0, 20 ) . '” nu este valid. Verifică cifrele (ex: 14399840 sau RO14399840).',
		];
	}

	return [
		'value' => $prefix . ltrim( $digits, '0' ),
		'error' => '',
	];
}

/**
 * Companies of an owner that cannot be invoiced yet (no fiscal code).
 *
 * @param int $owner_id Owner ID.
 * @return array
 */
function get_companies_missing_tax_id( $owner_id ) {
	return db_all( "SELECT id, name FROM companies WHERE user_id = ? AND tax_id = '' ORDER BY name ASC", [ (int) $owner_id ] );
}

/**
 * Billing details table (company name + fiscal code) for admin emails. Escaped HTML.
 *
 * @param int $owner_id Owner ID.
 * @return string
 */
function render_billing_companies_html( $owner_id ) {
	$rows = '';

	foreach ( get_user_companies( (int) $owner_id ) as $company ) {
		$rows .= '<tr><td style="border-top:1px solid #E2E8F0;">' . esc_html( $company['name'] ) . '</td>'
			. '<td style="border-top:1px solid #E2E8F0;font-weight:600;">' . esc_html( '' !== $company['tax_id'] ? $company['tax_id'] : '— lipsă —' ) . '</td></tr>';
	}

	if ( '' === $rows ) {
		return '';
	}

	return '<p style="margin:16px 0 6px;"><strong>Date de facturare</strong></p>'
		. '<table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="border:1px solid #E2E8F0;border-radius:10px;font-size:13px;border-collapse:separate;">'
		. '<tr style="background:#F8FAFC;color:#64748B;"><td>Companie</td><td>Cod fiscal (CUI / CIF)</td></tr>'
		. $rows
		. '</table>';
}

/**
 * Validates a Google Place ID.
 *
 * @param string $place_id Place ID.
 * @return bool
 */
function is_valid_place_id( $place_id ) {
	return 1 === preg_match( '/^[A-Za-z0-9_\-]{10,255}$/', (string) $place_id );
}

/**
 * Builds the "write a review" URL from a Place ID.
 *
 * @param string $place_id Place ID.
 * @return string
 */
function build_google_review_url( $place_id ) {
	return 'https://search.google.com/local/writereview?placeid=' . rawurlencode( $place_id );
}

/**
 * Resolves the Google review URL of a company (explicit link wins, then Place ID).
 *
 * @param array $company Company row.
 * @return string Empty when not configured.
 */
function get_company_google_url( array $company ) {
	if ( is_valid_google_review_url( $company['google_review_url'] ?? '' ) ) {
		return $company['google_review_url'];
	}

	if ( is_valid_place_id( $company['google_place_id'] ?? '' ) ) {
		return build_google_review_url( $company['google_place_id'] );
	}

	return '';
}

/**
 * Public funnel URL of a company.
 *
 * @param array  $company Company row.
 * @param string $source  Optional scan source (e.g. qr_a4, widget, email_sig).
 * @param string $ref     Optional employee / department tag slug.
 * @return string
 */
function get_company_public_url( array $company, $source = '', $ref = '' ) {
	$query  = [];
	$source = sanitize_source( $source );
	$ref    = sanitize_ref_tag( $ref );

	if ( defined( 'PRETTY_URLS' ) && PRETTY_URLS ) {
		$path = 'r/' . $company['slug'];
	} else {
		$path       = 'review.php';
		$query['b'] = $company['slug'];
	}

	if ( 'direct' !== $source ) {
		$query['src'] = $source;
	}

	if ( '' !== $ref ) {
		$query['ref'] = $ref;
	}

	return app_url( $path, $query );
}

/**
 * Normalizes a tracking tag slug (?ref=).
 *
 * @param mixed $ref Raw value.
 * @return string Empty when invalid.
 */
function sanitize_ref_tag( $ref ) {
	$ref = is_scalar( $ref ) ? strtolower( trim( (string) $ref ) ) : '';

	return preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $ref ) ? $ref : '';
}

/**
 * Tracking tags of a company.
 *
 * @param int  $company_id  Company ID (ownership checked by the caller).
 * @param bool $only_active Only active tags.
 * @return array
 */
function get_company_ref_tags( $company_id, $only_active = true ) {
	return db_all(
		'SELECT * FROM ref_tags WHERE company_id = ?' . ( $only_active ? ' AND is_active = 1' : '' ) . ' ORDER BY label ASC',
		[ (int) $company_id ]
	);
}

/**
 * Loads an active tracking tag of a company by slug.
 *
 * @param int    $company_id Company ID.
 * @param string $slug       Tag slug.
 * @return array|null
 */
function get_company_ref_tag( $company_id, $slug ) {
	$slug = sanitize_ref_tag( $slug );

	if ( '' === $slug ) {
		return null;
	}

	return db_row( 'SELECT * FROM ref_tags WHERE company_id = ? AND slug = ? AND is_active = 1', [ (int) $company_id, $slug ] );
}

/**
 * Creates a tracking tag with a unique slug for the company.
 *
 * @param int    $company_id Company ID (ownership checked by the caller).
 * @param string $label      Human label (e.g. "Chelner Mihai").
 * @return array{success:bool,error:string,slug:string}
 */
function create_ref_tag( $company_id, $label ) {
	$label = sanitize_text( $label, 80 );
	$base  = sanitize_slug( $label, 60 );

	if ( 2 > mb_strlen( $label ) || '' === $base ) {
		return [
			'success' => false,
			'error'   => 'Introdu un nume de etichetă (ex: Chelner Mihai, Recepție, Masa 4).',
			'slug'    => '',
		];
	}

	if ( 100 <= (int) db_value( 'SELECT COUNT(*) FROM ref_tags WHERE company_id = ?', [ (int) $company_id ] ) ) {
		return [
			'success' => false,
			'error'   => 'Ai atins numărul maxim de 100 de etichete pentru această companie.',
			'slug'    => '',
		];
	}

	$slug   = $base;
	$suffix = 2;

	while ( 0 < (int) db_value( 'SELECT COUNT(*) FROM ref_tags WHERE company_id = ? AND slug = ?', [ (int) $company_id, $slug ] ) ) {
		$slug = $base . '-' . $suffix;
		++$suffix;
	}

	db_query( 'INSERT INTO ref_tags (company_id, label, slug) VALUES (?, ?, ?)', [ (int) $company_id, $label, $slug ] );

	return [
		'success' => true,
		'error'   => '',
		'slug'    => $slug,
	];
}

/**
 * Human label of a tracking tag (falls back to the slug for deleted tags).
 *
 * @param string      $slug  Tag slug.
 * @param string|null $label Joined label.
 * @return string
 */
function format_ref_tag( $slug, $label = null ) {
	if ( '' === (string) $slug ) {
		return '—';
	}

	return null !== $label && '' !== $label ? (string) $label : (string) $slug;
}

/**
 * Normalizes a scan source value.
 *
 * @param mixed $source Raw source.
 * @return string
 */
function sanitize_source( $source ) {
	$source = is_scalar( $source ) ? strtolower( (string) $source ) : '';

	return preg_match( '/^[a-z0-9_]{1,32}$/', $source ) ? $source : 'direct';
}

/**
 * Human label for a scan source.
 *
 * @param string $source Source key.
 * @return string
 */
function get_source_label( $source ) {
	$labels = [
		'direct'     => 'Link direct',
		'qr_a4'      => 'QR Afiș A4',
		'qr_a5'      => 'QR Flyer A5',
		'qr_card'    => 'QR Carte de vizită',
		'qr_sticker' => 'QR Sticker',
		'qr'         => 'Cod QR',
		'widget'     => 'Widget website',
		'email_sig'  => 'Semnătură email',
	];

	return $labels[ $source ] ?? $source;
}

/**
 * Currently selected company ID for the dashboard (0 = all companies). Validated against ownership.
 *
 * @return int
 */
function get_current_company_id() {
	$company_id = (int) ( $_SESSION['current_company_id'] ?? 0 );

	if ( 0 < $company_id && ! get_owned_company( $company_id ) ) {
		$_SESSION['current_company_id'] = 0;

		return 0;
	}

	return $company_id;
}

/**
 * Deletes a company with its uploaded files (reviews cascade in DB).
 *
 * @param array $company Company row (already ownership-checked).
 * @return void
 */
function delete_company( array $company ) {
	// Reviews, views, tags and operator assignments cascade in the database.
	db_query( 'DELETE FROM companies WHERE id = ?', [ (int) $company['id'] ] );

	delete_upload( $company['logo_path'] );
	delete_upload( $company['cover_path'] );

	if ( (int) ( $_SESSION['current_company_id'] ?? 0 ) === (int) $company['id'] ) {
		$_SESSION['current_company_id'] = 0;
	}
}

/**
 * Printable template catalogue.
 *
 * @return array
 */
function get_printable_templates() {
	return [
		'a4'      => [
			'label'  => 'Afiș A4 / Suport tejghea',
			'size'   => '210mm 297mm',
			'width'  => 210,
			'height' => 297,
			'source' => 'qr_a4',
			'pages'  => 1,
		],
		'a5'      => [
			'label'  => 'Flyer A5 / Table tent',
			'size'   => '148mm 210mm',
			'width'  => 148,
			'height' => 210,
			'source' => 'qr_a5',
			'pages'  => 1,
		],
		'card'    => [
			'label'  => 'Carte de vizită 85 × 55 mm (față + verso)',
			'size'   => '85mm 55mm',
			'width'  => 85,
			'height' => 55,
			'source' => 'qr_card',
			'pages'  => 2,
		],
		'sticker' => [
			'label'  => 'Sticker masă 100 × 100 mm',
			'size'   => '100mm 100mm',
			'width'  => 100,
			'height' => 100,
			'source' => 'qr_sticker',
			'pages'  => 1,
		],
	];
}
