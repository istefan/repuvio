<?php
/**
 * "Ajutor & Ghid" help center (route /help), for every logged-in role: instant search, three
 * visual quick-start guides, categorized FAQ accordions, role-specific help (operator tickets,
 * Super Admin system documentation) and a support contact card.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$user          = require_login();
$role          = $user['role'];
$support_email = sanitize_email_address( get_setting( 'support_email' ) );
$brand         = esc_html( app_name() );

/*
 * FAQ content: category key => [ label, icon, [ [ question, answer HTML ], ... ] ].
 * Answers are static HTML authored here (no user input).
 */
$faq = [
	'company'    => [
		'Configurare Companie & Google Maps',
		'bi-shop',
		[
			[ 'Cum schimb pragul de stele?', '<p>Mergi la <strong>Companii → Editează</strong>, secțiunea „Google Reviews & rutare”, câmpul <strong>Prag minim pentru Google</strong>. Evaluările egale sau peste prag merg pe Google; cele sub prag devin sesizări private. Recomandăm <strong>4 stele și peste</strong> dacă vrei să crești media pe Google.</p><p class="mb-0">Doar proprietarul firmei poate schimba pragul; operatorii nu au acces.</p>' ],
			[ 'Ce se întâmplă dacă nu am pus încă linkul de Google?', '<p class="mb-0">Pagina funcționează în continuare: clienții mulțumiți văd un mesaj de mulțumire în loc de redirecționare, iar sesizările negative ajung la tine normal. Pe cardul companiei apare eticheta „Link Google lipsă”. Adaugă linkul <code>https://g.page/r/...</code> cât mai repede ca să nu pierzi recenzii pozitive.</p>' ],
			[ 'Ce formate de link Google acceptă ' . app_name() . '?', '<ul class="mb-0"><li><strong>Linkul scurt</strong> <code>https://g.page/r/.../review</code> din butonul „Solicită recenzii” (recomandat).</li><li>Linkul direct <code>https://search.google.com/local/writereview?placeid=...</code>.</li><li><strong>Place ID</strong>-ul (<code>ChIJ...</code>) — construim noi linkul.</li></ul>' ],
			[ 'Cum activez / dezactivez White-label?', '<p class="mb-0">În <strong>Companii → Editează</strong>, cardul din dreapta: comutatorul <strong>„White-label: ascunde Protejat de ' . $brand . '”</strong>. Este inclus în toate abonamentele și se aplică imediat pe pagina publică <code>/r/{slug}</code>.</p>' ],
			[ 'Cum schimb logo-ul, coperta sau mesajul de bun venit?', '<p class="mb-0">Tot din <strong>Companii → Editează</strong>. Imaginile (JPG, PNG, WEBP, max. 5 MB) sunt reprocesate automat, iar metadatele sunt eliminate. Fără logo afișăm o pictogramă cu inițialele firmei.</p>' ],
		],
	],
	'print'      => [
		'Print Studio, Coduri QR pe Angajați & Widget',
		'bi-qr-code',
		[
			[ 'Cum urmăresc performanța fiecărui angajat sau echipe (planul PRO)?', '<p>În <strong>Print Studio</strong>, cardul „Cod QR pe angajat / departament”, creează etichete precum „Chelner Mihai”, „Recepție” sau „Masa 4”. Fiecare cod QR conține <code>?ref=eticheta</code>.</p><p class="mb-0">Rezultatele apar în <strong>Panou → Performanță pe angajat / sursă</strong>, în filtrul „Angajat / sursă” din Feedback și în raportul lunar ROI. Funcția este inclusă în Pro, Enterprise și în perioada de probă.</p>' ],
			[ 'Cum printez la calitate maximă, fără margini, din browser?', '<ol class="mb-0"><li>Apasă <strong>Printează / Descarcă PDF</strong> în Print Studio.</li><li>În fereastra de printare alege <strong>Margini: Niciuna</strong> și bifează <strong>Grafică de fundal</strong>.</li><li>Scalare: <strong>100% / Dimensiune reală</strong>.</li><li>Pentru tipografie alege „Salvează ca PDF” — codul QR este vectorial și rămâne clar la orice mărime.</li></ol>' ],
			[ 'Cum instalez insigna pe site?', '<p>Mergi la <strong>Widget & Email</strong>. Pe WordPress descarcă <strong>pluginul ' . $brand . ' Trust Badge</strong> și configurează-l din <em>Setări → ' . $brand . ' Badge</em>. Pe alte platforme copiază codul generat și lipește-l înainte de <code>&lt;/body&gt;</code>.</p><p class="mb-0">Insigna nu folosește cookie-uri, iar recenziile venite de pe site apar cu sursa „Widget website”.</p>' ],
			[ 'Cum adaug semnătura în Outlook sau Gmail?', '<p class="mb-0">În <strong>Widget & Email → Semnătură email</strong> alege una din cele 3 variante, apasă <strong>Copiază Semnătura Formatată (Vizual)</strong> și urmează tutorialul pas cu pas pentru Outlook, Gmail, Apple Mail sau Thunderbird.</p>' ],
		],
	],
	'complaints' => [
		'Gestionarea Reclamațiilor & Echipa de Operatori',
		'bi-headset',
		[
			[ 'Cum adaug un operator?', '<p class="mb-0">Proprietarul firmei merge la <strong>Operatori</strong>, completează numele, emailul, o parolă inițială și bifează companiile alocate. La prima autentificare operatorul este obligat să își schimbe parola.</p>' ],
			[ 'Ce drepturi are un operator?', '<ul class="mb-0"><li>Vede <strong>Panoul</strong> și <strong>Feedback & Recenzii</strong> doar pentru companiile alocate.</li><li>Schimbă statusul sesizărilor și adaugă note interne.</li><li><strong>Nu</strong> poate adăuga sau șterge companii, schimba pragul de stele, vedea abonamentul, exporta CSV sau gestiona alți operatori.</li></ul>' ],
			[ 'Cum schimb statusul unei sesizări din „Nou” în „În lucru” sau „Rezolvat”?', '<ol class="mb-0"><li>Deschide <strong>Feedback & Recenzii</strong> și apasă <strong>Deschide</strong> pe sesizare.</li><li>În cardul <strong>Status tichet</strong> alege „În lucru” sau „Rezolvat” și apasă „Actualizează statusul”.</li><li>Adaugă o <strong>notă internă</strong> (ex: „Am sunat clientul, voucher 20%”). Prima notă mută automat tichetul din „Nou” în „În lucru”, iar fiecare schimbare de status este notată în istoric.</li></ol>' ],
			[ 'Cum contactez rapid clientul nemulțumit?', '<p class="mb-0">Dacă clientul și-a lăsat emailul sau telefonul, în tichet apare un link direct (<code>mailto:</code> / <code>tel:</code>). Răspunde în primele ore: un client ascultat devine adesea un client fidel.</p>' ],
		],
	],
	'billing'    => [
		'Abonamente, Cota de Recenzii & Facturare',
		'bi-credit-card',
		[
			[ 'Ce reprezintă o recenzie contorizată?', '<p class="mb-0">Orice interacțiune completă cu stele pe pagina <code>/r/{slug}</code>: o redirecționare către Google <strong>sau</strong> un feedback privat trimis. Simpla deschidere a paginii (scanarea codului QR fără vot) este doar o <strong>vizualizare</strong> și nu consumă cota.</p>' ],
			[ 'Ce se întâmplă dacă depășesc 500 sau 1.000 de recenzii într-o lună?', '<p>Primești email la 80% și la 100% din cotă. După depășire, clienții mulțumiți sunt redirecționați <strong>în continuare</strong> pe Google, dar feedback-ul negativ nou este păstrat <strong>blocat</strong> până la upgrade sau până la începutul noului ciclu lunar.</p><p class="mb-0">Fă upgrade din <strong>Abonament</strong>: comanda se confirmă într-o fereastră, iar abonamentul se activează la confirmarea plății.</p>' ],
			[ 'Cum funcționează protecția Soft-Lock?', '<p class="mb-0">Clientul final <strong>nu vede niciodată o eroare</strong> din cauza abonamentului tău. Dacă abonamentul a expirat sau cota este atinsă, mesajul lui este salvat, dar ascuns (blocat) în panou, și primești un email. Imediat ce abonamentul este activ și există cotă, sesizările se deblochează automat, de la cea mai veche.</p>' ],
			[ 'Cum plătesc și primesc factura?', '<p class="mb-0">Alege planul (lunar sau anual cu 2 luni gratuite) în <strong>Abonament</strong> și confirmă comanda. Primești factura proformă pe email; plata se face prin transfer bancar, iar abonamentul se activează după confirmare. Istoricul plăților apare în aceeași pagină.</p>' ],
		],
	],
];

$page_title       = 'Ajutor & Ghid';
$active_nav       = 'help';
$page_breadcrumbs = [ [ 'Acasă', get_home_url( $user ) ], [ 'Ajutor & Ghid', '' ] ];
$page_scripts     = [ asset_url( 'js/help.js' ) ];

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<div class="rm-help-root" id="rmHelp">
	<section class="rm-help-hero mb-4">
		<h2 class="h3 text-white mb-1">Cum te putem ajuta?</h2>
		<p class="mb-3">Caută în ghiduri și întrebări frecvente — rezultatele apar pe măsură ce scrii.</p>
		<div class="rm-help-search">
			<i class="bi bi-search"></i>
			<input type="search" class="form-control" id="rmHelpSearch" placeholder="Ex: prag stele, g.page, operator, cotă, printare fără margini..." aria-label="Caută în Ajutor" autocomplete="off">
		</div>
		<div class="small mt-2 d-none" id="rmHelpCount" aria-live="polite"></div>
	</section>

	<?php if ( 'operator' === $role ) : ?>
		<section class="rm-card rm-card-body mb-4 rm-help-highlight" data-help-item>
			<div class="d-flex gap-3 align-items-start">
				<span class="rm-guide-num"><i class="bi bi-headset"></i></span>
				<div>
					<h3 class="h5">Ghidul tău de operator: gestionarea sesizărilor</h3>
					<ol class="rm-steps small mb-2">
						<li>Deschide <strong>Feedback & Recenzii</strong>; filtrează după <strong>Status: Nou</strong> ca să vezi ce așteaptă răspuns.</li>
						<li>Deschide sesizarea, contactează clientul (email / telefon) și mută tichetul în <strong>În lucru</strong>.</li>
						<li>Notează în <strong>Note interne</strong> fiecare pas făcut, ca toată echipa să vadă istoricul.</li>
						<li>Când situația este rezolvată, setează statusul <strong>Rezolvat</strong>.</li>
					</ol>
					<a class="btn btn-dark btn-sm" href="<?php echo esc_url( app_url( 'dashboard/reviews.php', [ 'status' => 'new' ] ) ); ?>"><i class="bi bi-inbox"></i> Vezi sesizările noi</a>
				</div>
			</div>
		</section>
	<?php endif; ?>

	<h2 class="h5 mb-3" data-help-heading>Ghiduri rapide</h2>
	<div class="row g-3 mb-4">
		<div class="col-lg-4" data-help-item>
			<div class="rm-card rm-card-body h-100">
				<span class="rm-guide-num mb-3">1</span>
				<h3 class="h6">Cum funcționează filtrul inteligent de recenzii (Smart Funnel)</h3>
				<p class="small text-muted">Clientul scanează codul QR și alege 1–5 stele pe pagina ta <code>/r/{slug}</code>.</p>
				<div class="rm-shield small">
					<div class="rm-shield-row is-private"><span class="rm-shield-stars">1★ – 2★</span><span class="rm-shield-tag">Privat</span><span>Sesizare internă în „Feedback & Recenzii” + email către tine. Nu ajunge pe Google.</span></div>
					<div class="rm-shield-row is-google"><span class="rm-shield-stars">4★ – 5★</span><span class="rm-shield-tag">Google</span><span>Redirecționare către Google Maps pentru recenzia publică.</span></div>
				</div>
				<p class="small text-muted mt-2 mb-0">Nota 3★ urmează pragul ales de tine (implicit merge pe Google; cu pragul 4★ devine sesizare privată).</p>
			</div>
		</div>
		<div class="col-lg-4" data-help-item>
			<div class="rm-card rm-card-body h-100">
				<span class="rm-guide-num mb-3">2</span>
				<h3 class="h6">Cum obții linkul de recenzie Google (g.page sau Place ID)</h3>
				<ol class="rm-steps small mb-2">
					<li>Caută-ți firma pe Google, logat cu contul care administrează profilul.</li>
					<li>Apasă <strong>„Solicită recenzii”</strong> și copiază linkul <code>https://g.page/r/CAbc.../review</code>.</li>
					<li>Alternativ: găsește <strong>Place ID</strong>-ul (<code>ChIJ...</code>) cu Google Place ID Finder.</li>
				</ol>
				<a class="small" href="https://developers.google.com/maps/documentation/places/web-service/place-id#find-id" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Google Place ID Finder</a>
			</div>
		</div>
		<div class="col-lg-4" data-help-item>
			<div class="rm-card rm-card-body h-100">
				<span class="rm-guide-num mb-3">3</span>
				<h3 class="h6">Cele mai bune practici pentru afișarea codurilor QR în locație</h3>
				<ul class="list-unstyled small mb-0">
					<li class="mb-2"><i class="bi bi-cup-hot"></i> <strong>HoReCa:</strong> table tent A5 pe fiecare masă, sticker pe mapa de nota de plată, afiș A4 la casă. Cel mai bun moment: la plată.</li>
					<li class="mb-2"><i class="bi bi-heart-pulse"></i> <strong>Clinici & saloane:</strong> stand A4/A5 pe recepție, la vedere în timpul plății; cod per medic / stilist cu <code>?ref=</code>.</li>
					<li><i class="bi bi-tools"></i> <strong>Service & instalații:</strong> carte de vizită lăsată de tehnician la final, plus semnătura email și linkul trimis pe WhatsApp după intervenție.</li>
				</ul>
			</div>
		</div>
	</div>

	<?php if ( 'super_admin' === $role ) : ?>
		<section class="rm-card mb-4" data-help-item>
			<div class="rm-card-header">
				<h2 class="rm-card-title"><i class="bi bi-shield-lock"></i> Documentație Administrare Sistem (Super Admin)</h2>
				<button class="btn btn-sm btn-light" type="button" data-bs-toggle="collapse" data-bs-target="#rmAdminDocs" aria-expanded="false" aria-controls="rmAdminDocs">Arată / ascunde</button>
			</div>
			<div class="collapse" id="rmAdminDocs" data-help-collapse>
				<div class="rm-card-body small">
					<div class="row g-4">
						<div class="col-md-6">
							<h3 class="h6">Mod DEV vs. PROD</h3>
							<p class="mb-0"><strong>Setări → Mediu & Anti-abuz.</strong> În <strong>PROD</strong> o evaluare per dispozitiv (cookie) sau per IP + browser este numărată o singură dată per companie în fereastra anti-duplicat (implicit 24 h), plus rate limiting. <strong>DEV</strong> dezactivează aceste limite pentru teste nelimitate; în antet apare badge-ul „DEV”. Revino la PROD înainte de lansare.</p>
						</div>
						<div class="col-md-6">
							<h3 class="h6">Cron Job (<code>cron.php</code>)</h3>
							<p class="mb-1"><strong>O dată pe oră</strong>, din cPanel → Cron Jobs („Once Per Hour”):</p>
							<pre class="rm-code mb-1">php /home/USER/public_html/cron.php</pre>
							<p class="mb-0">sau prin HTTP cu <code>?token=CRON_TOKEN</code>. Expiră abonamente, resetează cotele lunare, trimite avertizările de expirare, deblochează feedback-ul, pune în coadă rapoartele ROI lunare și rezumatul zilnic (o singură dată pe zi, de la ora aleasă) și trimite coada de emailuri în loturi, ca să nu fie blocat contul SMTP.</p>
						</div>
						<div class="col-md-6">
							<h3 class="h6">Raport ROI manual</h3>
							<p class="mb-0"><strong>Rapoarte ROI</strong>: alege un Business Owner sau pe toți, perioada (luna precedentă, luna curentă sau interval personalizat) și apasă „Trimite Email ROI Acum”. Trimiterea manuală nu afectează trimiterea automată din cron.</p>
						</div>
						<div class="col-md-6">
							<h3 class="h6">Email & Șabloane</h3>
							<p class="mb-0">Alegi când primesc proprietarii emailurile despre recenzii (instantaneu, Hibrid Smart sau doar rezumat zilnic), pornești / oprești și editezi fiecare email, vezi previzualizarea cu date demo, trimiți teste, setezi aspectul comun (logo, culoare, subsol) și urmărești jurnalul: ce s-a trimis, ce a eșuat (cu eroarea SMTP) și ce așteaptă în coadă, cu buton de retrimitere.</p>
						</div>
						<div class="col-md-6">
							<h3 class="h6">Backup Google Drive</h3>
							<p class="mb-0">Conectezi o dată contul Google (Client ID + Secret din Google Cloud, aplicație publicată „In production”), iar baza de date (.sql.gz) și fișierele (.zip) se salvează automat în folderul <code>ahoi-backups</code>, zilnic / de 2 ori pe săptămână / săptămânal / lunar, la ora aleasă. Se păstrează ultimele N arhive; la orice eșec primești email. „Descarcă backup SQL direct în browser” funcționează și fără Google Drive.</p>
						</div>
						<div class="col-md-6">
							<h3 class="h6">Upsell printuri fizice</h3>
							<p class="mb-0"><strong>Setări → Printuri & Rapoarte</strong>: comutatorul „Activat / Ascuns”. <strong>Printuri fizice</strong>: produse, prețuri (RON), vizibilitate și statusul comenzilor. Fiecare comandă trimite email către emailul de suport și către Super Admin.</p>
						</div>
					</div>
				</div>
			</div>
		</section>
	<?php endif; ?>

	<h2 class="h5 mb-3" data-help-heading>Întrebări frecvente</h2>
	<ul class="nav nav-pills rm-tabs rm-help-tabs gap-1 mb-3 flex-wrap" role="tablist">
		<?php $first = true; ?>
		<?php foreach ( $faq as $key => $category ) : ?>
			<li class="nav-item" role="presentation">
				<button class="nav-link<?php echo $first ? ' active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#faq-<?php echo esc_attr( $key ); ?>" type="button" role="tab"><i class="bi <?php echo esc_attr( $category[1] ); ?>"></i> <?php echo esc_html( $category[0] ); ?></button>
			</li>
			<?php $first = false; ?>
		<?php endforeach; ?>
	</ul>

	<div class="tab-content rm-help-faq mb-4">
		<?php $first = true; ?>
		<?php foreach ( $faq as $key => $category ) : ?>
			<div class="tab-pane fade<?php echo $first ? ' show active' : ''; ?>" id="faq-<?php echo esc_attr( $key ); ?>" role="tabpanel" data-category="<?php echo esc_attr( $category[0] ); ?>">
				<div class="accordion" id="acc-<?php echo esc_attr( $key ); ?>">
					<?php foreach ( $category[2] as $index => $entry ) : ?>
						<?php $item_id = $key . '-' . $index; ?>
						<div class="accordion-item" data-help-item>
							<h3 class="accordion-header">
								<button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#q-<?php echo esc_attr( $item_id ); ?>" aria-expanded="false" aria-controls="q-<?php echo esc_attr( $item_id ); ?>"><?php echo esc_html( $entry[0] ); ?></button>
							</h3>
							<div id="q-<?php echo esc_attr( $item_id ); ?>" class="accordion-collapse collapse" data-bs-parent="#acc-<?php echo esc_attr( $key ); ?>">
								<div class="accordion-body small"><?php echo $entry[1]; // phpcs:ignore WordPress.Security.EscapeOutput -- static, authored HTML above. ?></div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php $first = false; ?>
		<?php endforeach; ?>
	</div>

	<div class="rm-card rm-card-body text-center d-none mb-4" id="rmHelpEmpty">
		<i class="bi bi-search fs-3 text-muted"></i>
		<p class="mt-2 mb-0">Nu am găsit nimic pentru căutarea ta. Încearcă alt cuvânt sau scrie-ne mai jos.</p>
	</div>

	<section class="rm-card rm-card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
		<div class="d-flex align-items-center gap-3">
			<span class="rm-guide-num"><i class="bi bi-life-preserver"></i></span>
			<div>
				<div class="fw-semibold text-dark">Ai nevoie de ajutor suplimentar?</div>
				<div class="small text-muted">
					<?php if ( '' !== $support_email ) : ?>
						Scrie-ne la <a href="<?php echo esc_url( 'mailto:' . $support_email ); ?>"><?php echo esc_html( $support_email ); ?></a> — răspundem în cel mult o zi lucrătoare.
					<?php else : ?>
						Contactează administratorul platformei.
					<?php endif; ?>
				</div>
			</div>
		</div>
		<div class="d-flex flex-wrap gap-2">
			<?php if ( '' !== $support_email ) : ?>
				<a class="btn btn-light" href="<?php echo esc_url( 'mailto:' . $support_email ); ?>"><i class="bi bi-envelope"></i> Trimite un email</a>
			<?php endif; ?>
			<?php if ( 'user' === $role ) : ?>
				<a class="btn btn-dark" href="<?php echo esc_url( app_url( 'dashboard/onboarding.php' ) ); ?>"><i class="bi bi-magic"></i> Relansează Ghidul de Configurare Pas cu Pas</a>
			<?php endif; ?>
		</div>
	</section>
</div>

<?php
require REPUVIO_ROOT . '/templates/app-footer.php';
