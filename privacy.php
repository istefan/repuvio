<?php
/**
 * Public page: Politica de Confidențialitate & Prelucrarea Datelor GDPR (route /confidentialitate).
 * Describes the dual role of the provider: data controller (account holders) and data processor
 * (end-customer feedback, incorporated DPA). Provider details from the legal_* settings.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once __DIR__ . '/includes/bootstrap.php';

$legal    = get_legal_settings();
$brand    = esc_html( app_name() );
$provider = esc_html( $legal['legal_company_name'] );
$mail     = '<a href="mailto:' . esc_attr( $legal['legal_email'] ) . '">' . esc_html( $legal['legal_email'] ) . '</a>';

$doc_key      = 'privacy';
$doc_title    = 'Politica de Confidențialitate';
$doc_intro    = '<p>Această politică explică modul în care <strong>' . $provider . '</strong> prelucrează datele cu caracter personal prin platforma <strong>' . $brand . '</strong>, în conformitate cu Regulamentul (UE) 2016/679 („GDPR”) și cu legislația română aplicabilă.</p>'
	. '<p class="lp-doc-note">' . $brand . ' are un <strong>rol dublu</strong>: este <strong>operator de date</strong> pentru conturile proprietarilor de afaceri și ale operatorilor acestora, și <strong>persoană împuternicită</strong> pentru datele clienților finali care lasă o sesizare privată pe paginile de evaluare.</p>';
$doc_sections = [
	[
		'operator',
		'Ahoi Digital SRL ca Operator de Date (Data Controller)',
		'<p>Pentru datele Proprietarilor de afaceri (Beneficiari) și ale Operatorilor creați de aceștia, ' . $provider . ' stabilește scopurile și mijloacele prelucrării.</p>'
		. '<h3>Ce date prelucrăm</h3>'
		. '<ul>'
		. '<li>date de cont: nume, adresă de email, parolă (stocată exclusiv sub formă de hash), rol;</li>'
		. '<li>date ale companiilor: denumire, cod fiscal (CUI / CIF), logo, copertă, linkul Google, emailuri pentru alerte;</li>'
		. '<li>date de facturare și plăți: abonament, sume, perioade, referințe de plată;</li>'
		. '<li>date tehnice și de securitate: adresa IP și data ultimei autentificări, jurnal de securitate (autentificări, modificări administrative), încercări eșuate de autentificare;</li>'
		. '<li>mesaje trimise prin formularul de contact: nume, companie, email, telefon, mesaj.</li>'
		. '</ul>'
		. '<h3>Scopuri și temei legal</h3>'
		. '<ul>'
		. '<li><strong>furnizarea serviciului SaaS</strong> și administrarea contului — executarea contractului (art. 6 alin. (1) lit. b);</li>'
		. '<li><strong>facturare</strong> și evidență contabilă — obligație legală (art. 6 alin. (1) lit. c);</li>'
		. '<li><strong>notificări de sistem</strong> (sesizări noi, cotă, expirare abonament) și <strong>rapoarte lunare ROI</strong> — executarea contractului; de la raportul lunar te poți dezabona oricând din Profil;</li>'
		. '<li><strong>securitate</strong> — prevenirea atacurilor de tip brute-force și a abuzurilor, jurnal de audit — interes legitim (art. 6 alin. (1) lit. f);</li>'
		. '<li><strong>răspuns la solicitări</strong> trimise prin formularul de contact — demersuri precontractuale / interes legitim.</li>'
		. '</ul>'
		. '<h3>Durata stocării</h3>'
		. '<p>Datele contului se păstrează pe durata relației contractuale. După închiderea contului, datele se șterg sau se anonimizează, cu excepția documentelor financiar-contabile, păstrate pe durata impusă de legislația contabilă. Jurnalele tehnice de limitare a încercărilor se curăță automat periodic.</p>',
	],
	[
		'imputernicit',
		'Ahoi Digital SRL ca Persoană Împuternicită (Data Processor) – Acord DPA',
		'<p>Pentru datele <strong>Clienților Finali</strong> care lasă o evaluare sau o sesizare privată pe pagina publică <code>/r/{slug}</code> a unei afaceri, <strong>Proprietarul Afacerii evaluate este Operatorul de Date</strong>, iar ' . $provider . ' acționează ca <strong>persoană împuternicită</strong>, prelucrând datele exclusiv în numele și conform instrucțiunilor acestuia.</p>'
		. '<h3>Date prelucrate</h3>'
		. '<ul>'
		. '<li>nota acordată (1–5 stele) și data;</li>'
		. '<li>pentru sesizările private: numele, telefonul sau emailul (opționale) și mesajul sesizării;</li>'
		. '<li>sursa scanării și eticheta codului QR (<code>?ref=</code>, de ex. angajatul sau masa);</li>'
		. '<li>date tehnice anti-abuz: o amprentă (hash criptografic) a adresei IP, un identificator aleator de dispozitiv (cookie) și tipul browserului, folosite în modul de producție pentru a preveni evaluările repetate abuzive.</li>'
		. '</ul>'
		. '<h3>Clauze DPA încorporate (art. 28 GDPR)</h3>'
		. '<p>Prin acceptarea Termenilor și Condițiilor, Beneficiarul (operator) și ' . $provider . ' (persoană împuternicită) convin că:</p>'
		. '<ul>'
		. '<li>datele sunt prelucrate numai pentru afișarea, gestionarea și notificarea sesizărilor către Beneficiar și pentru statistici agregate;</li>'
		. '<li>persoanele autorizate să acceseze datele sunt supuse obligației de confidențialitate;</li>'
		. '<li>se aplică măsurile de securitate descrise la secțiunea 3;</li>'
		. '<li>Furnizorul poate folosi sub-împuterniciți (găzduire, livrare email) doar cu garanții adecvate și îl va informa pe Beneficiar despre modificări;</li>'
		. '<li>Furnizorul asistă Beneficiarul în soluționarea cererilor persoanelor vizate și notifică fără întârzieri nejustificate orice încălcare a securității datelor;</li>'
		. '<li>la încetarea contractului, datele se șterg odată cu contul Beneficiarului (ștergerea companiei sau a contului elimină definitiv și sesizările aferente).</li>'
		. '</ul>'
		. '<p><strong>Obligația Beneficiarului:</strong> Proprietarul Afacerii folosește datele Clienților Finali <strong>exclusiv pentru soluționarea amiabilă a sesizării</strong> și nu le utilizează în scop de marketing fără un temei legal separat. Clientul Final este informat pe pagina de evaluare și își exprimă acordul înainte de trimiterea sesizării.</p>',
	],
	[
		'securitate',
		'Securitatea Datelor (Măsuri Tehnice și Organizatorice)',
		'<ul>'
		. '<li>parole stocate exclusiv sub formă de hash, cu algoritmi moderni (Argon2id, respectiv Bcrypt);</li>'
		. '<li>conexiuni criptate HTTPS / TLS și cookie-uri de sesiune securizate (<code>HttpOnly</code>, <code>SameSite=Lax</code>);</li>'
		. '<li>protecție CSRF pentru fiecare formular, limitarea încercărilor de autentificare și blocare temporară;</li>'
		. '<li>izolare logică între conturi: fiecare interogare este filtrată pe contul și companiile la care utilizatorul are acces; operatorii văd doar companiile alocate;</li>'
		. '<li>secrete de sistem (parole SMTP, chei de integrare) criptate cu AES-256;</li>'
		. '<li>imagini încărcate reprocesate, cu eliminarea metadatelor (EXIF);</li>'
		. '<li>jurnal de audit pentru acțiunile administrative și găzduire pe servere situate în Uniunea Europeană.</li>'
		. '</ul>',
	],
	[
		'destinatari',
		'Destinatari și Transferuri',
		'<p>Datele nu sunt vândute și nu sunt folosite pentru publicitate. Pot avea acces, strict în măsura necesară: furnizorul de găzduire, furnizorul serviciului de email (pentru notificări), contabilul / auditorul (pentru documente de facturare) și autoritățile, la cerere legală. Paginile platformei încarcă fonturi și biblioteci de interfață din rețele de distribuție a conținutului (Google Fonts, jsDelivr), care pot primi adresa IP a vizitatorului în mod tehnic, fără cookie-uri de urmărire.</p>',
	],
	[
		'drepturi',
		'Drepturile Persoanelor Vizate',
		'<p>Conform GDPR, ai următoarele drepturi:</p>'
		. '<ul>'
		. '<li><strong>dreptul de acces</strong> la datele prelucrate;</li>'
		. '<li><strong>dreptul la rectificare</strong> — datele de cont se pot modifica direct din Profil;</li>'
		. '<li><strong>dreptul la ștergere</strong> („dreptul de a fi uitat”);</li>'
		. '<li><strong>dreptul la restricționarea</strong> prelucrării și <strong>dreptul de opoziție</strong>;</li>'
		. '<li><strong>dreptul la portabilitate</strong> — sesizările și recenziile se pot exporta oricând în format CSV direct din cont;</li>'
		. '<li><strong>dreptul de a depune plângere</strong> la Autoritatea Națională de Supraveghere a Prelucrării Datelor cu Caracter Personal (ANSPDCP), <a href="https://www.dataprotection.ro/" target="_blank" rel="noopener">dataprotection.ro</a>.</li>'
		. '</ul>'
		. '<p>Pentru exercitarea drepturilor ne poți scrie la ' . $mail . '. Răspundem în cel mult o lună de la primirea cererii. Dacă ai lăsat o sesizare unei afaceri, te poți adresa și direct acelei afaceri (operatorul datelor tale); te vom sprijini în transmiterea cererii.</p>',
	],
	[
		'contact-dpo',
		'Contact pentru Protecția Datelor',
		'<p>Pentru orice întrebare legată de această politică sau de datele tale personale, contactează-ne la ' . $mail . ' sau prin <a href="' . esc_url( legal_url( 'contact' ) ) . '">pagina de contact</a>. Informații despre cookie-uri găsești în <a href="' . esc_url( legal_url( 'cookies' ) ) . '">Politica de Cookies</a>.</p>',
	],
];

require REPUVIO_ROOT . '/templates/legal-document.php';
