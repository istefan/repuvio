<?php
/**
 * Super Admin: messages sent through the public contact form (/contact). Stored in contact_messages,
 * independent of email delivery. Filter (new / all / archived), read the full message, reply by email,
 * change status (Nou / Citit / Răspuns trimis / Arhivat), delete.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

require_super_admin();

$statuses = get_contact_statuses();
$subjects = get_contact_subjects();
$filters  = [
	'open'     => 'Nearhivate',
	'new'      => 'Noi',
	'archived' => 'Arhivate',
	'all'      => 'Toate',
];
$filter   = array_key_exists( input_get( 'show' ), $filters ) ? input_get( 'show' ) : 'open';

if ( is_post() ) {
	$message_id = (int) input_post( 'message_id' );
	$action     = input_post( 'action' );

	if ( 'status' === $action && array_key_exists( input_post( 'status' ), $statuses ) ) {
		db_query( 'UPDATE contact_messages SET status = ? WHERE id = ?', [ input_post( 'status' ), $message_id ] );
		audit_log( 'admin_contact_status', 'message=' . $message_id . ' status=' . input_post( 'status' ) );
		flash( 'success', 'Mesajul #' . $message_id . ' a fost actualizat.' );
	} elseif ( 'delete' === $action ) {
		db_query( 'DELETE FROM contact_messages WHERE id = ?', [ $message_id ] );
		audit_log( 'admin_contact_deleted', 'message=' . $message_id );
		flash( 'success', 'Mesajul #' . $message_id . ' a fost șters definitiv.' );
	}

	redirect( app_url( 'admin/contact-messages.php', [ 'show' => input_post( 'show', 'open' ) ] ) );
}

$where = [
	'open'     => "WHERE status <> 'archived'",
	'new'      => "WHERE status = 'new'",
	'archived' => "WHERE status = 'archived'",
	'all'      => '',
];

$messages = db_all( 'SELECT * FROM contact_messages ' . $where[ $filter ] . " ORDER BY FIELD(status, 'new', 'read', 'replied', 'archived'), created_at DESC LIMIT 200" );

$page_title       = 'Mesaje contact';
$active_nav       = 'contact';
$page_breadcrumbs = [ [ 'Super Admin', app_url( 'admin/index.php' ) ], [ 'Mesaje contact', '' ] ];

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
	<div class="btn-group btn-group-sm">
		<?php foreach ( $filters as $key => $label ) : ?>
			<a class="btn <?php echo esc_attr( $key === $filter ? 'btn-dark' : 'btn-light' ); ?>" href="<?php echo esc_url( app_url( 'admin/contact-messages.php', [ 'show' => $key ] ) ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</div>
	<a class="btn btn-sm btn-light" href="<?php echo esc_url( legal_url( 'contact' ) ); ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Deschide pagina de contact</a>
</div>

<div class="rm-card">
	<?php if ( empty( $messages ) ) : ?>
		<div class="rm-empty"><i class="bi bi-envelope-open"></i><p class="mt-2 mb-0">Niciun mesaj în această listă.</p></div>
	<?php else : ?>
		<div class="table-responsive">
			<table class="table rm-table">
				<thead><tr><th>#</th><th>Data</th><th>Expeditor</th><th>Subiect</th><th>Mesaj</th><th>Email</th><th style="min-width: 220px;">Status</th></tr></thead>
				<tbody>
					<?php foreach ( $messages as $message ) : ?>
						<?php $reply = 'mailto:' . $message['email'] . '?subject=' . rawurlencode( 'Re: ' . ( $subjects[ $message['subject'] ] ?? 'Mesajul tău' ) . ' — ' . app_name() ); ?>
						<tr>
							<td class="fw-semibold">#<?php echo esc_html( (int) $message['id'] ); ?></td>
							<td class="small text-nowrap"><?php echo esc_html( format_datetime( $message['created_at'] ) ); ?><div class="text-muted"><?php echo esc_html( time_ago( $message['created_at'] ) ); ?></div></td>
							<td>
								<div class="fw-medium text-dark"><?php echo esc_html( $message['name'] ); ?></div>
								<div class="small text-muted"><?php echo esc_html( '' !== $message['company'] ? $message['company'] : '—' ); ?></div>
								<div class="small"><a href="<?php echo esc_url( $reply ); ?>"><?php echo esc_html( $message['email'] ); ?></a><?php echo '' !== $message['phone'] ? esc_html( ' · ' . $message['phone'] ) : ''; ?></div>
							</td>
							<td class="small"><?php echo esc_html( $subjects[ $message['subject'] ] ?? $message['subject'] ); ?></td>
							<td class="small" style="max-width: 380px;">
								<details>
									<summary class="text-truncate"><?php echo esc_html( mb_substr( $message['message'], 0, 90 ) ); ?></summary>
									<div class="mt-2 p-2 rounded-3 border bg-light-subtle"><?php echo nl2br( esc_html( $message['message'] ) ); ?></div>
									<div class="text-muted mt-1"><?php echo esc_html( 1 === (int) $message['gdpr_consent'] ? 'Acord GDPR exprimat la trimitere.' : 'Fără acord GDPR înregistrat.' ); ?></div>
								</details>
							</td>
							<td>
								<?php if ( 1 === (int) $message['email_sent'] ) : ?>
									<span class="rm-pill rm-pill-positive"><i class="bi bi-envelope-check"></i> Trimis</span>
								<?php else : ?>
									<span class="rm-pill rm-pill-negative" title="Notificarea pe email a eșuat — mesajul există doar aici"><i class="bi bi-envelope-x"></i> Netrimis</span>
								<?php endif; ?>
							</td>
							<td>
								<form method="post" class="d-flex flex-wrap gap-1 align-items-center">
									<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<input type="hidden" name="action" value="status">
									<input type="hidden" name="message_id" value="<?php echo esc_attr( $message['id'] ); ?>">
									<input type="hidden" name="show" value="<?php echo esc_attr( $filter ); ?>">
									<select class="form-select form-select-sm w-auto" name="status" data-autosubmit aria-label="Status mesaj">
										<?php foreach ( $statuses as $key => $status ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>"<?php echo $key === $message['status'] ? ' selected' : ''; ?>><?php echo esc_html( $status[0] ); ?></option>
										<?php endforeach; ?>
									</select>
									<a class="btn btn-sm btn-light" href="<?php echo esc_url( $reply ); ?>" title="Răspunde pe email"><i class="bi bi-reply"></i></a>
								</form>
								<form method="post" class="mt-1" data-confirm="Ștergi definitiv mesajul? (dreptul la ștergere GDPR)">
									<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<input type="hidden" name="action" value="delete">
									<input type="hidden" name="message_id" value="<?php echo esc_attr( $message['id'] ); ?>">
									<input type="hidden" name="show" value="<?php echo esc_attr( $filter ); ?>">
									<button type="submit" class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-trash"></i> Șterge</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>

<?php
require REPUVIO_ROOT . '/templates/app-footer.php';
