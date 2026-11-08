<?php
/**
 * Entry point. Logged-in users are sent to their panel; guests get the public sales landing page:
 * sticky navbar, hero with interactive Smart Funnel mock-up and phone CTA, why reviews matter today
 * (customer decision journey), problem vs. solution with the 6 core advantages, 3-step "how it works",
 * benefits for the end customers, ROI estimator, pricing from the `plans` table (monthly / yearly
 * toggle), FAQ and final CTA (with phone). Head, navbar, footer (legal links) and cookie banner come from
 * templates/public-*.php, shared with the legal pages.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once __DIR__ . '/includes/bootstrap.php';

$user = current_user();

if ( $user ) {
	redirect( get_home_url( $user ) );
}

$brand         = app_name();
$plans         = get_plans( true );
$register_url  = app_url( 'auth/register.php' );
$login_url     = app_url( 'auth/login.php' );
$support_email = sanitize_email_address( get_setting( 'support_email' ) );
$trial_days    = max( 1, get_setting_int( 'trial_days', 30 ) );
$phone         = get_legal_settings()['legal_phone'];
$phone_url     = phone_tel_url( $phone );

/**
 * Feature bullets of a plan for the pricing table (built from the plan's limits and flags).
 *
 * @param array $plan Plan row.
 * @return array
 */
function landing_plan_features( array $plan ) {
	$quota     = (int) $plan['max_reviews_per_month'];
	$starting  = 1 === (int) $plan['price_is_starting'];
	$companies = (int) $plan['max_companies'];
	$features  = [
		'<strong>' . esc_html( format_number( $quota ) . ( $starting ? '+' : '' ) ) . ' recenzii colectate / lună</strong> (peste ' . esc_html( (int) floor( $quota / 30 ) ) . '/zi)',
		'<strong>' . esc_html( 1 === $companies ? '1 locație / firmă' : ( 'Până la ' . $companies . ( $starting ? '+' : '' ) . ' locații / firme' ) ) . '</strong> în același cont',
		esc_html( 1 === (int) $plan['max_alert_emails'] ? '1 adresă de email pentru alerte instantanee' : $plan['max_alert_emails'] . ' adrese de email pentru alerte instantanee' ),
		esc_html( 0 === (int) $plan['history_months'] ? 'Istoric feedback nelimitat' : 'Istoric feedback pe ultimele ' . $plan['history_months'] . ' luni' ),
	];

	if ( 1 === (int) $plan['allow_ref_tags'] ) {
		$features[] = '<strong>Coduri QR multiple per locație</strong> — urmărire pe angajat, departament sau masă';
	}

	if ( 1 === (int) $plan['includes_onboarding'] ) {
		$features[] = '<strong>Onboarding asistat 1-la-1</strong> — configurare completă realizată de echipa noastră';
		$features[] = 'Suport prioritar dedicat';
	}

	return $features;
}

$advantages = [
	[ 'bi-geo-alt', 'Ajungi în Top 3 pe Google Maps (Local Pack)', 'Flux constant de recenzii autentice de 5 stele de la clienții care altfel ar fi plecat fără să lase recenzie.' ],
	[ 'bi-shield-check', 'Scut împotriva emoției de moment', 'Clientul nemulțumit primește pe loc o cale directă către conducere și nu mai simte nevoia să se răzbune public pe Google.' ],
	[ 'bi-person-badge', 'Descoperi cine ridică standardele', 'Cu codurile QR dedicate pe angajat, echipă sau masă (?ref=) știi exact cine oferă servicii impecabile și unde apar probleme.' ],
	[ 'bi-envelope-check', 'Gata cu adresele de Gmail uitate', 'Chiar dacă profilul Google Business este pe un Gmail vechi, alertele ajung instantaneu pe emailul tău oficial de relații cu clienții.' ],
	[ 'bi-kanban', 'CRM intern pentru reclamații', 'Urmărești fiecare sesizare (Nou, În lucru, Rezolvat), adaugi note interne și delegi rezolvarea către operatorii tăi.' ],
	[ 'bi-printer', 'Materiale gata de print & insignă web', 'Afișe A4, table tent A5, cărți de vizită, stickere de masă, widget pentru site și semnătură pentru Outlook / Gmail — în 10 secunde.' ],
];

$faq = [
	[ 'Ai nevoie de cunoștințe tehnice pentru instalare?', 'Nu. Te înregistrezi, scrii numele firmei, lipești linkul tău Google (îți arătăm de unde îl iei în 10 secunde) și descarci afișul cu codul QR. Configurarea durează sub 2 minute, iar pentru site există un plugin WordPress gata configurat.' ],
	[ 'Ce se întâmplă după cele ' . $trial_days . ' de zile gratuite?', 'Alegi abonamentul potrivit (lunar sau anual, cu 2 luni gratuite) și primești factura pe email. Nu cerem card bancar la înregistrare și nu te taxăm automat. Dacă nu continui, linkurile și codurile QR rămân funcționale, iar clienții mulțumiți ajung în continuare pe Google.' ],
	[ 'Ce se întâmplă dacă depășesc limita de recenzii într-o lună? Mi se blochează pagina?', 'Nu. Pagina nu se blochează niciodată pentru clienții tăi. Primești email la 80% și la 100% din cotă. După depășire, clienții mulțumiți sunt trimiși în continuare pe Google, iar sesizările negative noi sunt păstrate în siguranță („Soft-Lock”) și se deblochează automat la upgrade sau la începutul lunii următoare.' ],
	[ 'Cum mă ajută codurile QR pe angajat sau echipă din planul PRO?', 'Generezi câte un cod QR pentru fiecare chelner, tehnician, medic, masă sau departament. Fiecare evaluare este atribuită automat, iar în panou și în raportul lunar vezi cine aduce cele mai multe recenzii de 5★ și unde apar nemulțumirile.' ],
	[ 'Pot gestiona mai multe puncte de lucru sau firme din același cont?', 'Da. Planul Pro include până la 3 locații, iar Enterprise 5+. Fiecare locație are pagina, codurile QR și statisticile ei, iar tu le vezi pe toate dintr-un singur panou.' ],
	[ 'Pot ascunde numele ' . $brand . ' de pe pagina pe care o văd clienții mei?', 'Da. Opțiunea White-Label este inclusă în toate abonamentele: cu un singur clic, pagina de evaluare arată doar logo-ul, coperta și numele firmei tale.' ],
];
$public_title       = $brand . ' — Mai multe recenzii de 5★ pe Google, zero reclamații publice';
$public_description = $brand . ' trimite clienții mulțumiți direct pe Google Maps și interceptează privat nemulțumirile de 1–2 stele. Coduri QR inteligente, CRM de reclamații, ' . $trial_days . ' de zile gratuit.';
$public_og          = true;
$is_landing         = true;

require REPUVIO_ROOT . '/templates/public-head.php';
require REPUVIO_ROOT . '/templates/public-nav.php';
?>

<main>
	<section class="lp-hero">
		<div class="lp-container lp-hero-grid">
			<div>
				<span class="lp-badge">★ Scutul inteligent de reputație pentru afacerile locale din România</span>
				<h1>Transformă clienții mulțumiți în recenzii de 5★ pe Google. <span>Oprește nemulțumirile înainte să devină publice.</span></h1>
				<p class="lp-lead"><?php echo esc_html( $brand ); ?> îți oferă coduri QR inteligente și linkuri personalizate care direcționează clienții fericiți direct pe Google Maps, în timp ce sesizările de 1–2 stele ajung privat pe emailul tău pentru a le rezolva intern.</p>
				<div class="lp-cta-row">
					<a class="lp-btn lp-btn-dark lp-btn-lg" href="<?php echo esc_url( $register_url ); ?>">Începe luna gratuită (<?php echo esc_html( $trial_days ); ?> zile) <i class="bi bi-arrow-right"></i></a>
					<a class="lp-btn lp-btn-light lp-btn-lg" href="#cum-functioneaza">Vezi cum funcționează</a>
				</div>
				<p class="lp-micro"><i class="bi bi-check2"></i> Fără card bancar la înregistrare <span>·</span> <i class="bi bi-check2"></i> Configurare în 90 de secunde</p>
				<?php if ( '' !== $phone_url ) : ?>
					<a class="lp-phone-cta" href="<?php echo esc_url( $phone_url ); ?>">
						<span class="lp-phone-cta-icon"><i class="bi bi-telephone-fill"></i></span>
						<span><small>Preferi să vorbim? Te ajutăm cu configurarea, gratuit:</small><strong><?php echo esc_html( $phone ); ?></strong></span>
					</a>
				<?php endif; ?>
			</div>

			<div class="lp-demo" aria-label="Demonstrație filtru inteligent">
				<div class="lp-phone">
					<div class="lp-phone-cover"></div>
					<div class="lp-phone-body">
						<div class="lp-phone-logo">BT</div>
						<div class="lp-phone-name">Bistro Teiul Verde</div>
						<div class="lp-phone-q">Cum a fost experiența ta?</div>
						<div class="lp-stars" role="radiogroup" aria-label="Alege stele pentru demonstrație">
							<?php for ( $star = 1; 5 >= $star; $star++ ) : ?>
								<button type="button" class="lp-star is-on" data-lp-star="<?php echo esc_attr( $star ); ?>" aria-label="<?php echo esc_attr( $star . ' stele' ); ?>">★</button>
							<?php endfor; ?>
						</div>
						<div class="lp-phone-hint">Atinge o stea pentru a vedea ruta</div>
					</div>
				</div>
				<div class="lp-branches">
					<div class="lp-branch is-google is-active" data-lp-branch="google">
						<span class="lp-branch-icon"><i class="bi bi-google"></i></span>
						<div><strong>4★ – 5★ → Publicat pe Google Maps</strong><span>Crește vizibilitatea în căutările locale</span></div>
					</div>
					<div class="lp-branch is-private" data-lp-branch="private">
						<span class="lp-branch-icon"><i class="bi bi-shield-lock"></i></span>
						<div><strong>1★ – 2★ → Tichet privat către management</strong><span>Salvează media firmei și rezolvă problema clientului</span></div>
					</div>
				</div>
			</div>
		</div>
	</section>

	<section class="lp-section lp-alt" id="importanta">
		<div class="lp-container">
			<div class="lp-head">
				<span class="lp-eyebrow">De ce contează recenziile</span>
				<h2>Astăzi, clienții te aleg după recenzii — înainte să te cunoască</h2>
				<p>Restaurantul de diseară, medicul, service-ul auto, frizerul sau instalatorul: pentru aproape orice produs sau serviciu, oamenii verifică întâi stelele de pe Google și ce au scris alții. Recenziile sunt noul „din gură în gură” — doar că acum îl aud mii de oameni.</p>
			</div>

			<ol class="lp-journey" aria-label="Cum decide un client azi">
				<li><span class="lp-journey-icon"><i class="bi bi-search"></i></span><strong>Caută pe Google</strong><span>„restaurant lângă mine”, „dentist Ploiești”, „service auto deschis acum”</span></li>
				<li><span class="lp-journey-icon"><i class="bi bi-star-half"></i></span><strong>Compară stelele</strong><span>Ratingul și numărul de recenzii decid pe cine vede primul și pe cine sare</span></li>
				<li><span class="lp-journey-icon"><i class="bi bi-chat-left-quote"></i></span><strong>Citește recenziile recente</strong><span>Mai ales pe cele negative — și cum a răspuns afacerea</span></li>
				<li><span class="lp-journey-icon is-final"><i class="bi bi-hand-thumbs-up"></i></span><strong>Alege — sau pleacă la concurent</strong><span>Fără să te sune, fără să-ți dea o a doua șansă</span></li>
			</ol>

			<div class="lp-grid-3">
				<article class="lp-card">
					<span class="lp-icon"><i class="bi bi-geo-alt"></i></span>
					<h3>Recenziile te urcă (sau te coboară) în Google Maps</h3>
					<p>Google precizează în documentația Google Business Profile că numărul și scorul recenziilor contribuie la vizibilitatea în rezultatele locale. Mai multe recenzii bune = mai mulți clienți care te găsesc.</p>
				</article>
				<article class="lp-card">
					<span class="lp-icon"><i class="bi bi-funnel"></i></span>
					<h3>Ratingul filtrează înainte de primul click</h3>
					<p>Pe telefon, clienții văd întâi stelele de lângă nume. O diferență de câteva zecimi poate decide dacă afacerea ta este apăsată sau derulată mai departe.</p>
				</article>
				<article class="lp-card">
					<span class="lp-icon"><i class="bi bi-clock-history"></i></span>
					<h3>Contează ce s-a întâmplat recent</h3>
					<p>O recenzie de acum doi ani nu mai convinge. Ai nevoie de un flux constant de păreri noi de la clienții mulțumiți — exact cei care, de obicei, pleacă fără să scrie nimic.</p>
				</article>
			</div>
		</div>
	</section>

	<section class="lp-section" id="avantaje">
		<div class="lp-container">
			<div class="lp-head">
				<span class="lp-eyebrow">Problema</span>
				<h2>De ce pierd afacerile locale clienți pe Google?</h2>
				<p>Clienții mulțumiți pleacă în tăcere. Cei nemulțumiți scriu imediat. Iar algoritmul Google te judecă după medie.</p>
			</div>
			<div class="lp-math">
				<div class="lp-math-number">1 × <span class="lp-red">1★</span> = 12 × <span class="lp-green">5★</span></div>
				<p><strong>O singură recenzie de 1★ pe Google Maps are nevoie de 12 recenzii de 5★ doar pentru a readuce media la 4.7.</strong> Fiecare nemulțumire interceptată privat este o recenzie negativă care nu mai trebuie „reparată”.</p>
			</div>
			<div class="lp-grid-3" id="functionalitati">
				<?php foreach ( $advantages as $advantage ) : ?>
					<article class="lp-card">
						<span class="lp-icon"><i class="bi <?php echo esc_attr( $advantage[0] ); ?>"></i></span>
						<h3><?php echo esc_html( $advantage[1] ); ?></h3>
						<p><?php echo esc_html( $advantage[2] ); ?></p>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<section class="lp-section lp-alt" id="cum-functioneaza">
		<div class="lp-container">
			<div class="lp-head">
				<span class="lp-eyebrow">Cum funcționează</span>
				<h2>Trei pași. Zero bătăi de cap.</h2>
			</div>
			<div class="lp-steps">
				<div class="lp-step">
					<span class="lp-step-num">1</span>
					<h3>Clientul scanează</h3>
					<p>Codul QR de pe masă, recepție sau cartea de vizită a tehnicianului — sau dă click pe linkul din email / WhatsApp.</p>
				</div>
				<div class="lp-step">
					<span class="lp-step-num">2</span>
					<h3>Alege stelele</h3>
					<p>Pe pagina ta personalizată, cu logo-ul și coperta firmei, clientul alege 1–5★ într-o secundă.</p>
				</div>
				<div class="lp-step">
					<span class="lp-step-num">3</span>
					<h3>Filtrul decide instantaneu</h3>
					<p>Peste pragul setat de tine (ex: 3★ sau 4★) se deschide fereastra oficială Google Review; sub prag, formularul privat de sesizare către tine.</p>
				</div>
			</div>
		</div>
	</section>

	<section class="lp-section" id="clienti">
		<div class="lp-container">
			<div class="lp-head">
				<span class="lp-eyebrow">Pentru clienții tăi</span>
				<h2>Și clienții tăi câștigă: vocea lor ajunge exact unde trebuie</h2>
				<p>Un client nemulțumit nu vrea să te „pedepsească” — vrea să fie ascultat. Pe Google, sesizarea lui se pierde într-o listă de recenzii pe care poate nu o citește nimeni de la conducere. Cu <?php echo esc_html( $brand ); ?>, ajunge direct la decident.</p>
			</div>

			<div class="lp-compare">
				<div class="lp-compare-col is-bad">
					<div class="lp-compare-title"><i class="bi bi-x-circle"></i> Recenzie publică de 1★</div>
					<ul>
						<li>Se pierde printre sute de recenzii, uneori fără niciun răspuns</li>
						<li>Clientul se expune public, cu numele lui</li>
						<li>Nu primește o soluție, doar „descarcă” nervii</li>
						<li>Rămâne acolo ani de zile și sperie clienții noi</li>
					</ul>
				</div>
				<div class="lp-compare-col is-good">
					<div class="lp-compare-title"><i class="bi bi-check-circle"></i> Sesizare privată prin <?php echo esc_html( $brand ); ?></div>
					<ul>
						<li>Ajunge instant pe emailul managerului și în lista de tichete</li>
						<li>Rămâne discretă: o vede doar echipa afacerii</li>
						<li>Clientul poate lăsa un telefon și e contactat pentru o soluție</li>
						<li>Problema se rezolvă, iar clientul are motiv să revină</li>
					</ul>
				</div>
			</div>

			<div class="lp-grid-3">
				<article class="lp-card">
					<span class="lp-icon"><i class="bi bi-person-check"></i></span>
					<h3>Ascultat de cine poate decide</h3>
					<p>Sesizarea ajunge direct la proprietar sau la operatorul desemnat, nu la un algoritm sau într-un colț uitat al internetului.</p>
				</article>
				<article class="lp-card">
					<span class="lp-icon"><i class="bi bi-lightning-charge"></i></span>
					<h3>30 de secunde, fără cont sau aplicație</h3>
					<p>Scanează codul, alege stelele, scrie două rânduri. Nu trebuie să-și facă cont Google și nu instalează nimic.</p>
				</article>
				<article class="lp-card">
					<span class="lp-icon"><i class="bi bi-shield-lock"></i></span>
					<h3>Datele lui rămân private</h3>
					<p>Numele și telefonul sunt opționale și sunt folosite exclusiv pentru rezolvarea sesizării, conform GDPR.</p>
				</article>
			</div>
		</div>
	</section>

	<section class="lp-section lp-alt" id="roi">
		<div class="lp-container lp-roi">
			<div>
				<span class="lp-eyebrow">Calculează impactul</span>
				<h2>Câte recenzii de 5★ pierzi în fiecare lună?</h2>
				<p>Mută cursoarele pentru a estima ce poate aduce <?php echo esc_html( $brand ); ?> afacerii tale.</p>
				<label class="lp-range">
					<span>Clienți pe lună <strong data-lp-out="clients">600</strong></span>
					<input type="range" min="50" max="5000" step="50" value="600" data-lp-input="clients" aria-label="Clienți pe lună">
				</label>
				<label class="lp-range">
					<span>Câți scanează codul QR <strong data-lp-out="scan">10%</strong></span>
					<input type="range" min="2" max="40" step="1" value="10" data-lp-input="scan" aria-label="Procent clienți care scanează">
				</label>
				<label class="lp-range">
					<span>Clienți mulțumiți <strong data-lp-out="happy">85%</strong></span>
					<input type="range" min="50" max="98" step="1" value="85" data-lp-input="happy" aria-label="Procent clienți mulțumiți">
				</label>
			</div>
			<div class="lp-roi-result">
				<div class="lp-roi-item"><span>Recenzii noi pe Google / lună</span><strong class="lp-green" data-lp-result="google">51</strong></div>
				<div class="lp-roi-item"><span>Nemulțumiri interceptate privat / lună</span><strong class="lp-red" data-lp-result="private">9</strong></div>
				<div class="lp-roi-item"><span>Recenzii noi pe Google / an</span><strong data-lp-result="year">612</strong></div>
				<p class="lp-micro">Estimare orientativă. Rezultatele reale depind de plasarea codurilor QR și de momentul în care le prezinți clienților.</p>
			</div>
		</div>
	</section>

	<section class="lp-section" id="preturi">
		<div class="lp-container">
			<div class="lp-head">
				<span class="lp-eyebrow">Prețuri transparente</span>
				<h2>Alege planul potrivit afacerii tale</h2>
				<p>Toate planurile încep cu <?php echo esc_html( $trial_days ); ?> de zile gratuite. Fără card bancar.</p>
			</div>
			<div class="lp-toggle" role="radiogroup" aria-label="Perioadă de plată">
				<label><input type="radio" name="lp_cycle" value="monthly" data-lp-cycle checked><span>Plată Lunară</span></label>
				<label><input type="radio" name="lp_cycle" value="yearly" data-lp-cycle><span>Plată Anuală <em>Economisești 2 luni · 17%</em></span></label>
			</div>
			<div class="lp-pricing">
				<?php $previous = ''; ?>
				<?php foreach ( $plans as $plan ) : ?>
					<?php
					$popular  = 1 === (int) $plan['allow_ref_tags'] && 0 === (int) $plan['includes_onboarding'];
					$starting = 1 === (int) $plan['price_is_starting'];
					$monthly  = get_plan_price( $plan, 'monthly' );
					$yearly   = get_plan_price( $plan, 'yearly' );
					?>
					<article class="lp-plan<?php echo $popular ? ' is-popular' : ''; ?>">
						<?php if ( $popular ) : ?>
							<span class="lp-plan-badge">Cel mai popular</span>
						<?php endif; ?>
						<h3><?php echo esc_html( mb_strtoupper( $plan['name'] ) ); ?></h3>
						<div class="lp-price" data-lp-price="monthly">
							<?php if ( $starting ) : ?>
								<small>De la</small>
							<?php endif; ?>
							<strong><?php echo esc_html( format_number( $monthly, 0 ) ); ?></strong> <span>RON / lună</span>
							<div class="lp-price-note">sau <?php echo esc_html( format_number( $yearly, 0 ) ); ?> RON / an<?php echo $starting ? ' · negociabil' : ''; ?></div>
						</div>
						<div class="lp-price" data-lp-price="yearly" hidden>
							<?php if ( $starting ) : ?>
								<small>De la</small>
							<?php endif; ?>
							<strong><?php echo esc_html( format_number( $yearly, 0 ) ); ?></strong> <span>RON / an</span>
							<div class="lp-price-note">echivalent <?php echo esc_html( format_number( $yearly / 12, 0 ) ); ?> RON / lună · economisești <?php echo esc_html( format_number( max( 0, $monthly * 12 - $yearly ), 0 ) ); ?> RON</div>
						</div>
						<?php if ( '' !== $plan['target_description'] ) : ?>
							<p class="lp-plan-target"><strong>Ideal pentru:</strong> <?php echo esc_html( $plan['target_description'] ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== $previous ) : ?>
							<p class="lp-plan-inherit">Include totul din <?php echo esc_html( $previous ); ?>, plus:</p>
						<?php endif; ?>
						<ul>
							<?php foreach ( landing_plan_features( $plan ) as $feature ) : ?>
								<li><i class="bi bi-check2"></i> <span><?php echo $feature; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in landing_plan_features(). ?></span></li>
							<?php endforeach; ?>
						</ul>
						<div class="lp-plan-cta">
							<?php if ( 1 === (int) $plan['includes_onboarding'] && '' !== $support_email ) : ?>
								<a class="lp-btn lp-btn-dark" href="<?php echo esc_url( 'mailto:' . $support_email . '?subject=' . rawurlencode( 'Ofertă ' . $plan['name'] . ' ' . $brand ) ); ?>">Contactează-ne</a>
								<?php if ( '' !== $phone_url ) : ?>
									<a class="lp-btn lp-btn-light" href="<?php echo esc_url( $phone_url ); ?>"><i class="bi bi-telephone"></i> <?php echo esc_html( $phone ); ?></a>
								<?php endif; ?>
								<a class="lp-btn lp-btn-light" href="<?php echo esc_url( $register_url ); ?>">sau începe gratuit</a>
							<?php else : ?>
								<a class="lp-btn <?php echo esc_attr( $popular ? 'lp-btn-dark' : 'lp-btn-light' ); ?>" href="<?php echo esc_url( $register_url ); ?>">Începe <?php echo esc_html( $trial_days ); ?> zile gratuit</a>
							<?php endif; ?>
						</div>
					</article>
					<?php $previous = mb_strtoupper( $plan['name'] ); ?>
				<?php endforeach; ?>
			</div>
			<div class="lp-included">
				<strong><i class="bi bi-stars"></i> Incluse în toate planurile:</strong>
				<span>White-Label (fără branding <?php echo esc_html( $brand ); ?>)</span>
				<span>Șabloane de print personalizate (A4, A5, carte de vizită, sticker)</span>
				<span>Widget pentru site & semnătură email</span>
				<span>Raport lunar ROI & export CSV</span>
				<span>Conturi de operatori pentru reclamații</span>
			</div>
		</div>
	</section>

	<section class="lp-section lp-alt" id="intrebari">
		<div class="lp-container lp-narrow">
			<div class="lp-head">
				<span class="lp-eyebrow">Întrebări frecvente</span>
				<h2>Tot ce vrei să știi înainte să începi</h2>
			</div>
			<div class="lp-faq">
				<?php foreach ( $faq as $index => $entry ) : ?>
					<details<?php echo 0 === $index ? ' open' : ''; ?>>
						<summary><?php echo esc_html( $entry[0] ); ?><i class="bi bi-plus-lg"></i></summary>
						<p><?php echo esc_html( $entry[1] ); ?></p>
					</details>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<section class="lp-section">
		<div class="lp-container">
			<div class="lp-final">
				<h2>Protejează-ți ratingul de pe Google de astăzi.</h2>
				<p>Configurarea durează sub 2 minute. Primii clienți mulțumiți pot ajunge pe Google chiar în seara asta.</p>
				<div class="lp-cta-row lp-center">
					<a class="lp-btn lp-btn-white lp-btn-lg" href="<?php echo esc_url( $register_url ); ?>">Începe luna gratuită <i class="bi bi-arrow-right"></i></a>
					<?php if ( '' !== $phone_url ) : ?>
						<a class="lp-btn lp-btn-ghost lp-btn-lg" href="<?php echo esc_url( $phone_url ); ?>"><i class="bi bi-telephone"></i> Sună-ne: <?php echo esc_html( $phone ); ?></a>
					<?php else : ?>
						<a class="lp-btn lp-btn-ghost lp-btn-lg" href="#preturi">Vezi prețurile</a>
					<?php endif; ?>
				</div>
				<p class="lp-micro">Fără card bancar · Anulezi oricând · Suport în limba română</p>
			</div>
		</div>
	</section>
</main>

<?php
require REPUVIO_ROOT . '/templates/public-footer.php';
