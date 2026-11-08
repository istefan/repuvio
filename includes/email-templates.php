<?php
/**
 * Email template registry ("Email & Șabloane"): factory defaults of every system email,
 * placeholder catalogue, realistic demo data for previews / test sends, and the DB accessors of
 * the `email_templates` table (editable subject + body, ON/OFF toggle, 1-click factory reset).
 *
 * The defaults below are the single source for the `default_subject` / `default_body_html`
 * columns. ensure_email_templates() inserts missing rows (migration v9 and self-healing on read);
 * `php tools/email-templates-sql.php` prints the same rows as INSERT IGNORE for schema.sql.
 *
 * Body HTML is the inner content only: send_templated_email() wraps it in the master layout
 * (render_email_layout()). Placeholder values are HTML-escaped, except keys ending in `_html`
 * (pre-built, escaped tables) and `_url` (passed through esc_url()).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

/**
 * Template categories (slug => Romanian label).
 *
 * @return array
 */
function get_email_template_categories() {
	return [
		'user_auth'      => 'Cont & autentificare',
		'reviews_digest' => 'Recenzii & rezumate',
		'billing_quota'  => 'Abonament & cotă',
		'admin_system'   => 'Notificări administrator',
	];
}

/**
 * Placeholders available in every template (key => description).
 *
 * @return array
 */
function get_global_email_placeholders() {
	return [
		'app_name'      => 'Numele aplicației (Setări → General)',
		'app_url'       => 'Adresa aplicației',
		'support_email' => 'Emailul de suport',
		'year'          => 'Anul curent',
		'accent_color'  => 'Culoarea de accent din Aspect global (pentru butoane)',
	];
}

/**
 * Call-to-action button used by the default bodies (inline styles, accent colour placeholder).
 *
 * @param string $url_placeholder Placeholder holding the URL, e.g. "{{dashboard_url}}".
 * @param string $label           Button label.
 * @return string
 */
function email_default_button( $url_placeholder, $label ) {
	return '<p style="margin:28px 0 8px;"><a href="' . $url_placeholder . '" style="display:inline-block;background:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600;font-size:14px;">' . $label . '</a></p>';
}

/**
 * Two-column detail table used by the default bodies.
 *
 * @param array $rows [ label, value placeholder ] pairs.
 * @return string
 */
function email_default_table( array $rows ) {
	$html = '<table role="presentation" cellpadding="6" cellspacing="0" style="width:100%;border:1px solid #E2E8F0;border-radius:10px;font-size:14px;margin:8px 0;">';

	foreach ( $rows as $row ) {
		$html .= '<tr><td style="color:#64748B;width:150px;vertical-align:top;">' . $row[0] . '</td><td>' . $row[1] . '</td></tr>';
	}

	return $html . '</table>';
}

/**
 * Puts each block element of a default body on its own line (readable in the admin editor;
 * whitespace between blocks does not change how emails render).
 *
 * @param string $html Body HTML.
 * @return string
 */
function email_pretty_html( $html ) {
	$html = preg_replace( '#(</(?:h1|h2|p|ol|li|table|tr)>)#', "$1
", (string) $html );
	$html = preg_replace( '#(<(?:table|ol)[^>]*>)#', "$1
", $html );

	return trim( preg_replace( "/
{2,}/", "
", $html ) );
}

/**
 * Factory defaults of every system email template.
 *
 * @return array slug => [ name, category, is_enabled, subject, body_html, placeholders ]
 */
function get_default_email_templates() {
	$templates = get_default_email_templates_raw();

	foreach ( $templates as &$template ) {
		$template['body_html'] = email_pretty_html( $template['body_html'] );
	}
	unset( $template );

	return $templates;
}

/**
 * Factory defaults as authored (see get_default_email_templates()).
 *
 * @return array
 */
function get_default_email_templates_raw() {
	$h1 = function ( $text ) {
		return '<h1 style="margin:0 0 16px;font-size:20px;color:#0F172A;">' . $text . '</h1>';
	};

	return [
		'welcome_trial'             => [
			'name'         => 'Bun venit & activare perioadă gratuită',
			'category'     => 'user_auth',
			'is_enabled'   => 1,
			'subject'      => 'Bine ai venit în {{app_name}}! Perioada ta gratuită a început',
			'body_html'    => $h1( 'Bine ai venit, {{user_name}}!' )
				. '<p>Contul <strong>{{user_email}}</strong> a fost creat. Ai <strong>{{trial_days}} zile gratuite</strong>, cu toate funcțiile deblocate, până la <strong>{{trial_ends_at}}</strong>.</p>'
				. '<p>În 3 pași simpli ești gata:</p>'
				. '<ol style="padding-left:20px;margin:0 0 8px;"><li>Adaugă compania și linkul tău Google Reviews.</li><li>Alege de la câte stele clienții sunt trimiși pe Google.</li><li>Printează codul QR din Print Studio și pune-l la vedere.</li></ol>'
				. email_default_button( '{{onboarding_url}}', 'Pornește ghidul de configurare' )
				. '<p style="font-size:13px;color:#64748B;">Poți intra oricând în panou de aici: <a href="{{dashboard_url}}" style="color:#64748B;">{{dashboard_url}}</a></p>',
			'placeholders' => [
				'user_name'      => 'Numele utilizatorului',
				'user_email'     => 'Emailul contului',
				'trial_days'     => 'Numărul de zile gratuite',
				'trial_ends_at'  => 'Data de final a perioadei gratuite',
				'dashboard_url'  => 'Link către panou',
				'onboarding_url' => 'Link către ghidul de configurare',
			],
		],
		'password_reset'            => [
			'name'         => 'Resetare parolă',
			'category'     => 'user_auth',
			'is_enabled'   => 1,
			'subject'      => 'Resetează parola contului tău {{app_name}}',
			'body_html'    => $h1( 'Resetează parola' )
				. '<p>Bună {{user_name}},</p>'
				. '<p>Am primit o cerere de resetare a parolei pentru contul tău (de la adresa IP {{ip_address}}). Linkul este valabil <strong>{{expires_in}}</strong> și poate fi folosit o singură dată.</p>'
				. email_default_button( '{{reset_url}}', 'Setează o parolă nouă' )
				. '<p style="font-size:13px;color:#64748B;">Dacă nu tu ai cerut resetarea, ignoră acest mesaj: parola ta rămâne neschimbată.</p>',
			'placeholders' => [
				'user_name'  => 'Numele utilizatorului',
				'reset_url'  => 'Linkul unic de resetare',
				'expires_in' => 'Valabilitatea linkului (ex: 60 de minute)',
				'ip_address' => 'Adresa IP de la care s-a cerut resetarea',
			],
		],
		'operator_invite'           => [
			'name'         => 'Invitație cont operator',
			'category'     => 'user_auth',
			'is_enabled'   => 1,
			'subject'      => '{{owner_name}} te-a invitat să gestionezi feedback-ul în {{app_name}}',
			'body_html'    => $h1( 'Ai fost invitat în echipă' )
				. '<p>Bună {{operator_name}},</p>'
				. '<p><strong>{{owner_name}}</strong> ți-a creat un cont de operator pentru <strong>{{company_name}}</strong>. Vei putea citi feedback-ul privat al clienților, schimba statusul tichetelor și adăuga note interne.</p>'
				. email_default_table(
					[
						[ 'Email de autentificare', '{{operator_email}}' ],
						[ 'Parolă temporară', '<strong>{{temp_password}}</strong>' ],
					]
				)
				. '<p>La prima autentificare vei fi rugat să îți alegi o parolă nouă.</p>'
				. email_default_button( '{{login_url}}', 'Autentifică-te' ),
			'placeholders' => [
				'operator_name'  => 'Numele operatorului',
				'operator_email' => 'Emailul operatorului',
				'owner_name'     => 'Numele proprietarului care a creat contul',
				'company_name'   => 'Companiile atribuite',
				'login_url'      => 'Link către pagina de autentificare',
				'temp_password'  => 'Parola inițială (trebuie schimbată la prima autentificare)',
			],
		],
		'instant_negative_feedback' => [
			'name'         => 'Alertă instantanee: feedback privat (sub prag)',
			'category'     => 'reviews_digest',
			'is_enabled'   => 1,
			'subject'      => 'Feedback nou ({{stars}}) — {{company_name}}',
			'body_html'    => $h1( 'Feedback privat nou' )
				. '<p>Un client a lăsat un feedback privat pentru <strong>{{company_name}}</strong>. Contactează-l cât mai repede: un client ascultat devine adesea un client fidel.</p>'
				. email_default_table(
					[
						[ 'Rating', '<strong style="color:#B91C1C;">{{stars}}</strong>' ],
						[ 'Nume', '{{customer_name}}' ],
						[ 'Contact', '{{customer_contact}}' ],
						[ 'Cod QR', '{{ref_tag}}' ],
						[ 'Primit la', '{{created_at}}' ],
						[ 'Mesaj', '{{message}}' ],
					]
				)
				. email_default_button( '{{feedback_url}}', 'Deschide tichetul' ),
			'placeholders' => [
				'company_name'     => 'Numele companiei',
				'stars'            => 'Stelele acordate (ex: ★★☆☆☆ 2/5)',
				'customer_name'    => 'Numele clientului (sau —)',
				'customer_contact' => 'Telefon / email client (sau —)',
				'message'          => 'Mesajul clientului',
				'ref_tag'          => 'Codul QR scanat (angajat / departament) sau „Cod general”',
				'created_at'       => 'Data și ora',
				'feedback_url'     => 'Link către tichet',
			],
		],
		'instant_positive_review'   => [
			'name'         => 'Alertă instantanee: redirecționare pozitivă Google',
			'category'     => 'reviews_digest',
			'is_enabled'   => 0,
			'subject'      => 'Un client mulțumit ({{stars}}) a fost trimis pe Google — {{company_name}}',
			'body_html'    => $h1( 'Încă un client mulțumit!' )
				. '<p>Un client a dat <strong>{{stars}}</strong> pentru <strong>{{company_name}}</strong> și a fost trimis să lase o recenzie pe Google.</p>'
				. email_default_table(
					[
						[ 'Cod QR', '{{ref_tag}}' ],
						[ 'Primit la', '{{created_at}}' ],
					]
				)
				. email_default_button( '{{dashboard_url}}', 'Vezi panoul' ),
			'placeholders' => [
				'company_name'  => 'Numele companiei',
				'stars'         => 'Stelele acordate',
				'ref_tag'       => 'Codul QR scanat',
				'created_at'    => 'Data și ora',
				'dashboard_url' => 'Link către panou',
			],
		],
		'daily_digest'              => [
			'name'         => 'Rezumatul zilei precedente',
			'category'     => 'reviews_digest',
			'is_enabled'   => 1,
			'subject'      => 'Rezumatul zilei de {{date_yesterday}} — {{company_name}}',
			'body_html'    => $h1( 'Rezumatul zilei de {{date_yesterday}}' )
				. '<p>Bună {{user_name}}, iată ce s-a întâmplat ieri la <strong>{{company_name}}</strong>:</p>'
				. email_default_table(
					[
						[ 'Scanări cod QR', '<strong>{{total_views}}</strong>' ],
						[ 'Trimiși pe Google', '<strong style="color:#15803D;">{{google_redirects}}</strong>' ],
						[ 'Feedback privat', '<strong style="color:#B91C1C;">{{private_feedbacks_count}}</strong>' ],
						[ 'Nota medie', '{{avg_rating}}' ],
					]
				)
				. '<h2 style="font-size:16px;margin:22px 0 8px;color:#0F172A;">Feedback privat primit</h2>'
				. '{{feedbacks_table_html}}'
				. email_default_button( '{{dashboard_url}}', 'Deschide panoul' ),
			'placeholders' => [
				'user_name'               => 'Numele proprietarului',
				'company_name'            => 'Numele companiei',
				'date_yesterday'          => 'Ziua rezumată (ex: 01.11.2026)',
				'total_views'             => 'Scanări / vizualizări în acea zi',
				'google_redirects'        => 'Clienți trimiși pe Google',
				'private_feedbacks_count' => 'Feedback-uri private primite',
				'avg_rating'              => 'Nota medie a zilei',
				'feedbacks_table_html'    => 'Tabel cu toate mesajele private ale zilei (generat)',
				'dashboard_url'           => 'Link către panou',
			],
		],
		'monthly_roi_report'        => [
			'name'         => 'Raport lunar ROI & valoare generată',
			'category'     => 'reviews_digest',
			'is_enabled'   => 1,
			'subject'      => 'Raport {{period_label}}: {{google_redirects}} clienți trimiși pe Google — {{app_name}}',
			'body_html'    => $h1( 'Valoarea generată: {{period_label}}' )
				. '<p>Bună {{user_name}}, iată ce a adus {{app_name}} afacerii tale în perioada <strong>{{period_label}}</strong>:</p>'
				. email_default_table(
					[
						[ 'Scanări cod QR', '<strong>{{total_views}}</strong>' ],
						[ 'Clienți trimiși pe Google', '<strong style="color:#15803D;">{{google_redirects}}</strong>' ],
						[ 'Negative interceptate privat', '<strong style="color:#B91C1C;">{{saved_negatives}}</strong> ({{resolved_count}} rezolvate)' ],
						[ 'Nota medie internă', '{{avg_rating}}' ],
						[ 'Conversie scanare → evaluare', '{{conversion}}' ],
					]
				)
				. '{{locations_table_html}}'
				. '{{top_ref_tags_html}}'
				. '<p style="margin-top:18px;font-size:13px;color:#64748B;">Sfat: pune codul QR la vedere în momentul plății sau la predarea comenzii, acolo conversia este cea mai mare.</p>'
				. email_default_button( '{{dashboard_url}}', 'Deschide panoul' ),
			'placeholders' => [
				'user_name'            => 'Numele proprietarului',
				'period_label'         => 'Perioada raportului (ex: octombrie 2026)',
				'total_views'          => 'Scanări / vizualizări',
				'google_redirects'     => 'Clienți trimiși pe Google',
				'saved_negatives'      => 'Feedback negativ interceptat',
				'resolved_count'       => 'Tichete rezolvate',
				'avg_rating'           => 'Nota medie internă',
				'conversion'           => 'Conversie scanare → evaluare',
				'locations_table_html' => 'Tabel pe locații (doar la mai multe companii, generat)',
				'top_ref_tags_html'    => 'Top angajați / coduri QR (planuri cu etichete, generat)',
				'dashboard_url'        => 'Link către panou',
			],
		],
		'quota_warning_80'          => [
			'name'         => 'Avertizare cotă lunară (80% consumat)',
			'category'     => 'billing_quota',
			'is_enabled'   => 1,
			'subject'      => 'Ai folosit 80% din recenziile lunare — {{app_name}}',
			'body_html'    => $h1( 'Ai folosit 80% din recenziile lunare' )
				. '<p>Bună {{user_name}},</p>'
				. '<p>Pe planul <strong>{{plan_name}}</strong> ai folosit <strong>{{used_reviews}} din {{max_reviews}}</strong> recenzii incluse luna aceasta.</p>'
				. '<p>Când limita este atinsă, clienții mulțumiți ajung în continuare pe Google, dar feedback-ul negativ nou este blocat. Un plan superior te ajută să nu pierzi niciun mesaj.</p>'
				. email_default_button( '{{billing_url}}', 'Vezi abonamentul' ),
			'placeholders' => [
				'user_name'    => 'Numele proprietarului',
				'plan_name'    => 'Planul curent',
				'used_reviews' => 'Recenzii folosite',
				'max_reviews'  => 'Recenzii incluse',
				'billing_url'  => 'Link către pagina Abonament',
			],
		],
		'quota_reached_100'         => [
			'name'         => 'Cotă lunară atinsă (100%)',
			'category'     => 'billing_quota',
			'is_enabled'   => 1,
			'subject'      => 'Ai atins limita lunară de recenzii — {{app_name}}',
			'body_html'    => $h1( 'Ai atins limita lunară de recenzii' )
				. '<p>Bună {{user_name}},</p>'
				. '<p>Ai folosit <strong>{{used_reviews}} din {{max_reviews}}</strong> recenzii incluse în planul <strong>{{plan_name}}</strong>.</p>'
				. '<p>Clienții mulțumiți sunt trimiși în continuare pe Google, însă feedback-ul negativ nou este <strong>blocat</strong> până la upgrade sau până la începutul noului ciclu.</p>'
				. email_default_button( '{{billing_url}}', 'Fă upgrade' ),
			'placeholders' => [
				'user_name'    => 'Numele proprietarului',
				'plan_name'    => 'Planul curent',
				'used_reviews' => 'Recenzii folosite',
				'max_reviews'  => 'Recenzii incluse',
				'billing_url'  => 'Link către pagina Abonament',
			],
		],
		'quota_exceeded_softlock'   => [
			'name'         => 'Cotă depășită / feedback privat blocat (soft-lock)',
			'category'     => 'billing_quota',
			'is_enabled'   => 1,
			'subject'      => 'Ai un feedback negativ blocat — {{company_name}}',
			'body_html'    => $h1( 'Feedback negativ în așteptare' )
				. '<p>Bună {{user_name}},</p>'
				. '<p>Un client a lăsat un feedback privat de <strong>{{stars}}</strong> pentru <strong>{{company_name}}</strong>.</p>'
				. '<p>Mesajul este <strong>blocat</strong> pentru că limita lunară a planului {{plan_name}} a fost atinsă sau abonamentul nu este activ. Reînnoiește sau fă upgrade ca să îl citești și să poți contacta clientul.</p>'
				. email_default_button( '{{billing_url}}', 'Deblochează feedback-ul' ),
			'placeholders' => [
				'user_name'    => 'Numele proprietarului',
				'company_name' => 'Numele companiei',
				'stars'        => 'Stelele acordate',
				'plan_name'    => 'Planul curent',
				'billing_url'  => 'Link către pagina Abonament',
			],
		],
		'trial_expiring_soon'       => [
			'name'         => 'Expirare perioadă gratuită / abonament în 3 zile',
			'category'     => 'billing_quota',
			'is_enabled'   => 1,
			'subject'      => '{{plan_name}} expiră pe {{expires_at}} — {{app_name}}',
			'body_html'    => $h1( 'Accesul tău expiră în curând' )
				. '<p>Bună {{user_name}},</p>'
				. '<p><strong>{{plan_name}}</strong> expiră în <strong>{{days_left}} zile</strong>, pe <strong>{{expires_at}}</strong>.</p>'
				. '<p>Alege sau reînnoiește abonamentul ca să primești în continuare feedback-ul privat al clienților, fără întrerupere.</p>'
				. email_default_button( '{{billing_url}}', 'Alege abonamentul' ),
			'placeholders' => [
				'user_name'   => 'Numele proprietarului',
				'plan_name'   => 'Planul (sau „Perioada de probă”)',
				'expires_at'  => 'Data expirării',
				'days_left'   => 'Zile rămase',
				'billing_url' => 'Link către pagina Abonament',
			],
		],
		'subscription_expired'      => [
			'name'         => 'Abonament / perioadă gratuită expirată',
			'category'     => 'billing_quota',
			'is_enabled'   => 1,
			'subject'      => 'Abonamentul a expirat — {{app_name}}',
			'body_html'    => $h1( 'Abonament expirat' )
				. '<p>Bună {{user_name}},</p>'
				. '<p><strong>{{plan_name}}</strong> a expirat. Linkurile și codurile QR funcționează în continuare, iar clienții mulțumiți ajung pe Google, dar feedback-ul negativ nou este blocat până la reînnoire.</p>'
				. email_default_button( '{{billing_url}}', 'Reînnoiește abonamentul' ),
			'placeholders' => [
				'user_name'   => 'Numele proprietarului',
				'plan_name'   => 'Planul (sau „Perioada de probă”)',
				'billing_url' => 'Link către pagina Abonament',
			],
		],
		'admin_new_user_alert'      => [
			'name'         => '[Admin] Utilizator nou înregistrat',
			'category'     => 'admin_system',
			'is_enabled'   => 1,
			'subject'      => '[Admin] Cont nou: {{user_name}} ({{user_email}})',
			'body_html'    => $h1( 'Utilizator nou înregistrat' )
				. email_default_table(
					[
						[ 'Nume', '<strong>{{user_name}}</strong>' ],
						[ 'Email', '{{user_email}}' ],
						[ 'Înregistrat la', '{{registered_at}}' ],
					]
				)
				. '<p>Perioada gratuită a început automat.</p>'
				. email_default_button( '{{admin_users_url}}', 'Vezi utilizatorii' ),
			'placeholders' => [
				'user_name'       => 'Numele noului utilizator',
				'user_email'      => 'Emailul noului utilizator',
				'registered_at'   => 'Data înregistrării',
				'admin_users_url' => 'Link către lista de utilizatori',
			],
		],
		'admin_backup_failed'       => [
			'name'         => '[Admin] Backup Google Drive eșuat',
			'category'     => 'admin_system',
			'is_enabled'   => 1,
			'subject'      => '[Admin] Backup eșuat: {{backup_label}} — {{app_name}}',
			'body_html'    => $h1( 'Backup-ul nu a reușit' )
				. '<p>Backup-ul <strong>{{backup_label}}</strong> ({{trigger_label}}) din {{started_at}} nu a putut fi salvat în folderul Google Drive <strong>{{folder_name}}</strong>.</p>'
				. email_default_table(
					[
						[ 'Eroare', '<span style="color:#B91C1C;">{{error_message}}</span>' ],
					]
				)
				. '<p>Cauze frecvente: contul Google a fost deconectat sau parola schimbată (reconectează-l), spațiul din Google Drive este plin, ori serverul nu are spațiu pe disc pentru arhiva temporară.</p>'
				. email_default_button( '{{backups_url}}', 'Deschide Backup Google Drive' ),
			'placeholders' => [
				'backup_label'  => 'Ce s-a salvat (bază de date / fișiere / complet)',
				'trigger_label' => 'Automat (cron) sau manual',
				'started_at'    => 'Data și ora',
				'error_message' => 'Mesajul de eroare',
				'folder_name'   => 'Folderul din Google Drive',
				'folder_url'    => 'Link către folderul din Google Drive',
				'backups_url'   => 'Link către pagina Backup Google Drive',
			],
		],
		'admin_backup_success'      => [
			'name'         => '[Admin] Backup Google Drive reușit',
			'category'     => 'admin_system',
			'is_enabled'   => 0,
			'subject'      => '[Admin] Backup reușit: {{backup_label}} ({{total_size}}) — {{app_name}}',
			'body_html'    => $h1( 'Backup salvat în Google Drive' )
				. '<p>Backup-ul <strong>{{backup_label}}</strong> ({{trigger_label}}) din {{started_at}} a fost salvat în folderul <strong>{{folder_name}}</strong>.</p>'
				. '{{files_html}}'
				. '<p>Dimensiune totală: <strong>{{total_size}}</strong>.</p>'
				. email_default_button( '{{folder_url}}', 'Deschide folderul în Google Drive' ),
			'placeholders' => [
				'backup_label'  => 'Ce s-a salvat (bază de date / fișiere / complet)',
				'trigger_label' => 'Automat (cron) sau manual',
				'started_at'    => 'Data și ora',
				'files_html'    => 'Tabel cu arhivele salvate (generat)',
				'total_size'    => 'Dimensiunea totală',
				'folder_name'   => 'Folderul din Google Drive',
				'folder_url'    => 'Link către folderul din Google Drive',
				'backups_url'   => 'Link către pagina Backup Google Drive',
			],
		],
		'admin_contact_or_order'    => [
			'name'         => '[Admin] Mesaj contact / comandă printuri / cerere abonament',
			'category'     => 'admin_system',
			'is_enabled'   => 1,
			'subject'      => '[Admin] {{subject_type}} — {{sender_name}}',
			'body_html'    => $h1( '{{subject_type}}' )
				. email_default_table(
					[
						[ 'De la', '<strong>{{sender_name}}</strong>' ],
						[ 'Email', '{{sender_email}}' ],
						[ 'Telefon', '{{sender_phone}}' ],
					]
				)
				. '{{details_html}}'
				. email_default_button( '{{admin_url}}', 'Deschide în panou' ),
			'placeholders' => [
				'sender_name'  => 'Numele expeditorului / clientului',
				'sender_email' => 'Emailul expeditorului',
				'sender_phone' => 'Telefonul expeditorului (sau —)',
				'subject_type' => 'Tipul cererii (ex: Mesaj contact: Ofertă comercială, Comandă printuri #12)',
				'details_html' => 'Detaliile mesajului / comenzii (generat)',
				'admin_url'    => 'Link către secțiunea din panoul admin',
			],
		],
	];
}

/**
 * Inserts the factory templates that are missing (idempotent). Rows the admin never edited follow
 * the current factory text; edited rows keep their edits. The default_* columns are always
 * refreshed, so "Resetează" restores the current factory text. MySQL applies the assignments left
 * to right, so the subject / body comparisons still see the old defaults.
 *
 * @return void
 */
function ensure_email_templates() {
	foreach ( get_default_email_templates() as $slug => $template ) {
		db_query(
			'INSERT INTO email_templates (slug, name, category, is_enabled, subject, body_html, default_subject, default_body_html, available_placeholders)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
			ON DUPLICATE KEY UPDATE name = VALUES(name), category = VALUES(category),
				subject = IF(subject = default_subject, VALUES(subject), subject),
				body_html = IF(body_html = default_body_html, VALUES(body_html), body_html),
				default_subject = VALUES(default_subject), default_body_html = VALUES(default_body_html),
				available_placeholders = VALUES(available_placeholders)',
			[
				$slug,
				$template['name'],
				$template['category'],
				$template['is_enabled'],
				$template['subject'],
				$template['body_html'],
				$template['subject'],
				$template['body_html'],
				email_template_json( $template['placeholders'] ),
			]
		);
	}
}

/**
 * JSON encoding for stored placeholder lists (unescaped unicode and slashes, readable in the DB).
 *
 * @param array $data Data.
 * @return string
 */
function email_template_json( array $data ) {
	return (string) json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

/**
 * Loads one template row (seeds the table first when it is empty).
 *
 * @param string $slug Template slug.
 * @return array|null
 */
function get_email_template( $slug ) {
	$row = db_row( 'SELECT * FROM email_templates WHERE slug = ?', [ (string) $slug ] );

	if ( ! $row && isset( get_default_email_templates()[ $slug ] ) ) {
		ensure_email_templates();
		$row = db_row( 'SELECT * FROM email_templates WHERE slug = ?', [ (string) $slug ] );
	}

	return $row ?: null;
}

/**
 * All templates, ordered by category then name.
 *
 * @return array
 */
function get_email_templates() {
	$rows = db_all( "SELECT * FROM email_templates ORDER BY FIELD(category, 'user_auth', 'reviews_digest', 'billing_quota', 'admin_system'), id ASC" );

	if ( count( $rows ) < count( get_default_email_templates() ) ) {
		ensure_email_templates();
		$rows = db_all( "SELECT * FROM email_templates ORDER BY FIELD(category, 'user_auth', 'reviews_digest', 'billing_quota', 'admin_system'), id ASC" );
	}

	return $rows;
}

/**
 * Whether a template is switched on.
 *
 * @param string $slug Template slug.
 * @return bool
 */
function is_email_template_enabled( $slug ) {
	$template = get_email_template( $slug );

	return $template && 1 === (int) $template['is_enabled'];
}

/**
 * Template placeholders (key => description), template-specific first, then the global ones.
 *
 * @param array $template Template row.
 * @return array
 */
function get_template_placeholders( array $template ) {
	$own = json_decode( (string) $template['available_placeholders'], true );

	return array_merge( is_array( $own ) ? $own : [], get_global_email_placeholders() );
}

/**
 * Realistic demo values for previews and test sends (invented names only).
 *
 * @param string $slug Template slug.
 * @return array
 */
function get_email_template_demo_data( $slug ) {
	$cell = 'style="border-top:1px solid #E2E8F0;font-size:13px;vertical-align:top;"';
	$data = [
		'user_name'               => 'Alexandru Marin',
		'user_email'              => 'alexandru@exemplu.ro',
		'trial_days'              => (string) get_setting_int( 'trial_days', 30 ),
		'trial_ends_at'           => format_date( now_db( '+30 days' ) ),
		'dashboard_url'           => app_url( 'dashboard/index.php' ),
		'onboarding_url'          => app_url( 'dashboard/onboarding.php' ),
		'reset_url'               => app_url( 'auth/reset-password.php', [ 'token' => 'exemplu' ] ),
		'expires_in'              => '60 de minute',
		'ip_address'              => '192.0.2.10',
		'operator_name'           => 'Ioana Radu',
		'operator_email'          => 'ioana@exemplu.ro',
		'owner_name'              => 'Alexandru Marin',
		'company_name'            => 'Bistro Livada Demo',
		'login_url'               => app_url( 'auth/login.php' ),
		'temp_password'           => 'Exemplu-Parola-1',
		'stars'                   => stars_text( 2 ) . ' (2/5)',
		'customer_name'           => 'Mara Ionescu',
		'customer_contact'        => '0722 123 456',
		'message'                 => "Am așteptat 25 de minute pe terasă până să ne ia cineva comanda.\nMâncarea a fost bună, dar servirea foarte lentă.",
		'ref_tag'                 => 'Ospătar Mihai',
		'created_at'              => format_datetime( now_db( '-2 hours' ) ),
		'feedback_url'            => app_url( 'dashboard/reviews.php' ),
		'date_yesterday'          => format_date( now_db( '-1 day' ) ),
		'total_views'             => '42',
		'google_redirects'        => '18',
		'private_feedbacks_count' => '2',
		'avg_rating'              => '4,4★',
		'feedbacks_table_html'    => build_feedback_digest_table(
			[
				[
					'time'    => '13:42',
					'rating'  => 2,
					'name'    => 'Mara Ionescu',
					'contact' => '0722 123 456',
					'tag'     => 'Ospătar Mihai',
					'message' => 'Am așteptat 25 de minute pe terasă până să ne ia cineva comanda.',
					'locked'  => false,
					'url'     => app_url( 'dashboard/reviews.php' ),
				],
				[
					'time'    => '20:15',
					'rating'  => 3,
					'name'    => '',
					'contact' => '',
					'tag'     => '',
					'message' => 'Muzica a fost prea tare, nu ne puteam auzi.',
					'locked'  => false,
					'url'     => app_url( 'dashboard/reviews.php' ),
				],
			]
		),
		'period_label'            => ro_month_name( (int) date( 'n', strtotime( 'first day of last month' ) ) ) . ' ' . date( 'Y', strtotime( 'first day of last month' ) ),
		'saved_negatives'         => '14',
		'resolved_count'          => '11',
		'conversion'              => '58%',
		'locations_table_html'    => '',
		'top_ref_tags_html'       => '<h2 style="font-size:16px;margin:22px 0 8px;color:#0F172A;">Top angajați / coduri QR</h2>'
			. '<table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="border:1px solid #E2E8F0;border-radius:10px;border-collapse:separate;font-size:13px;">'
			. '<tr style="background:#F8FAFC;color:#64748B;"><td>Cod</td><td align="right">Google</td><td align="right">Feedback</td><td align="right">Notă</td></tr>'
			. '<tr><td ' . $cell . '><strong>Ospătar Andreea</strong></td><td align="right" ' . $cell . '>212</td><td align="right" ' . $cell . '>9</td><td align="right" ' . $cell . '>4,7★</td></tr>'
			. '<tr><td ' . $cell . '><strong>Ospătar Mihai</strong></td><td align="right" ' . $cell . '>19</td><td align="right" ' . $cell . '>15</td><td align="right" ' . $cell . '>3,4★</td></tr>'
			. '</table>',
		'plan_name'               => 'Pro',
		'used_reviews'            => '812',
		'max_reviews'             => '1.000',
		'billing_url'             => app_url( 'dashboard/billing.php' ),
		'expires_at'              => format_date( now_db( '+3 days' ) ),
		'days_left'               => '3',
		'registered_at'           => format_datetime( now_db() ),
		'admin_users_url'         => app_url( 'admin/users.php' ),
		'sender_name'             => 'Victor Nistor',
		'sender_email'            => 'victor@exemplu.ro',
		'sender_phone'            => '0733 456 789',
		'subject_type'            => 'Mesaj contact: Ofertă comercială',
		'details_html'            => '<p style="margin:14px 0 0;"><strong>Mesaj:</strong><br>Bună ziua, am 3 restaurante și aș dori o ofertă pentru planul Enterprise.</p>',
		'admin_url'               => app_url( 'admin/contact-messages.php' ),
		'backup_label'            => 'Complet (bază de date + fișiere)',
		'trigger_label'           => 'Automat (cron)',
		'started_at'              => format_datetime( now_db() ),
		'error_message'           => 'Google Drive: The user\'s Drive storage quota has been exceeded. (HTTP 403)',
		'folder_name'             => 'ahoi-backups',
		'folder_url'              => 'https://drive.google.com/drive/my-drive',
		'backups_url'             => app_url( 'admin/backups.php' ),
		'files_html'              => '<table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="border:1px solid #E2E8F0;border-radius:10px;font-size:13px;border-collapse:separate;">'
			. '<tr><td>Bază de date</td><td style="font-size:12px;">repuvio-ro-db-2026-11-02_030000-a1b2c3.sql.gz</td><td align="right">2,4 MB</td></tr>'
			. '<tr><td style="border-top:1px solid #E2E8F0;">Fișiere</td><td style="border-top:1px solid #E2E8F0;font-size:12px;">repuvio-ro-files-2026-11-02_030004-d4e5f6.zip</td><td align="right" style="border-top:1px solid #E2E8F0;">38,7 MB</td></tr>'
			. '</table>',
		'total_size'              => '41,1 MB',
	];

	if ( 'quota_exceeded_softlock' === $slug ) {
		$data['stars'] = stars_text( 1 ) . ' (1/5)';
	} elseif ( 'instant_positive_review' === $slug ) {
		$data['stars'] = stars_text( 5 ) . ' (5/5)';
		$data['ref_tag'] = 'Ospătar Andreea';
	} elseif ( 'subscription_expired' === $slug || 'trial_expiring_soon' === $slug ) {
		$data['plan_name'] = 'Perioada de probă';
	}

	return $data;
}
