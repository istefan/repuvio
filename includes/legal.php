<?php
/**
 * Legal & GDPR module: provider details (legal_* settings), public legal page URLs, cookie consent,
 * and the public contact form (validation, storage in contact_messages, admin notification).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

const COOKIE_CONSENT_NAME = 'repuvio_cookie_consent';

/**
 * Editable provider / legal settings with their defaults and admin labels.
 *
 * @return array key => [ default, label ]
 */
function get_legal_setting_fields() {
	return [
		'legal_company_name' => [ 'AHOI DIGITAL SRL', 'Denumire furnizor (persoană juridică)' ],
		'legal_company_url'  => [ 'https://www.ahoi.ro/', 'Website furnizor' ],
		'legal_cui'          => [ '46112959', 'CUI / CIF' ],
		'legal_reg_com'      => [ 'J23/3075/2022', 'Nr. Registrul Comerțului' ],
		'legal_caen'         => [ '6311 – Prelucrarea datelor, administrarea paginilor web și activități conexe', 'Cod CAEN' ],
		'legal_address'      => [ 'Str. Sfânta Agnes, nr. 191, Vila 7, Popești-Leordeni, Ilfov, România', 'Sediu social' ],
		'legal_email'        => [ 'iftimie.s@gmail.com', 'Email contact / GDPR' ],
		'legal_phone'        => [ '0762 82 33 33', 'Telefon' ],
		'legal_last_updated' => [ '01 Noiembrie 2026', 'Data ultimei actualizări a documentelor' ],
	];
}

/**
 * Current provider details (settings, falling back to the defaults).
 *
 * @return array key => value
 */
function get_legal_settings() {
	$values = [];

	foreach ( get_legal_setting_fields() as $key => $field ) {
		$value          = trim( get_setting( $key, $field[0] ) );
		$values[ $key ] = '' !== $value ? $value : $field[0];
	}

	return $values;
}

/**
 * Whether provider details still contain the "...." / "xx" placeholders from the seed.
 *
 * @return array Labels of fields that still look like placeholders.
 */
function get_legal_placeholders() {
	$fields  = get_legal_setting_fields();
	$missing = [];

	foreach ( get_legal_settings() as $key => $value ) {
		if ( false !== strpos( $value, '....' ) || false !== stripos( $value, 'xx' ) ) {
			$missing[] = $fields[ $key ][1];
		}
	}

	return $missing;
}

/**
 * tel: link for a Romanian phone number written for humans (e.g. "0762 82 33 33" → "tel:+40762823333").
 *
 * @param string $phone Phone number.
 * @return string Empty when the number has too few digits.
 */
function phone_tel_url( $phone ) {
	$digits = preg_replace( '/[^0-9+]/', '', (string) $phone );

	if ( 9 > strlen( preg_replace( '/[^0-9]/', '', $digits ) ) ) {
		return '';
	}

	if ( 0 === strpos( $digits, '0' ) && 0 !== strpos( $digits, '00' ) ) {
		$digits = '+40' . substr( $digits, 1 );
	}

	return 'tel:' . $digits;
}

/**
 * Public legal / contact pages: key => [ title, pretty route, file ].
 *
 * @return array
 */
function get_public_pages() {
	return [
		'terms'   => [ 'Termeni și Condiții', 'termeni-si-conditii', 'terms.php' ],
		'privacy' => [ 'Politica de Confidențialitate', 'confidentialitate', 'privacy.php' ],
		'cookies' => [ 'Politica de Cookies', 'politica-cookies', 'cookies.php' ],
		'contact' => [ 'Contact', 'contact', 'contact.php' ],
	];
}

/**
 * URL of a public legal page (pretty route when enabled).
 *
 * @param string $key terms|privacy|cookies|contact.
 * @return string
 */
function legal_url( $key ) {
	$pages = get_public_pages();

	if ( ! isset( $pages[ $key ] ) ) {
		return app_url();
	}

	return app_url( defined( 'PRETTY_URLS' ) && PRETTY_URLS ? $pages[ $key ][1] : $pages[ $key ][2] );
}

/**
 * Whether the visitor already dismissed the cookie information banner.
 *
 * @return bool
 */
function has_cookie_consent() {
	return '1' === (string) ( $_COOKIE[ COOKIE_CONSENT_NAME ] ?? '' );
}

/**
 * Contact form subjects.
 *
 * @return array
 */
function get_contact_subjects() {
	return [
		'enterprise'  => 'Ofertă Plan Enterprise',
		'commercial'  => 'Întrebare Comercială',
		'support'     => 'Suport Tehnic',
		'partnership' => 'Parteneriat',
	];
}

/**
 * Contact message status labels and pill classes.
 *
 * @return array status => [ label, pill class ]
 */
function get_contact_statuses() {
	return [
		'new'      => [ 'Nou', 'rm-pill-trial' ],
		'read'     => [ 'Citit', 'rm-pill-muted' ],
		'replied'  => [ 'Răspuns trimis', 'rm-pill-positive' ],
		'archived' => [ 'Arhivat', 'rm-pill-muted' ],
	];
}

/**
 * Signed render timestamp for the contact form (bots that post instantly are ignored).
 *
 * @return string
 */
function contact_form_timer() {
	$time = (string) time();

	return $time . '.' . hash_hmac( 'sha256', 'contact|' . $time, APP_KEY );
}

/**
 * Validates the contact form timer: between 3 seconds and 2 hours old.
 *
 * @param string $value Submitted value.
 * @return bool
 */
function contact_form_timer_ok( $value ) {
	$parts = explode( '.', (string) $value );

	if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! hash_equals( hash_hmac( 'sha256', 'contact|' . $parts[0], APP_KEY ), $parts[1] ) ) {
		return false;
	}

	$age = time() - (int) $parts[0];

	return 3 <= $age && 7200 >= $age;
}

/**
 * Validates, stores and emails a contact form submission.
 *
 * @param array $input name, company, email, phone, subject, message, gdpr, website (honeypot), ft (timer).
 * @return array{success:bool,errors:array,spam:bool,data:array}
 */
function handle_contact_submission( array $input ) {
	$subjects = get_contact_subjects();
	$data     = [
		'name'    => sanitize_text( $input['name'] ?? '', 150 ),
		'company' => sanitize_text( $input['company'] ?? '', 150 ),
		'email'   => sanitize_email_address( $input['email'] ?? '' ),
		'phone'   => sanitize_text( $input['phone'] ?? '', 50 ),
		'subject' => array_key_exists( $input['subject'] ?? '', $subjects ) ? $input['subject'] : 'commercial',
		'message' => sanitize_textarea( $input['message'] ?? '', 5000 ),
		'gdpr'    => '1' === ( $input['gdpr'] ?? '' ),
	];
	$errors   = [];

	// Honeypot or instant submit: pretend success, store nothing.
	if ( '' !== (string) ( $input['website'] ?? '' ) || ! contact_form_timer_ok( $input['ft'] ?? '' ) ) {
		return [
			'success' => true,
			'errors'  => [],
			'spam'    => true,
			'data'    => $data,
		];
	}

	if ( rate_limit_is_locked( 'contact_form' ) ) {
		$errors[] = 'Ai trimis deja mai multe mesaje. Te rugăm să încerci din nou peste o oră.';
	}

	if ( 2 > mb_strlen( $data['name'] ) ) {
		$errors[] = 'Introdu numele tău complet.';
	}

	if ( '' === $data['email'] ) {
		$errors[] = 'Introdu o adresă de email validă.';
	}

	if ( 10 > mb_strlen( $data['message'] ) ) {
		$errors[] = 'Mesajul trebuie să aibă cel puțin 10 caractere.';
	}

	if ( ! $data['gdpr'] ) {
		$errors[] = 'Trebuie să confirmi că ai citit Politica de Confidențialitate.';
	}

	if ( ! empty( $errors ) ) {
		return [
			'success' => false,
			'errors'  => $errors,
			'spam'    => false,
			'data'    => $data,
		];
	}

	rate_limit_hit( 'contact_form', 5, 3600, 3600 );

	db_query(
		'INSERT INTO contact_messages (name, company, email, phone, subject, message, gdpr_consent, ip_hash, user_agent) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)',
		[ $data['name'], $data['company'], $data['email'], $data['phone'], $data['subject'], $data['message'], client_ip_hash(), client_user_agent() ]
	);

	$message_id = db_insert_id();
	$result     = send_contact_notification_email( $message_id, $data );

	if ( $result['success'] ) {
		db_query( 'UPDATE contact_messages SET email_sent = 1 WHERE id = ?', [ $message_id ] );
	}

	return [
		'success' => true,
		'errors'  => [],
		'spam'    => false,
		'data'    => $data,
	];
}

/**
 * Notifies the platform owner about a new contact message (support email, legal email, active
 * super admins) through the `admin_contact_or_order` template.
 *
 * @param int   $message_id Message ID.
 * @param array $data       Sanitized message data.
 * @return array send_templated_email() result.
 */
function send_contact_notification_email( $message_id, array $data ) {
	$subject_label = get_contact_subjects()[ $data['subject'] ] ?? $data['subject'];
	$details       = '<table role="presentation" cellpadding="6" cellspacing="0" style="width:100%;border:1px solid #E2E8F0;border-radius:10px;font-size:14px;margin-top:12px;">'
		. '<tr><td style="color:#64748B;width:120px;">Companie</td><td>' . esc_html( '' !== $data['company'] ? $data['company'] : '—' ) . '</td></tr>'
		. '<tr><td style="color:#64748B;vertical-align:top;">Mesaj</td><td>' . nl2br( esc_html( $data['message'] ) ) . '</td></tr>'
		. '</table>';

	return send_admin_notification(
		'Mesaj contact #' . (int) $message_id . ': ' . $subject_label,
		[
			'name'  => $data['name'],
			'email' => $data['email'],
			'phone' => $data['phone'],
		],
		$details,
		app_url( 'admin/contact-messages.php' ),
		[ (string) get_setting( 'legal_email' ) ]
	);
}

/**
 * Number of unread contact messages (admin sidebar badge).
 *
 * @return int
 */
function count_new_contact_messages() {
	return (int) db_value( "SELECT COUNT(*) FROM contact_messages WHERE status = 'new'" );
}
