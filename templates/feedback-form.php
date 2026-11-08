<?php
/**
 * Private feedback form (used in the public funnel modal and the no-JS inline state).
 *
 * Expects: $action (string), $rating (int), $form (array), $form_error (string), $timer (string), $company (array).
 * Includes the mandatory GDPR consent (privacy_consent) shown under the contact fields.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;
?>
<form method="post" action="<?php echo esc_url( $action ); ?>" class="rf-form" id="rfFeedbackForm" novalidate>
	<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<input type="hidden" name="action" value="feedback">
	<input type="hidden" name="rating" value="<?php echo esc_attr( $rating ); ?>" id="rfRatingInput">
	<input type="hidden" name="ft" value="<?php echo esc_attr( $timer ); ?>">

	<h2 class="rf-question" id="rfModalTitle">Ne pare rău că experiența ta nu a fost de 5 stele.</h2>
	<p class="rf-text">Te rugăm să ne spui ce s-a întâmplat pentru a putea remedia situația.</p>

	<?php if ( '' !== $form_error ) : ?>
		<div class="rf-alert" role="alert"><?php echo esc_html( $form_error ); ?></div>
	<?php endif; ?>

	<div class="rf-hp" aria-hidden="true">
		<label for="rf-website">Website</label>
		<input type="text" id="rf-website" name="website" tabindex="-1" autocomplete="off">
	</div>

	<label class="rf-label" for="rf-name">Nume <span>(opțional)</span></label>
	<input class="rf-input" type="text" id="rf-name" name="customer_name" maxlength="150" autocomplete="name" value="<?php echo esc_attr( $form['name'] ); ?>">

	<label class="rf-label" for="rf-contact">Telefon sau email <span>(opțional, ca să te putem contacta)</span></label>
	<input class="rf-input" type="text" id="rf-contact" name="customer_contact" maxlength="190" autocomplete="email" value="<?php echo esc_attr( $form['contact'] ); ?>">

	<label class="rf-label" for="rf-message">Ce s-a întâmplat?</label>
	<textarea class="rf-input" id="rf-message" name="message" rows="4" maxlength="3000" required><?php echo esc_textarea( $form['message'] ); ?></textarea>

	<label class="rf-consent">
		<input type="checkbox" name="privacy_consent" value="1" required<?php echo ! empty( $form['consent'] ) ? ' checked' : ''; ?>>
		<span>Datele tale sunt transmise în siguranță exclusiv conducerii <strong><?php echo esc_html( $company['name'] ); ?></strong> pentru soluționarea sesizării, conform <a href="<?php echo esc_url( legal_url( 'privacy' ) ); ?>" target="_blank" rel="noopener">Politicii de Confidențialitate</a>.</span>
	</label>

	<button type="submit" class="rf-btn">Trimite mesajul</button>
	<p class="rf-privacy">Mesajul ajunge doar la echipa afacerii și nu este publicat.</p>
</form>
