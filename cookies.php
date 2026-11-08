<?php
/**
 * Public page: Politica de Cookies (route /politica-cookies).
 * Lists exactly the cookies the platform sets (names read from the code constants) and how to
 * delete or block cookies in the main browsers.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once __DIR__ . '/includes/bootstrap.php';

$legal = get_legal_settings();
$brand = esc_html( app_name() );
$rows  = [
	[ session_name() ?: 'repuvio_sid', 'Cookie de sesiune securizat (<code>HttpOnly</code>, <code>SameSite=Lax</code>, <code>Secure</code> pe HTTPS). Menține autentificarea în panou și protecția CSRF a formularelor.', 'Strict necesar', 'Până la închiderea browserului (sesiunea expiră după inactivitate)' ],
	[ FUNNEL_DEVICE_COOKIE, 'Identificator aleator de dispozitiv, setat pe pagina publică de evaluare (<code>/r/{slug}</code>). În modul de producție (PROD) previne trimiterea repetată sau abuzivă a evaluărilor de pe același dispozitiv. Nu conține date personale.', 'Strict necesar (securitate)', '12 luni' ],
	[ COOKIE_CONSENT_NAME, 'Reține că ai închis bannerul de informare despre cookies, ca să nu îl mai afișăm.', 'Funcțional', '12 luni' ],
];
$table = '';

foreach ( $rows as $row ) {
	$table .= '<tr><td><code>' . esc_html( $row[0] ) . '</code></td><td>' . $row[1] . '</td><td>' . esc_html( $row[2] ) . '</td><td>' . esc_html( $row[3] ) . '</td></tr>';
}

$doc_key      = 'cookies';
$doc_title    = 'Politica de Cookies';
$doc_intro    = '<p>Cookie-urile sunt fișiere text de mici dimensiuni salvate de browser. <strong>' . $brand . ' folosește exclusiv cookie-uri strict necesare și funcționale</strong> — fără cookie-uri de publicitate, fără trackere terțe și fără profilare.</p>';
$doc_sections = [
	[
		'lista',
		'Cookie-urile pe care le folosim',
		'<div class="lp-table-wrap"><table class="lp-table"><thead><tr><th>Nume</th><th>Scop</th><th>Tip</th><th>Durată</th></tr></thead><tbody>' . $table . '</tbody></table></div>'
		. '<p>Cookie-urile strict necesare nu necesită consimțământ (art. 4 alin. (5) din Legea nr. 506/2004), însă te informăm transparent despre ele prin bannerul afișat pe paginile publice.</p>',
	],
	[
		'fara-tracking',
		'Ce NU folosim',
		'<ul>'
		. '<li>cookie-uri de publicitate sau de retargeting;</li>'
		. '<li>cookie-uri de analiză de la terți (de exemplu Google Analytics sau Facebook Pixel);</li>'
		. '<li>cookie-uri pe pagina de evaluare care să urmărească clientul pe alte site-uri.</li>'
		. '</ul>'
		. '<p>Insigna de încredere instalată pe site-urile clienților noștri nu setează cookie-uri; reține doar în memoria temporară a browserului (sessionStorage) dacă vizitatorul a închis-o. Fonturile și pictogramele se încarcă din rețele de distribuție (Google Fonts, jsDelivr), care nu setează cookie-uri prin aceste fișiere.</p>',
	],
	[
		'gestionare',
		'Cum ștergi sau blochezi cookie-urile',
		'<p>Poți șterge oricând cookie-urile existente și poți bloca cookie-urile noi din setările browserului. Atenție: dacă blochezi cookie-urile strict necesare, autentificarea în panou nu va funcționa.</p>'
		. '<ul>'
		. '<li><strong>Google Chrome:</strong> Setări → Confidențialitate și securitate → Cookie-uri terță parte / Șterge datele de navigare.</li>'
		. '<li><strong>Safari:</strong> Setări (Preferințe) → Confidențialitate → Gestionează datele site-urilor web.</li>'
		. '<li><strong>Mozilla Firefox:</strong> Setări → Confidențialitate și securitate → Cookie-uri și date ale site-urilor → Gestionează datele.</li>'
		. '<li><strong>Microsoft Edge:</strong> Setări → Cookie-uri și permisiuni site → Gestionați și ștergeți cookie-urile și datele site-ului.</li>'
		. '</ul>'
		. '<p>Mai multe informații despre cookie-uri găsești pe <a href="https://www.allaboutcookies.org/" target="_blank" rel="noopener">allaboutcookies.org</a>.</p>',
	],
	[
		'contact-cookies',
		'Contact',
		'<p>Pentru întrebări despre această politică scrie-ne la <a href="mailto:' . esc_attr( $legal['legal_email'] ) . '">' . esc_html( $legal['legal_email'] ) . '</a>. Detalii despre prelucrarea datelor personale găsești în <a href="' . esc_url( legal_url( 'privacy' ) ) . '">Politica de Confidențialitate</a>.</p>',
	],
];

require REPUVIO_ROOT . '/templates/legal-document.php';
