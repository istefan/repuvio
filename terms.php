<?php
/**
 * Public page: Termeni și Condiții (route /termeni-si-conditii).
 * B2B SaaS terms in Romanian; provider details from the legal_* settings, plans, prices, quotas and the
 * trial length from the database, so the document always matches the live offer.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once __DIR__ . '/includes/bootstrap.php';

$legal    = get_legal_settings();
$brand    = esc_html( app_name() );
$provider = esc_html( $legal['legal_company_name'] );
$domain   = esc_html( (string) parse_url( APP_URL, PHP_URL_HOST ) );
$email    = esc_html( $legal['legal_email'] );
$trial    = esc_html( max( 1, get_setting_int( 'trial_days', 30 ) ) );
$plans    = '';

foreach ( get_plans( true ) as $plan ) {
	$starting = 1 === (int) $plan['price_is_starting'];
	$plans   .= '<li><strong>' . esc_html( $plan['name'] ) . '</strong> — ' . ( $starting ? 'de la ' : '' ) . esc_html( format_ron( get_plan_price( $plan, 'monthly' ) ) ) . ' / lună sau '
		. ( $starting ? 'de la ' : '' ) . esc_html( format_ron( get_plan_price( $plan, 'yearly' ) ) ) . ' / an; '
		. esc_html( format_number( $plan['max_reviews_per_month'] ) . ( $starting ? '+' : '' ) ) . ' recenzii colectate / lună; '
		. esc_html( $plan['max_companies'] . ( $starting ? '+' : '' ) ) . ' ' . ( 1 === (int) $plan['max_companies'] ? 'companie / locație' : 'companii / locații' )
		. ( 1 === (int) $plan['allow_ref_tags'] ? '; coduri QR multiple pe angajați / echipe / departamente' : '' )
		. ( 1 === (int) $plan['includes_onboarding'] ? '; onboarding asistat' : '' ) . '.</li>';
}

$doc_key      = 'terms';
$doc_title    = 'Termeni și Condiții';
$doc_intro    = '<p>Acești Termeni și Condiții reglementează accesul și utilizarea platformei <strong>' . $brand . '</strong> (<strong>' . $domain . '</strong>). Prin crearea unui cont sau utilizarea serviciului, Beneficiarul confirmă că a citit, a înțeles și acceptă integral prezentul document, împreună cu <a href="' . esc_url( legal_url( 'privacy' ) ) . '">Politica de Confidențialitate</a> și <a href="' . esc_url( legal_url( 'cookies' ) ) . '">Politica de Cookies</a>.</p>'
	. '<p class="lp-doc-note">Serviciul se adresează profesioniștilor (persoane juridice, PFA, întreprinderi individuale sau familiale) care îl folosesc în scopul activității lor economice.</p>';
$doc_sections = [
	[
		'furnizor',
		'Identificarea Furnizorului și Definiții',
		'<p>Platforma ' . $brand . ' este deținută și operată de <strong>' . $provider . '</strong>, reprezentată de Stefan Iftimie, cu datele de identificare din finalul acestui document (denumit în continuare „Furnizorul”).</p>'
		. '<ul>'
		. '<li><strong>Utilizator / Beneficiar</strong> — proprietarul de afacere (persoană juridică sau profesionist) care își creează cont, înregistrează una sau mai multe companii / locații și administrează serviciul.</li>'
		. '<li><strong>Operator</strong> — membru al echipei Beneficiarului, căruia Beneficiarul îi creează un cont cu drepturi limitate (vizualizare panou și gestionarea sesizărilor pentru companiile alocate).</li>'
		. '<li><strong>Client Final</strong> — persoana care accesează pagina publică de evaluare a Beneficiarului (<code>/r/{slug}</code>), de regulă prin scanarea unui cod QR, și acordă o notă de 1–5 stele.</li>'
		. '<li><strong>Recenzie contorizată</strong> — orice interacțiune finalizată cu stelele pe pagina publică: redirecționarea către Google sau trimiterea unei sesizări private.</li>'
		. '<li><strong>Cont</strong> — spațiul securizat al Beneficiarului în platformă, accesibil cu email și parolă.</li>'
		. '</ul>',
	],
	[
		'obiect',
		'Obiectul Serviciului',
		'<p>Furnizorul pune la dispoziția Beneficiarului, în regim SaaS (software ca serviciu, acces online, fără instalare), o platformă de management al reputației online care include:</p>'
		. '<ul>'
		. '<li>pagini publice de evaluare personalizate cu numele, logo-ul și coperta Beneficiarului;</li>'
		. '<li>filtrarea inteligentă a feedback-ului: evaluările egale sau peste pragul stabilit de Beneficiar sunt îndrumate către pagina oficială de recenzii Google, iar cele sub prag sunt transmise privat Beneficiarului ca sesizări;</li>'
		. '<li>generarea de coduri QR și materiale tipăribile (afișe, flyere, cărți de vizită, stickere), widget pentru website și semnături de email;</li>'
		. '<li>un modul intern de gestionare a sesizărilor (statusuri, note interne, operatori), statistici și rapoarte lunare.</li>'
		. '</ul>'
		. '<p>Serviciul nu publică și nu modifică recenzii în numele Clienților Finali. Decizia de a publica o recenzie pe Google aparține exclusiv Clientului Final.</p>',
	],
	[
		'abonamente',
		'Perioada de Probă și Abonamente',
		'<h3>3.1. Perioada de probă gratuită</h3>'
		. '<p>La înregistrare, Beneficiarul primește <strong>' . $trial . ' de zile gratuite</strong>, cu limitele de probă afișate în cont. Înregistrarea nu necesită card bancar, iar la finalul perioadei de probă serviciul nu se taxează automat.</p>'
		. '<h3>3.2. Planuri și prețuri</h3>'
		. '<ul>' . $plans . '</ul>'
		. '<p>Plata anuală include echivalentul a 2 luni gratuite. Prețurile sunt exprimate în lei (RON); regimul TVA se aplică potrivit legii și se menționează pe factură. Planul Enterprise se poate negocia individual.</p>'
		. '<h3>3.3. Comandă, facturare și plată</h3>'
		. '<p>Beneficiarul solicită abonamentul din pagina „Abonament”. Furnizorul emite factura (proformă) pe datele de facturare ale companiei, inclusiv codul fiscal (CUI / CIF), obligatoriu pentru facturare. Abonamentul se activează după confirmarea plății, pentru perioada plătită (lunar sau anual).</p>'
		. '<h3>3.4. Calculul recenziilor</h3>'
		. '<p>Fiecare interacțiune finalizată cu stelele pe pagina publică (redirecționare către Google sau sesizare privată trimisă) consumă o recenzie din cota lunară. Simpla deschidere a paginii sau scanarea codului fără vot reprezintă o vizualizare și nu consumă cota. Cota se resetează la fiecare ciclu lunar.</p>'
		. '<h3>3.5. Mecanismul „Soft-Lock”</h3>'
		. '<p>La depășirea cotei lunare sau la expirarea abonamentului, pagina publică <strong>rămâne activă</strong> pentru a nu afecta imaginea Beneficiarului: redirecționările către Google continuă să funcționeze, însă sesizările private noi sunt păstrate în siguranță, dar <strong>blocate / mascate în panou</strong> până la reînnoire, upgrade sau începutul ciclului următor. După reactivare, sesizările blocate se deblochează automat, în ordinea primirii, în limita cotei disponibile.</p>'
		. '<h3>3.6. Încetare</h3>'
		. '<p>Beneficiarul poate înceta oricând utilizarea serviciului prin nereînnoirea abonamentului. Sumele aferente perioadelor deja începute nu se restituie, cu excepția cazurilor prevăzute de lege. Furnizorul poate suspenda sau închide contul în caz de încălcare gravă a prezenților Termeni, cu notificare prealabilă pe email, acolo unde este posibil.</p>',
	],
	[
		'obligatii',
		'Obligațiile Beneficiarului și Utilizarea Acceptabilă',
		'<p>Beneficiarul se obligă:</p>'
		. '<ul>'
		. '<li>să furnizeze date reale și actualizate, inclusiv datele de facturare;</li>'
		. '<li>să păstreze confidențialitatea datelor de autentificare și să răspundă pentru toate acțiunile efectuate din contul său și din conturile de <strong>Operator</strong> create în subordinea sa (inclusiv alocarea, suspendarea și ștergerea acestora);</li>'
		. '<li>să utilizeze sesizările Clienților Finali exclusiv pentru soluționarea amiabilă a acestora, în conformitate cu legislația privind protecția datelor;</li>'
		. '<li>să respecte politicile Google Business Profile și politicile privind conținutul recenziilor Google.</li>'
		. '</ul>'
		. '<p>Este <strong>interzisă</strong> utilizarea platformei pentru:</p>'
		. '<ul>'
		. '<li>generarea de recenzii fictive, cumpărate, autogenerate sau scrise de angajați ori de persoane apropiate în locul clienților reali;</li>'
		. '<li>oferirea de recompense condiționate de o anumită notă sau de publicarea unei recenzii pozitive;</li>'
		. '<li>trimiterea de mesaje nesolicitate (spam), hărțuirea Clienților Finali sau utilizarea datelor acestora în scop de marketing fără temei legal;</li>'
		. '<li>încercări de acces neautorizat, ocolirea limitelor tehnice, supraîncărcarea sau perturbarea serviciului.</li>'
		. '</ul>',
	],
	[
		'google',
		'Independența față de Google LLC și Limitarea Răspunderii',
		'<p>' . $brand . ' este o platformă independentă. <strong>Nu este afiliată, sponsorizată sau aprobată de Google LLC.</strong> „Google”, „Google Maps” și „Google Business Profile” sunt mărci ale Google LLC, utilizate exclusiv pentru a descrie compatibilitatea serviciului.</p>'
		. '<ul>'
		. '<li>Furnizorul nu garantează poziționarea pe un anumit loc în Google Maps sau în rezultatele căutării, nici un număr minim de recenzii.</li>'
		. '<li>Furnizorul nu este responsabil pentru ștergerea, filtrarea sau moderarea recenziilor de către algoritmii ori echipele Google și nici pentru modificările aduse de Google serviciilor sale.</li>'
		. '<li>Serviciul este furnizat „ca atare”; Furnizorul depune eforturi rezonabile pentru disponibilitate continuă, dar nu răspunde pentru întreruperi cauzate de mentenanță, furnizori terți sau evenimente în afara controlului său.</li>'
		. '<li>În măsura permisă de lege, răspunderea totală a Furnizorului este limitată la valoarea abonamentului plătit de Beneficiar în ultimele 3 luni anterioare evenimentului și exclude prejudiciile indirecte (profit nerealizat, pierderea clientelei sau a reputației).</li>'
		. '</ul>',
	],
	[
		'final',
		'Proprietate Intelectuală, Forță Majoră, Legea Aplicabilă și Litigii',
		'<h3>6.1. Proprietate intelectuală</h3>'
		. '<p>Platforma, codul sursă, designul, textele și mărcile ' . $brand . ' aparțin Furnizorului. Beneficiarul primește un drept de utilizare neexclusiv, netransferabil, pe durata abonamentului. Logo-urile, imaginile și textele încărcate de Beneficiar rămân proprietatea acestuia; Beneficiarul garantează că are dreptul să le folosească.</p>'
		. '<h3>6.2. Forță majoră</h3>'
		. '<p>Niciuna dintre părți nu răspunde pentru neexecutarea obligațiilor cauzată de un eveniment de forță majoră, în condițiile Codului civil român.</p>'
		. '<h3>6.3. Modificări</h3>'
		. '<p>Furnizorul poate actualiza acești Termeni; versiunea în vigoare este publicată pe această pagină, cu data ultimei actualizări. Modificările esențiale sunt comunicate pe email cu cel puțin 15 zile înainte de aplicare.</p>'
		. '<h3>6.4. Legea aplicabilă și litigii</h3>'
		. '<p>Prezentul contract este guvernat de legea română. Părțile vor încerca soluționarea amiabilă a oricărei neînțelegeri; în caz contrar, litigiul va fi soluționat de instanțele competente de la sediul Furnizorului. Pentru întrebări ne poți scrie la <a href="mailto:' . esc_attr( $legal['legal_email'] ) . '">' . $email . '</a>.</p>',
	],
];

require REPUVIO_ROOT . '/templates/legal-document.php';
