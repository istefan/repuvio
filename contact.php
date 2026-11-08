<?php
/**
 * Public page: Contact & Suport (route /contact).
 * Provider details card + quick links, and a secure contact form (CSRF, honeypot, signed timer,
 * rate limit, GDPR checkbox). Messages are stored in contact_messages and emailed to the platform owner.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once __DIR__ . '/includes/bootstrap.php';

$legal    = get_legal_settings();
$subjects = get_contact_subjects();
$errors   = [];
$form     = [
	'name'    => '',
	'company' => '',
	'email'   => '',
	'phone'   => '',
	'subject' => array_key_exists( input_get( 'subiect' ), $subjects ) ? input_get( 'subiect' ) : 'commercial',
	'message' => '',
	'gdpr'    => false,
];

if ( is_post() ) {
	$result = handle_contact_submission(
		[
			'name'    => input_post( 'name' ),
			'company' => input_post( 'company' ),
			'email'   => input_post( 'email' ),
			'phone'   => input_post( 'phone' ),
			'subject' => input_post( 'subject' ),
			'message' => input_post( 'message' ),
			'gdpr'    => input_post( 'gdpr' ),
			'website' => input_post( 'website' ),
			'ft'      => input_post( 'ft' ),
		]
	);

	if ( $result['success'] ) {
		$_SESSION['contact_sent'] = 1;
		redirect( legal_url( 'contact' ) . '#formular', 303 );
	}

	$errors = $result['errors'];
	$form   = $result['data'];
}

$sent = ! empty( $_SESSION['contact_sent'] );
unset( $_SESSION['contact_sent'] );

$public_title       = 'Contact & Suport · ' . app_name();
$public_description = 'Contactează echipa ' . app_name() . ' pentru oferte Enterprise, întrebări comerciale, suport tehnic sau parteneriate.';

require REPUVIO_ROOT . '/templates/public-head.php';
require REPUVIO_ROOT . '/templates/public-nav.php';
?>
<main class="lp-legal">
	<section class="lp-legal-hero">
		<div class="lp-container">
			<span class="lp-eyebrow">Contact & Suport</span>
			<h1>Hai să vorbim despre reputația afacerii tale</h1>
			<p class="lp-lead">Oferte Enterprise, întrebări comerciale, suport tehnic sau parteneriate — îți răspundem în cel mult o zi lucrătoare.</p>
		</div>
	</section>

	<div class="lp-container lp-contact-grid">
		<aside>
			<div class="lp-card lp-contact-card">
				<h2 class="h-sm"><?php echo esc_html( $legal['legal_company_name'] ); ?></h2>
				<ul class="lp-contact-list">
					<li><i class="bi bi-envelope"></i> <a href="<?php echo esc_url( 'mailto:' . $legal['legal_email'] ); ?>"><?php echo esc_html( $legal['legal_email'] ); ?></a></li>
					<li><i class="bi bi-telephone"></i> <a href="<?php echo esc_url( phone_tel_url( $legal['legal_phone'] ) ); ?>"><?php echo esc_html( $legal['legal_phone'] ); ?></a></li>
					<li><i class="bi bi-clock"></i> Suport: Luni – Vineri, 09:00 – 17:00</li>
					<li><i class="bi bi-geo-alt"></i> <?php echo esc_html( $legal['legal_address'] ); ?></li>
					<li><i class="bi bi-building"></i> CUI <?php echo esc_html( $legal['legal_cui'] ); ?> · <?php echo esc_html( $legal['legal_reg_com'] ); ?></li>
				</ul>
			</div>
			<div class="lp-card lp-contact-card">
				<h2 class="h-sm">Răspunsuri rapide</h2>
				<ul class="lp-contact-links">
					<li><a href="<?php echo esc_url( app_url() . '#preturi' ); ?>"><i class="bi bi-tag"></i> Prețuri și planuri</a></li>
					<li><a href="<?php echo esc_url( app_url() . '#intrebari' ); ?>"><i class="bi bi-question-circle"></i> Întrebări frecvente</a></li>
					<li><a href="<?php echo esc_url( legal_url( 'terms' ) ); ?>"><i class="bi bi-file-text"></i> Termeni și Condiții</a></li>
					<li><a href="<?php echo esc_url( legal_url( 'privacy' ) ); ?>"><i class="bi bi-shield-lock"></i> Confidențialitate (GDPR)</a></li>
					<li><a href="<?php echo esc_url( app_url( 'auth/register.php' ) ); ?>"><i class="bi bi-rocket-takeoff"></i> Începe perioada de probă gratuită</a></li>
				</ul>
			</div>
		</aside>

		<section class="lp-card lp-contact-form" id="formular">
			<?php if ( $sent ) : ?>
				<div class="lp-success">
					<i class="bi bi-check2-circle"></i>
					<h2>Mulțumim! Mesajul tău a fost trimis.</h2>
					<p>Îți răspundem pe email în cel mult o zi lucrătoare.</p>
					<a class="lp-btn lp-btn-light" href="<?php echo esc_url( app_url() ); ?>">Înapoi la pagina principală</a>
				</div>
			<?php else : ?>
				<h2 class="h-sm">Trimite-ne un mesaj</h2>

				<?php if ( ! empty( $errors ) ) : ?>
					<div class="lp-alert" role="alert">
						<?php foreach ( $errors as $error ) : ?>
							<div><?php echo esc_html( $error ); ?></div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( legal_url( 'contact' ) . '#formular' ); ?>" novalidate>
					<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<input type="hidden" name="ft" value="<?php echo esc_attr( contact_form_timer() ); ?>">
					<div class="lp-hp" aria-hidden="true">
						<label for="c-website">Website</label>
						<input type="text" id="c-website" name="website" tabindex="-1" autocomplete="off">
					</div>

					<div class="lp-form-grid">
						<label class="lp-field">
							<span>Nume complet *</span>
							<input type="text" name="name" value="<?php echo esc_attr( $form['name'] ); ?>" maxlength="150" required autocomplete="name">
						</label>
						<label class="lp-field">
							<span>Numele companiei</span>
							<input type="text" name="company" value="<?php echo esc_attr( $form['company'] ); ?>" maxlength="150" autocomplete="organization">
						</label>
						<label class="lp-field">
							<span>Email *</span>
							<input type="email" name="email" value="<?php echo esc_attr( $form['email'] ); ?>" maxlength="190" required autocomplete="email">
						</label>
						<label class="lp-field">
							<span>Telefon</span>
							<input type="tel" name="phone" value="<?php echo esc_attr( $form['phone'] ); ?>" maxlength="50" autocomplete="tel">
						</label>
						<label class="lp-field lp-field-full">
							<span>Subiect</span>
							<select name="subject">
								<?php foreach ( $subjects as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>"<?php echo $key === $form['subject'] ? ' selected' : ''; ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label class="lp-field lp-field-full">
							<span>Mesaj *</span>
							<textarea name="message" rows="6" maxlength="5000" required><?php echo esc_textarea( $form['message'] ); ?></textarea>
						</label>
					</div>

					<label class="lp-check">
						<input type="checkbox" name="gdpr" value="1"<?php echo $form['gdpr'] ? ' checked' : ''; ?> required>
						<span>Sunt de acord ca datele mele să fie folosite pentru a primi un răspuns la această solicitare, conform <a href="<?php echo esc_url( legal_url( 'privacy' ) ); ?>" target="_blank" rel="noopener">Politicii de Confidențialitate</a>.</span>
					</label>

					<button type="submit" class="lp-btn lp-btn-dark lp-btn-lg"><i class="bi bi-send"></i> Trimite mesajul</button>
				</form>
			<?php endif; ?>
		</section>
	</div>
</main>
<?php
require REPUVIO_ROOT . '/templates/public-footer.php';
