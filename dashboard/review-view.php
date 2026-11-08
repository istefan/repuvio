<?php
/**
 * Feedback ticket detail: status changes and internal resolution notes (owners and assigned operators).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$user     = require_panel_access();
$is_owner = 'user' === $user['role'];

unlock_pending_reviews( get_account_owner_id( $user ) );

$review_id = sanitize_int_range( is_post() ? input_post( 'review_id', '0' ) : input_get( 'id', '0' ), 0, PHP_INT_MAX, 0 );
$review    = get_owned_review( $review_id );

if ( ! $review ) {
	render_error_page( 404, 'Feedback inexistent', 'Înregistrarea nu există sau nu îți aparține.' );
}

$is_locked = 1 === (int) $review['is_locked'];
$statuses  = get_ticket_statuses();

if ( is_post() ) {
	if ( $is_locked ) {
		flash( 'warning', 'Feedback-ul este blocat. Reînnoiește abonamentul pentru a-l gestiona.' );
		redirect( app_url( 'dashboard/review-view.php', [ 'id' => $review_id ] ) );
	}

	$action = input_post( 'action' );

	if ( 'status' === $action ) {
		$status = input_post( 'ticket_status' );

		if ( array_key_exists( $status, $statuses ) ) {
			db_query( 'UPDATE reviews SET ticket_status = ? WHERE id = ?', [ $status, (int) $review['id'] ] );
			db_query( 'INSERT INTO review_notes (review_id, user_id, note) VALUES (?, ?, ?)', [ (int) $review['id'], (int) $user['id'], 'Status schimbat în „' . $statuses[ $status ] . '”.' ] );
			flash( 'success', 'Statusul a fost actualizat: ' . $statuses[ $status ] . '.' );
		}
	} elseif ( 'note' === $action ) {
		$note = sanitize_textarea( input_post( 'note' ), 3000 );

		if ( '' !== $note ) {
			db_query( 'INSERT INTO review_notes (review_id, user_id, note) VALUES (?, ?, ?)', [ (int) $review['id'], (int) $user['id'], $note ] );

			if ( 'new' === $review['ticket_status'] ) {
				db_query( "UPDATE reviews SET ticket_status = 'in_progress' WHERE id = ?", [ (int) $review['id'] ] );
			}

			flash( 'success', 'Nota a fost adăugată.' );
		} else {
			flash( 'warning', 'Nota nu poate fi goală.' );
		}
	}

	redirect( app_url( 'dashboard/review-view.php', [ 'id' => $review_id ] ) );
}

$notes   = $is_locked ? [] : get_review_notes( (int) $review['id'] );
$contact = (string) $review['customer_contact'];
$email   = sanitize_email_address( $contact );
$phone   = preg_replace( '/[^0-9+]/', '', $contact );

$page_title       = 'Feedback #' . (int) $review['id'];
$active_nav       = 'reviews';
$page_breadcrumbs = [ [ 'Acasă', app_url( 'dashboard/index.php' ) ], [ 'Feedback & Recenzii', app_url( 'dashboard/reviews.php' ) ], [ '#' . (int) $review['id'], '' ] ];

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<div class="row g-3">
	<div class="col-xl-8">
		<div class="rm-card mb-3">
			<div class="rm-card-header">
				<div class="d-flex align-items-center gap-2 flex-wrap">
					<?php echo rating_badge( $review['rating'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper. ?>
					<span class="fw-semibold text-dark"><?php echo esc_html( $review['company_name'] ); ?></span>
					<span class="small text-muted">· <?php echo esc_html( format_datetime( $review['created_at'] ) ); ?> · <?php echo esc_html( get_source_label( $review['source'] ) ); ?></span>
					<?php if ( '' !== $review['ref_tag'] ) : ?>
						<span class="rm-pill rm-pill-muted"><i class="bi bi-tag"></i> <?php echo esc_html( format_ref_tag( $review['ref_tag'], $review['ref_label'] ) ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( 'private_feedback' === $review['action_taken'] ) : ?>
					<?php echo ticket_status_badge( $review['ticket_status'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper. ?>
				<?php endif; ?>
			</div>
			<div class="rm-card-body">
				<?php if ( 'redirected_google' === $review['action_taken'] ) : ?>
					<p class="mb-0 text-muted"><i class="bi bi-google"></i> Clientul a acordat <?php echo esc_html( (int) $review['rating'] ); ?> stele și a fost redirecționat către Google Reviews.</p>
				<?php elseif ( $is_locked ) : ?>
					<div class="rm-locked">
						<div class="rm-locked-content p-3">
							<p class="fw-semibold mb-1">Client •••••••</p>
							<p class="mb-1">•••• •••• ••••@•••••.ro</p>
							<p class="mb-0">Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam.</p>
						</div>
						<div class="rm-locked-overlay">
							<i class="bi bi-lock-fill fs-3 text-dark"></i>
							<div class="fw-semibold text-dark">Feedback blocat</div>
							<div class="small text-muted">Upgrade / Reînnoiește abonamentul pentru a debloca feedback-ul privat și datele de contact ale clientului.</div>
							<?php if ( $is_owner ) : ?>
								<a class="btn btn-dark btn-sm mt-1" href="<?php echo esc_url( app_url( 'dashboard/billing.php' ) ); ?>"><i class="bi bi-unlock"></i> Deblochează acum</a>
							<?php endif; ?>
						</div>
					</div>
				<?php else : ?>
					<div class="row g-3 mb-3">
						<div class="col-sm-6">
							<div class="small text-muted">Nume client</div>
							<div class="fw-semibold text-dark"><?php echo esc_html( '' !== $review['customer_name'] ? $review['customer_name'] : 'Anonim' ); ?></div>
						</div>
						<div class="col-sm-6">
							<div class="small text-muted">Contact</div>
							<?php if ( '' !== $email ) : ?>
								<a href="<?php echo esc_url( 'mailto:' . $email ); ?>"><?php echo esc_html( $email ); ?></a>
							<?php elseif ( 6 <= strlen( $phone ) ) : ?>
								<a href="<?php echo esc_url( 'tel:' . $phone ); ?>"><?php echo esc_html( $contact ); ?></a>
							<?php else : ?>
								<span class="text-dark"><?php echo esc_html( '' !== $contact ? $contact : '—' ); ?></span>
							<?php endif; ?>
						</div>
					</div>
					<div class="small text-muted mb-1">Mesaj</div>
					<div class="p-3 rounded-3 border bg-light-subtle text-dark"><?php echo nl2br( esc_html( (string) $review['message'] ) ); ?></div>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( 'private_feedback' === $review['action_taken'] && ! $is_locked ) : ?>
			<div class="rm-card">
				<div class="rm-card-header"><h2 class="rm-card-title">Note interne de rezolvare</h2></div>
				<div class="rm-card-body">
					<?php if ( empty( $notes ) ) : ?>
						<p class="text-muted small">Nu există note. Notează pașii făcuți pentru a rezolva situația.</p>
					<?php endif; ?>
					<?php foreach ( $notes as $note ) : ?>
						<div class="rm-note">
							<div class="small text-muted mb-1"><strong class="text-dark"><?php echo esc_html( $note['author_name'] ?? 'Utilizator șters' ); ?></strong> · <?php echo esc_html( format_datetime( $note['created_at'] ) ); ?></div>
							<div><?php echo nl2br( esc_html( $note['note'] ) ); ?></div>
						</div>
					<?php endforeach; ?>

					<form method="post" class="mt-3">
						<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<input type="hidden" name="review_id" value="<?php echo esc_attr( $review['id'] ); ?>">
						<input type="hidden" name="action" value="note">
						<label class="form-label" for="note">Adaugă notă</label>
						<textarea class="form-control mb-2" id="note" name="note" rows="3" maxlength="3000" required placeholder="Ex: Am sunat clientul, i-am oferit un voucher de 20%."></textarea>
						<button type="submit" class="btn btn-dark btn-sm"><i class="bi bi-plus-lg"></i> Adaugă nota</button>
					</form>
				</div>
			</div>
		<?php endif; ?>
	</div>

	<div class="col-xl-4">
		<?php if ( 'private_feedback' === $review['action_taken'] && ! $is_locked ) : ?>
			<div class="rm-card mb-3">
				<div class="rm-card-header"><h2 class="rm-card-title">Status tichet</h2></div>
				<div class="rm-card-body">
					<form method="post">
						<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<input type="hidden" name="review_id" value="<?php echo esc_attr( $review['id'] ); ?>">
						<input type="hidden" name="action" value="status">
						<?php foreach ( $statuses as $key => $label ) : ?>
							<div class="form-check mb-2">
								<input class="form-check-input" type="radio" name="ticket_status" id="status-<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $key ); ?>"<?php echo $key === $review['ticket_status'] ? ' checked' : ''; ?>>
								<label class="form-check-label" for="status-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
							</div>
						<?php endforeach; ?>
						<button type="submit" class="btn btn-dark btn-sm w-100 mt-2">Actualizează statusul</button>
					</form>
				</div>
			</div>
		<?php endif; ?>

		<div class="rm-card">
			<div class="rm-card-body small">
				<div class="d-flex justify-content-between mb-2"><span class="text-muted">ID</span><span>#<?php echo esc_html( (int) $review['id'] ); ?></span></div>
				<div class="d-flex justify-content-between mb-2"><span class="text-muted">Primit</span><span><?php echo esc_html( format_datetime( $review['created_at'] ) ); ?></span></div>
				<div class="d-flex justify-content-between mb-2"><span class="text-muted">Sursă</span><span><?php echo esc_html( get_source_label( $review['source'] ) ); ?></span></div>
				<div class="d-flex justify-content-between mb-2"><span class="text-muted">Angajat / etichetă</span><span><?php echo esc_html( format_ref_tag( $review['ref_tag'], $review['ref_label'] ) ); ?></span></div>
				<div class="d-flex justify-content-between"><span class="text-muted">Actualizat</span><span><?php echo esc_html( format_datetime( $review['updated_at'] ) ); ?></span></div>
			</div>
		</div>
		<a class="btn btn-light w-100 mt-3" href="<?php echo esc_url( app_url( 'dashboard/reviews.php' ) ); ?>"><i class="bi bi-arrow-left"></i> Înapoi la listă</a>
	</div>
</div>

<?php
require REPUVIO_ROOT . '/templates/app-footer.php';
