<?php
/**
 * Super Admin: "Email & Șabloane".
 *
 * Tabs:
 * - rules     Review notification delivery mode (instant_all / smart_hybrid / daily_digest_only),
 *             Daily Digest hour, SMTP batch throttling, manual Daily Digest test, manual queue run.
 * - templates Every system email: ON/OFF switch, editor (subject + HTML body, placeholder pills),
 *             live preview (also of unsaved changes), test send with demo data, factory reset.
 * - layout    Master layout wrapped around every email (title / logo, accent colour, footer, Reply-To).
 * - logs      Email delivery log (email_logs): filters, view the sent email, re-send, purge > 30 days.
 *
 * Previews are rendered by admin/email-preview.php inside a sandboxed iframe.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$admin      = require_super_admin();
$tabs       = [
	'rules'     => [ 'bi-sliders', 'Reguli de expediere & Rezumat zilnic' ],
	'templates' => [ 'bi-file-earmark-richtext', 'Șabloane de email' ],
	'layout'    => [ 'bi-palette', 'Aspect global' ],
	'logs'      => [ 'bi-journal-text', 'Jurnal emailuri' ],
];
$active_tab = array_key_exists( input_get( 'tab' ), $tabs ) ? input_get( 'tab' ) : 'rules';
$errors     = [];
$categories = get_email_template_categories();
$statuses   = get_email_log_statuses();

/**
 * Redirects back to a tab of the Email Center.
 *
 * @param string $tab   Tab.
 * @param array  $query Extra query.
 * @return void
 */
function email_center_redirect( $tab, array $query = [] ) {
	redirect( app_url( 'admin/emails.php', array_merge( [ 'tab' => $tab ], $query ) ) );
}

if ( is_post() ) {
	$action = input_post( 'action' );

	if ( 'save_rules' === $action ) {
		$mode = input_post( 'review_email_delivery_mode' );

		update_setting( 'review_email_delivery_mode', array_key_exists( $mode, get_review_delivery_modes() ) ? $mode : 'smart_hybrid' );
		update_setting( 'daily_digest_hour', (string) sanitize_int_range( input_post( 'daily_digest_hour' ), 0, 23, 8 ) );
		update_setting( 'smtp_batch_limit_per_run', (string) sanitize_int_range( input_post( 'smtp_batch_limit_per_run' ), 1, 500, 30 ) );
		update_setting( 'smtp_delay_ms', (string) sanitize_int_range( input_post( 'smtp_delay_ms' ), 0, 5000, 250 ) );

		audit_log( 'admin_email_rules', 'mode=' . get_global_delivery_mode() );
		flash( 'success', 'Regulile de expediere au fost salvate.' );
		email_center_redirect( 'rules' );
	} elseif ( 'run_digest' === $action ) {
		$company = db_row( 'SELECT * FROM companies WHERE id = ?', [ (int) input_post( 'company_id' ) ] );
		$date    = sanitize_date( input_post( 'digest_date' ) );

		if ( ! $company ) {
			flash( 'danger', 'Alege o companie.' );
		} elseif ( '' === $date || $date >= date( 'Y-m-d' ) ) {
			flash( 'danger', 'Alege o zi încheiată (ieri sau mai devreme).' );
		} else {
			$to_company = 'company' === input_post( 'digest_recipient' );
			$result     = send_daily_digest(
				$company,
				$date,
				[
					'force'          => true,
					'recipients'     => $to_company ? null : [ $admin['email'] ],
					'subject_prefix' => $to_company ? '' : '[Test] ',
				]
			);

			audit_log( 'admin_digest_manual', 'company=' . (int) $company['id'] . ' date=' . $date . ' to=' . ( $to_company ? 'company' : 'admin' ) );
			flash(
				$result['success'] ? 'success' : 'danger',
				$result['success']
					? 'Rezumatul zilei de ' . format_date( $date ) . ' pentru ' . $company['name'] . ' a fost trimis ' . ( $to_company ? 'destinatarilor companiei' : 'la ' . $admin['email'] ) . ( ! empty( $result['empty'] ) ? ' (ziua nu a avut evaluări: în mod automat nu s-ar fi trimis).' : '.' )
					: 'Trimiterea a eșuat: ' . $result['error']
			);
		}

		email_center_redirect( 'rules' );
	} elseif ( 'run_queue' === $action ) {
		$queue = process_email_queue();

		audit_log( 'admin_email_queue', 'sent=' . $queue['sent'] . ' failed=' . $queue['failed'] );
		flash( 0 === $queue['failed'] ? 'success' : 'warning', 'Coada a fost procesată: ' . $queue['sent'] . ' trimise, ' . $queue['failed'] . ' eșuate, ' . $queue['remaining'] . ' rămase în coadă.' );
		email_center_redirect( 'rules' );
	} elseif ( 'toggle_template' === $action ) {
		$template = get_email_template( input_post( 'slug' ) );

		if ( $template ) {
			$enabled = '1' === input_post( 'is_enabled' ) ? 1 : 0;

			db_query( 'UPDATE email_templates SET is_enabled = ? WHERE id = ?', [ $enabled, (int) $template['id'] ] );
			audit_log( 'admin_email_template_toggle', $template['slug'] . '=' . $enabled );
			flash( 'success', '„' . $template['name'] . '” este acum ' . ( $enabled ? 'PORNIT' : 'OPRIT' ) . '.' );
		}

		email_center_redirect( 'templates' );
	} elseif ( 'save_template' === $action ) {
		$template = get_email_template( input_post( 'slug' ) );
		$subject  = trim( str_replace( [ "\r", "\n" ], ' ', (string) ( $_POST['subject'] ?? '' ) ) );
		$body     = (string) ( $_POST['body_html'] ?? '' );

		if ( ! $template ) {
			email_center_redirect( 'templates' );
		}

		if ( '' === $subject || 255 < mb_strlen( $subject ) ) {
			$errors[] = 'Subiectul este obligatoriu (maxim 255 de caractere).';
		}

		if ( '' === trim( $body ) || 200000 < strlen( $body ) ) {
			$errors[] = 'Conținutul este obligatoriu (maxim 200 KB).';
		}

		if ( empty( $errors ) ) {
			db_query( 'UPDATE email_templates SET subject = ?, body_html = ? WHERE id = ?', [ $subject, $body, (int) $template['id'] ] );
			audit_log( 'admin_email_template_saved', $template['slug'] );
			flash( 'success', 'Șablonul „' . $template['name'] . '” a fost salvat.' );
			email_center_redirect( 'templates', [ 'edit' => $template['slug'] ] );
		}

		$active_tab = 'templates';
	} elseif ( 'reset_template' === $action ) {
		$template = get_email_template( input_post( 'slug' ) );

		if ( $template ) {
			db_query( 'UPDATE email_templates SET subject = default_subject, body_html = default_body_html WHERE id = ?', [ (int) $template['id'] ] );
			audit_log( 'admin_email_template_reset', $template['slug'] );
			flash( 'success', 'Șablonul „' . $template['name'] . '” a revenit la textul implicit.' );
		}

		email_center_redirect( 'templates', $template ? [ 'edit' => $template['slug'] ] : [] );
	} elseif ( 'test_template' === $action ) {
		$template = get_email_template( input_post( 'slug' ) );

		if ( $template ) {
			if ( rate_limit_is_locked( 'email_template_test' ) ) {
				flash( 'warning', 'Prea multe emailuri de test. Așteaptă 10 minute.' );
			} else {
				rate_limit_hit( 'email_template_test', 20, 600, 600 );

				$result = send_templated_email(
					$template['slug'],
					$admin['email'],
					get_email_template_demo_data( $template['slug'] ),
					(int) $admin['id'],
					[
						'force'          => true,
						'subject_prefix' => '[Test] ',
					]
				);

				flash( $result['success'] ? 'success' : 'danger', $result['success'] ? 'Testul „' . $template['name'] . '” a fost trimis la ' . $admin['email'] . ' (cu date demo).' : 'Testul a eșuat: ' . $result['error'] );
			}
		}

		email_center_redirect( 'templates', $template ? [ 'edit' => $template['slug'] ] : [] );
	} elseif ( 'save_layout' === $action ) {
		$logo_url = trim( sanitize_text( input_post( 'email_logo_url' ), 500 ) );
		$accent   = input_post( 'email_accent_color' );
		$reply_to = sanitize_email_address( input_post( 'email_reply_to' ) );

		if ( '' !== $logo_url && ( false === filter_var( $logo_url, FILTER_VALIDATE_URL ) || ! preg_match( '#^https://#i', $logo_url ) ) ) {
			$errors[] = 'URL-ul logo-ului trebuie să înceapă cu https:// (clienții de email blochează imaginile nesecurizate).';
		}

		if ( ! preg_match( '/^#[0-9A-Fa-f]{6}$/', $accent ) ) {
			$errors[] = 'Culoarea de accent trebuie să fie de forma #0F172A.';
		}

		if ( '' !== input_post( 'email_reply_to' ) && '' === $reply_to ) {
			$errors[] = 'Adresa Reply-To nu este validă.';
		}

		if ( empty( $errors ) ) {
			update_setting( 'email_header_title', sanitize_text( input_post( 'email_header_title' ), 120 ) );
			update_setting( 'email_logo_url', $logo_url );
			update_setting( 'email_accent_color', strtoupper( $accent ) );
			update_setting( 'email_footer_text', mb_substr( trim( (string) ( $_POST['email_footer_text'] ?? '' ) ), 0, 1000 ) );
			update_setting( 'email_reply_to', $reply_to );

			audit_log( 'admin_email_layout' );
			flash( 'success', 'Aspectul global al emailurilor a fost salvat. Se aplică tuturor emailurilor noi.' );
			email_center_redirect( 'layout' );
		}

		$active_tab = 'layout';
	} elseif ( 'retry_log' === $action ) {
		$row = db_row( 'SELECT * FROM email_logs WHERE id = ?', [ (int) input_post( 'log_id' ) ] );

		if ( ! $row || '' === $row['body_html'] ) {
			flash( 'danger', 'Emailul nu poate fi retrimis (conținutul nu a fost păstrat).' );
		} else {
			$result = send_logged_email( $row );

			audit_log( 'admin_email_retry', 'log=' . (int) $row['id'] . ' success=' . ( $result['success'] ? 1 : 0 ) );
			flash( $result['success'] ? 'success' : 'danger', $result['success'] ? 'Emailul către ' . $row['recipient_email'] . ' a fost retrimis.' : 'Retrimiterea a eșuat: ' . $result['error'] );
		}

		email_center_redirect( 'logs', array_filter( [ 'status' => input_post( 'return_status' ) ] ) );
	} elseif ( 'purge_logs' === $action ) {
		$purged = db_query( "DELETE FROM email_logs WHERE created_at < NOW() - INTERVAL 30 DAY AND status <> 'queued'" )->rowCount();

		audit_log( 'admin_email_logs_purged', 'rows=' . $purged );
		flash( 'success', 'Am șters ' . $purged . ' înregistrări mai vechi de 30 de zile.' );
		email_center_redirect( 'logs' );
	}
}

$templates = get_email_templates();
$editing   = 'templates' === $active_tab && '' !== input_get( 'edit' ) ? get_email_template( input_get( 'edit' ) ) : null;
$draft     = $editing && is_post() && 'save_template' === input_post( 'action' ) ? [
	'subject'   => (string) ( $_POST['subject'] ?? '' ),
	'body_html' => (string) ( $_POST['body_html'] ?? '' ),
] : null;

if ( ! $editing && is_post() && 'save_template' === input_post( 'action' ) ) {
	$editing = get_email_template( input_post( 'slug' ) );
	$draft   = [
		'subject'   => (string) ( $_POST['subject'] ?? '' ),
		'body_html' => (string) ( $_POST['body_html'] ?? '' ),
	];
}

$layout      = get_email_layout_settings();
$queue_count = (int) db_value( "SELECT COUNT(*) FROM email_logs WHERE status = 'queued'" );
$stats       = db_row(
	"SELECT SUM(status = 'sent' AND created_at >= NOW() - INTERVAL 1 DAY) AS sent_24h,
		SUM(status = 'failed' AND created_at >= NOW() - INTERVAL 7 DAY) AS failed_7d,
		SUM(status = 'skipped_disabled' AND created_at >= NOW() - INTERVAL 7 DAY) AS skipped_7d
	FROM email_logs"
);

// Logs tab: filters + pagination.
$log_filters = [
	'status'   => array_key_exists( input_get( 'status' ), $statuses ) ? input_get( 'status' ) : '',
	'template' => substr( preg_replace( '/[^a-z0-9_]/', '', strtolower( input_get( 'template' ) ) ), 0, 64 ),
	'q'        => sanitize_text( input_get( 'q' ), 100 ),
];
$log_where   = [ '1 = 1' ];
$log_params  = [];

if ( '' !== $log_filters['status'] ) {
	$log_where[]  = 'status = ?';
	$log_params[] = $log_filters['status'];
}

if ( '' !== $log_filters['template'] ) {
	$log_where[]  = 'template_slug = ?';
	$log_params[] = $log_filters['template'];
}

if ( '' !== $log_filters['q'] ) {
	$log_where[]  = '(recipient_email LIKE ? OR subject LIKE ?)';
	$log_params[] = '%' . $log_filters['q'] . '%';
	$log_params[] = '%' . $log_filters['q'] . '%';
}

$logs       = [];
$pagination = null;
$view_log   = null;

if ( 'logs' === $active_tab ) {
	$pagination = paginate( (int) db_value( 'SELECT COUNT(*) FROM email_logs WHERE ' . implode( ' AND ', $log_where ), $log_params ), 50, (int) input_get( 'page', '1' ) );
	$logs       = db_all(
		'SELECT id, template_slug, recipient_email, subject, status, error_message, sent_at, created_at, body_html <> \'\' AS has_body FROM email_logs WHERE ' . implode( ' AND ', $log_where ) . ' ORDER BY id DESC LIMIT ' . (int) $pagination['per_page'] . ' OFFSET ' . (int) $pagination['offset'],
		$log_params
	);
	$view_log   = '' !== input_get( 'view' ) ? db_row( 'SELECT id, recipient_email, subject, status, created_at FROM email_logs WHERE id = ?', [ (int) input_get( 'view' ) ] ) : null;
}

$digest_companies = 'rules' === $active_tab ? db_all( "SELECT c.id, c.name, u.name AS owner_name FROM companies c INNER JOIN users u ON u.id = c.user_id WHERE u.role = 'user' ORDER BY c.name ASC" ) : [];

$page_title       = 'Email & Șabloane';
$active_nav       = 'emails';
$page_breadcrumbs = [ [ 'Super Admin', app_url( 'admin/index.php' ) ], [ 'Email & Șabloane', '' ] ];
$page_scripts     = [ asset_url( 'js/emails.js' ) ];

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<?php if ( ! empty( $errors ) ) : ?>
	<div class="alert alert-danger">
		<?php foreach ( $errors as $error ) : ?>
			<div><?php echo esc_html( $error ); ?></div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<div class="row g-3 mb-3">
	<div class="col-6 col-xl-3">
		<div class="rm-card rm-card-body h-100">
			<div class="rm-kpi-label"><span class="rm-kpi-icon"><i class="bi bi-send-check"></i></span> Trimise (24 h)</div>
			<div class="rm-kpi-value"><?php echo esc_html( format_number( (int) $stats['sent_24h'] ) ); ?></div>
		</div>
	</div>
	<div class="col-6 col-xl-3">
		<div class="rm-card rm-card-body h-100">
			<div class="rm-kpi-label"><span class="rm-kpi-icon"><i class="bi bi-exclamation-triangle"></i></span> Erori (7 zile)</div>
			<div class="rm-kpi-value<?php echo 0 < (int) $stats['failed_7d'] ? ' text-danger' : ''; ?>"><?php echo esc_html( format_number( (int) $stats['failed_7d'] ) ); ?></div>
			<?php if ( 0 < (int) $stats['failed_7d'] ) : ?>
				<a class="small" href="<?php echo esc_url( app_url( 'admin/emails.php', [ 'tab' => 'logs', 'status' => 'failed' ] ) ); ?>">Vezi erorile <i class="bi bi-arrow-right"></i></a>
			<?php endif; ?>
		</div>
	</div>
	<div class="col-6 col-xl-3">
		<div class="rm-card rm-card-body h-100">
			<div class="rm-kpi-label"><span class="rm-kpi-icon"><i class="bi bi-hourglass-split"></i></span> În coadă</div>
			<div class="rm-kpi-value"><?php echo esc_html( format_number( $queue_count ) ); ?></div>
			<div class="small text-muted">trimise de cron, câte <?php echo esc_html( get_setting_int( 'smtp_batch_limit_per_run', 30 ) ); ?> pe rulare</div>
		</div>
	</div>
	<div class="col-6 col-xl-3">
		<div class="rm-card rm-card-body h-100">
			<div class="rm-kpi-label"><span class="rm-kpi-icon"><i class="bi bi-envelope-check"></i></span> Mod notificări recenzii</div>
			<div class="fw-semibold text-dark mt-1"><?php echo esc_html( get_review_delivery_modes()[ get_global_delivery_mode() ][0] ); ?></div>
			<div class="small text-muted">Driver: <?php echo esc_html( get_mail_drivers()[ get_setting( 'mail_driver', 'log' ) ] ?? get_setting( 'mail_driver', 'log' ) ); ?> · <a href="<?php echo esc_url( app_url( 'admin/settings.php', [ 'tab' => 'email' ] ) ); ?>">SMTP</a></div>
		</div>
	</div>
</div>

<ul class="nav nav-pills rm-tabs gap-1 mb-3">
	<?php foreach ( $tabs as $tab_key => $tab ) : ?>
		<li class="nav-item"><a class="nav-link<?php echo $tab_key === $active_tab ? ' active' : ''; ?>" href="<?php echo esc_url( app_url( 'admin/emails.php', [ 'tab' => $tab_key ] ) ); ?>"<?php echo $tab_key === $active_tab ? ' aria-current="page"' : ''; ?>><i class="bi <?php echo esc_attr( $tab[0] ); ?>"></i> <?php echo esc_html( $tab[1] ); ?></a></li>
	<?php endforeach; ?>
</ul>

<?php if ( 'rules' === $active_tab ) : ?>
	<div class="row g-3">
		<div class="col-xl-7">
			<form method="post" class="rm-card" novalidate>
				<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<input type="hidden" name="action" value="save_rules">
				<div class="rm-card-header"><h2 class="rm-card-title">Mod de notificare pentru recenzii</h2></div>
				<div class="rm-card-body">
					<div class="d-grid gap-2 mb-3">
						<?php foreach ( get_review_delivery_modes() as $mode_key => $mode ) : ?>
							<label class="rm-template-option">
								<input type="radio" class="visually-hidden" name="review_email_delivery_mode" value="<?php echo esc_attr( $mode_key ); ?>"<?php echo $mode_key === get_global_delivery_mode() ? ' checked' : ''; ?>>
								<span class="rm-template-card d-block">
									<span class="fw-semibold text-dark d-block"><?php echo esc_html( $mode[0] ); ?></span>
									<span class="small text-muted"><?php echo esc_html( $mode[1] ); ?></span>
								</span>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="small text-muted">Fiecare proprietar poate alege alt mod pentru o companie anume (Companii → Editează). Feedback-ul blocat de cotă (soft-lock) și emailurile de cont / abonament pleacă întotdeauna imediat.</p>

					<div class="row g-3">
						<div class="col-md-4">
							<label class="form-label" for="daily_digest_hour">Ora rezumatului zilnic</label>
							<select class="form-select" id="daily_digest_hour" name="daily_digest_hour">
								<?php for ( $hour = 0; 23 >= $hour; $hour++ ) : ?>
									<option value="<?php echo esc_attr( $hour ); ?>"<?php echo get_setting_int( 'daily_digest_hour', 8 ) === $hour ? ' selected' : ''; ?>><?php echo esc_html( sprintf( '%02d:00', $hour ) ); ?></option>
								<?php endfor; ?>
							</select>
							<div class="form-text">Primul cron de la această oră trimite rezumatul zilei precedente.</div>
						</div>
						<div class="col-md-4">
							<label class="form-label" for="smtp_batch_limit_per_run">Emailuri pe rulare cron</label>
							<input type="number" min="1" max="500" class="form-control" id="smtp_batch_limit_per_run" name="smtp_batch_limit_per_run" value="<?php echo esc_attr( get_setting_int( 'smtp_batch_limit_per_run', 30 ) ); ?>">
							<div class="form-text">Restul rămâne în coadă pentru rularea următoare.</div>
						</div>
						<div class="col-md-4">
							<label class="form-label" for="smtp_delay_ms">Pauză între emailuri (ms)</label>
							<input type="number" min="0" max="5000" step="50" class="form-control" id="smtp_delay_ms" name="smtp_delay_ms" value="<?php echo esc_attr( get_setting_int( 'smtp_delay_ms', 250 ) ); ?>">
							<div class="form-text">Protejează contul SMTP de blocări pe hosting partajat.</div>
						</div>
					</div>
				</div>
				<div class="rm-card-body border-top"><button type="submit" class="btn btn-dark">Salvează regulile</button></div>
			</form>
		</div>
		<div class="col-xl-5">
			<form method="post" class="rm-card mb-3" novalidate>
				<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<input type="hidden" name="action" value="run_digest">
				<div class="rm-card-header"><h2 class="rm-card-title">Trimite manual rezumatul zilnic</h2></div>
				<div class="rm-card-body">
					<div class="mb-3">
						<label class="form-label" for="digest_company">Companie</label>
						<select class="form-select" id="digest_company" name="company_id" required>
							<?php foreach ( $digest_companies as $digest_company ) : ?>
								<option value="<?php echo esc_attr( (int) $digest_company['id'] ); ?>"><?php echo esc_html( $digest_company['name'] . ' · ' . $digest_company['owner_name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="mb-3">
						<label class="form-label" for="digest_date">Ziua rezumată</label>
						<input type="date" class="form-control" id="digest_date" name="digest_date" value="<?php echo esc_attr( date( 'Y-m-d', strtotime( 'yesterday' ) ) ); ?>" max="<?php echo esc_attr( date( 'Y-m-d', strtotime( 'yesterday' ) ) ); ?>">
					</div>
					<div class="mb-1">
						<div class="form-check">
							<input class="form-check-input" type="radio" name="digest_recipient" id="digest_to_me" value="me" checked>
							<label class="form-check-label" for="digest_to_me">Către mine (<?php echo esc_html( $admin['email'] ); ?>), cu subiectul „[Test]”</label>
						</div>
						<div class="form-check">
							<input class="form-check-input" type="radio" name="digest_recipient" id="digest_to_company" value="company">
							<label class="form-check-label" for="digest_to_company">Către destinatarii companiei</label>
						</div>
					</div>
					<div class="form-text">Trimiterea manuală pleacă și pentru o zi fără evaluări. Automat, o zi fără evaluări nu generează niciun email.</div>
				</div>
				<div class="rm-card-body border-top"><button type="submit" class="btn btn-dark"<?php echo empty( $digest_companies ) ? ' disabled' : ''; ?>><i class="bi bi-send"></i> Trimite rezumatul zilnic acum</button></div>
			</form>

			<div class="rm-card">
				<div class="rm-card-header"><h2 class="rm-card-title">Programare & coadă</h2></div>
				<div class="rm-card-body small">
					<div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Ultimul rezumat automat</span><strong class="text-dark"><?php echo esc_html( '' !== get_setting( 'last_daily_digest_run_date', '' ) ? format_date( get_setting( 'last_daily_digest_run_date' ) ) : '—' ); ?></strong></div>
					<div class="d-flex justify-content-between py-1"><span class="text-muted">Emailuri în coadă</span><strong class="text-dark"><?php echo esc_html( format_number( $queue_count ) ); ?></strong></div>
					<div class="alert rm-alert-info small mt-3 mb-3"><i class="bi bi-clock"></i> Programează <code>cron.php</code> <strong>o dată pe oră</strong> (cPanel → Cron Jobs → „Once Per Hour”). Fiecare sarcină rulează o singură dată pe zi / lună, iar rulările din oră în oră golesc coada.</div>
					<form method="post">
						<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<input type="hidden" name="action" value="run_queue">
						<button type="submit" class="btn btn-sm btn-outline-dark"<?php echo 0 === $queue_count ? ' disabled' : ''; ?>><i class="bi bi-play-circle"></i> Trimite acum coada (<?php echo esc_html( get_setting_int( 'smtp_batch_limit_per_run', 30 ) ); ?> emailuri)</button>
					</form>
				</div>
			</div>
		</div>
	</div>
<?php elseif ( 'templates' === $active_tab ) : ?>
	<?php if ( $editing ) : ?>
		<?php
		$form_subject = $draft ? $draft['subject'] : $editing['subject'];
		$form_body    = $draft ? $draft['body_html'] : $editing['body_html'];
		$is_modified  = $editing['subject'] !== $editing['default_subject'] || $editing['body_html'] !== $editing['default_body_html'];
		?>
		<section class="rm-card mb-3" aria-labelledby="tplEditTitle">
			<div class="rm-card-header">
				<div>
					<h2 class="rm-card-title" id="tplEditTitle"><?php echo esc_html( $editing['name'] ); ?></h2>
					<div class="small text-muted"><code><?php echo esc_html( $editing['slug'] ); ?></code> · <?php echo esc_html( $categories[ $editing['category'] ] ?? $editing['category'] ); ?> · <?php echo esc_html( 1 === (int) $editing['is_enabled'] ? 'PORNIT' : 'OPRIT' ); ?><?php echo $is_modified ? ' · modificat față de textul implicit' : ''; ?></div>
				</div>
				<a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'admin/emails.php', [ 'tab' => 'templates' ] ) ); ?>"><i class="bi bi-x-lg"></i> Închide</a>
			</div>
			<div class="rm-card-body">
				<div class="row g-3">
					<div class="col-xxl-6">
						<form method="post" id="tplForm" novalidate>
							<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<input type="hidden" name="slug" value="<?php echo esc_attr( $editing['slug'] ); ?>">
							<div class="mb-3">
								<label class="form-label" for="tplSubject">Subiect</label>
								<input type="text" class="form-control" id="tplSubject" name="subject" maxlength="255" value="<?php echo esc_attr( $form_subject ); ?>" data-placeholder-target>
							</div>
							<div class="mb-2">
								<label class="form-label" for="tplBody">Conținut HTML <span class="text-muted fw-normal">(fără antet și subsol: se adaugă automat din Aspect global)</span></label>
								<textarea class="form-control font-monospace small rm-code-editor" id="tplBody" name="body_html" rows="20" spellcheck="false" data-placeholder-target><?php echo esc_textarea( $form_body ); ?></textarea>
							</div>
							<div class="mb-3">
								<div class="small text-muted mb-1">Click pe un câmp pentru a-l insera unde se află cursorul:</div>
								<div class="d-flex flex-wrap gap-1">
									<?php foreach ( get_template_placeholders( $editing ) as $placeholder_key => $placeholder_label ) : ?>
										<button type="button" class="rm-placeholder-pill" data-insert-placeholder="<?php echo esc_attr( '{{' . $placeholder_key . '}}' ); ?>" title="<?php echo esc_attr( $placeholder_label ); ?>"><?php echo esc_html( '{{' . $placeholder_key . '}}' ); ?></button>
									<?php endforeach; ?>
								</div>
								<div class="form-text">Câmpurile care se termină în <code>_html</code> conțin tabele generate automat; restul valorilor sunt protejate automat (escape HTML).</div>
							</div>
							<div class="d-flex flex-wrap gap-2">
								<button type="submit" class="btn btn-dark" name="action" value="save_template"><i class="bi bi-check2"></i> Salvează</button>
								<button type="submit" class="btn btn-light" name="action" value="draft" formaction="<?php echo esc_url( app_url( 'admin/email-preview.php' ) ); ?>" formtarget="tplPreviewFrame"><i class="bi bi-eye"></i> Previzualizează modificările</button>
								<button type="submit" class="btn btn-light" name="action" value="test_template" title="Trimite varianta salvată, cu date demo, la <?php echo esc_attr( $admin['email'] ); ?>"><i class="bi bi-send"></i> Trimite test</button>
								<button type="submit" class="btn btn-outline-danger ms-auto" name="action" value="reset_template" data-confirm="Revii la textul implicit din fabrică? Modificările tale la acest șablon se pierd."><i class="bi bi-arrow-counterclockwise"></i> Resetează la textul implicit</button>
							</div>
						</form>
					</div>
					<div class="col-xxl-6">
						<div class="small text-muted mb-1">Previzualizare (varianta salvată, cu date demo). „Previzualizează modificările” arată aici textul nesalvat.</div>
						<iframe name="tplPreviewFrame" class="rm-email-frame" title="Previzualizare email" sandbox src="<?php echo esc_url( app_url( 'admin/email-preview.php', [ 'slug' => $editing['slug'] ] ) ); ?>"></iframe>
					</div>
				</div>
			</div>
		</section>
	<?php elseif ( '' !== input_get( 'preview' ) && get_email_template( input_get( 'preview' ) ) ) : ?>
		<?php $preview_template = get_email_template( input_get( 'preview' ) ); ?>
		<section class="rm-card mb-3">
			<div class="rm-card-header">
				<h2 class="rm-card-title">Previzualizare: <?php echo esc_html( $preview_template['name'] ); ?></h2>
				<div class="d-flex gap-1">
					<a class="btn btn-sm btn-dark" href="<?php echo esc_url( app_url( 'admin/emails.php', [ 'tab' => 'templates', 'edit' => $preview_template['slug'] ] ) ); ?>"><i class="bi bi-pencil"></i> Editează</a>
					<a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'admin/emails.php', [ 'tab' => 'templates' ] ) ); ?>"><i class="bi bi-x-lg"></i> Închide</a>
				</div>
			</div>
			<div class="rm-card-body">
				<iframe class="rm-email-frame" title="Previzualizare email" sandbox src="<?php echo esc_url( app_url( 'admin/email-preview.php', [ 'slug' => $preview_template['slug'] ] ) ); ?>"></iframe>
			</div>
		</section>
	<?php endif; ?>

	<div class="rm-card">
		<div class="table-responsive">
			<table class="table rm-table align-middle">
				<thead>
					<tr>
						<th class="text-nowrap">ON / OFF</th>
						<th>Șablon</th>
						<th>Subiect curent</th>
						<th class="text-end">Acțiuni</th>
					</tr>
				</thead>
				<tbody>
					<?php $current_category = ''; ?>
					<?php foreach ( $templates as $template ) : ?>
						<?php if ( $current_category !== $template['category'] ) : ?>
							<?php $current_category = $template['category']; ?>
							<tr class="rm-table-group"><td colspan="4"><?php echo esc_html( $categories[ $current_category ] ?? $current_category ); ?></td></tr>
						<?php endif; ?>
						<tr>
							<td>
								<form method="post">
									<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<input type="hidden" name="action" value="toggle_template">
									<input type="hidden" name="slug" value="<?php echo esc_attr( $template['slug'] ); ?>">
									<div class="form-check form-switch m-0">
										<input class="form-check-input" type="checkbox" role="switch" name="is_enabled" value="1" aria-label="<?php echo esc_attr( 'Pornește / oprește ' . $template['name'] ); ?>" data-autosubmit<?php echo 1 === (int) $template['is_enabled'] ? ' checked' : ''; ?>>
									</div>
									<noscript><button type="submit" class="btn btn-sm btn-light mt-1">Aplică</button></noscript>
								</form>
							</td>
							<td>
								<div class="fw-semibold text-dark"><?php echo esc_html( $template['name'] ); ?></div>
								<div class="small"><code><?php echo esc_html( $template['slug'] ); ?></code>
									<?php if ( $template['subject'] !== $template['default_subject'] || $template['body_html'] !== $template['default_body_html'] ) : ?>
										<span class="rm-pill rm-pill-neutral ms-1">modificat</span>
									<?php endif; ?>
									<?php if ( 1 !== (int) $template['is_enabled'] ) : ?>
										<span class="rm-pill rm-pill-muted ms-1">oprit</span>
									<?php endif; ?>
								</div>
							</td>
							<td class="small text-muted rm-truncate"><?php echo esc_html( $template['subject'] ); ?></td>
							<td class="text-end text-nowrap">
								<a class="btn btn-sm btn-dark" href="<?php echo esc_url( app_url( 'admin/emails.php', [ 'tab' => 'templates', 'edit' => $template['slug'] ] ) ); ?>"><i class="bi bi-pencil"></i> Editează</a>
								<a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'admin/emails.php', [ 'tab' => 'templates', 'preview' => $template['slug'] ] ) ); ?>" title="Previzualizează"><i class="bi bi-eye"></i></a>
								<form method="post" class="d-inline">
									<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<input type="hidden" name="action" value="test_template">
									<input type="hidden" name="slug" value="<?php echo esc_attr( $template['slug'] ); ?>">
									<button type="submit" class="btn btn-sm btn-light" title="<?php echo esc_attr( 'Trimite test la ' . $admin['email'] ); ?>"><i class="bi bi-send"></i></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
<?php elseif ( 'layout' === $active_tab ) : ?>
	<div class="row g-3">
		<div class="col-xl-5">
			<form method="post" class="rm-card" novalidate>
				<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<input type="hidden" name="action" value="save_layout">
				<div class="rm-card-header"><h2 class="rm-card-title">Antet, culori & subsol</h2></div>
				<div class="rm-card-body">
					<div class="mb-3">
						<label class="form-label" for="email_header_title">Titlu în antet</label>
						<input type="text" class="form-control" id="email_header_title" name="email_header_title" maxlength="120" value="<?php echo esc_attr( $layout['header_title'] ); ?>" placeholder="<?php echo esc_attr( app_name() ); ?>">
						<div class="form-text">Gol = numele aplicației.</div>
					</div>
					<div class="mb-3">
						<label class="form-label" for="email_logo_url">URL logo <span class="text-muted fw-normal">(opțional, înlocuiește titlul)</span></label>
						<input type="url" class="form-control" id="email_logo_url" name="email_logo_url" value="<?php echo esc_attr( $layout['logo_url'] ); ?>" placeholder="https://www.repuvio.ro/logo-email.png">
						<div class="form-text">PNG, înălțime ideală 80 px (afișat la 40 px). Doar https://.</div>
					</div>
					<div class="mb-3">
						<label class="form-label" for="email_accent_color">Culoare de accent</label>
						<div class="d-flex gap-2 align-items-center">
							<input type="color" class="form-control form-control-color" id="email_accent_color" name="email_accent_color" value="<?php echo esc_attr( $layout['accent_color'] ); ?>">
							<span class="small text-muted">Bara de sus și butoanele din emailuri (<code>{{accent_color}}</code>).</span>
						</div>
					</div>
					<div class="mb-3">
						<label class="form-label" for="email_footer_text">Text subsol</label>
						<textarea class="form-control" id="email_footer_text" name="email_footer_text" rows="3" maxlength="1000"><?php echo esc_textarea( $layout['footer_text'] ); ?></textarea>
						<div class="form-text">Poți folosi <code>{{year}}</code>, <code>{{app_name}}</code>, <code>{{provider_name}}</code> și HTML simplu (linkuri). Gol = textul implicit.</div>
					</div>
					<div class="mb-1">
						<label class="form-label" for="email_reply_to">Adresă Reply-To implicită</label>
						<input type="email" class="form-control" id="email_reply_to" name="email_reply_to" value="<?php echo esc_attr( $layout['reply_to'] ); ?>" placeholder="support@repuvio.ro">
						<div class="form-text">Unde ajung răspunsurile la emailurile automate. Alertele de feedback folosesc emailul clientului, dacă l-a lăsat.</div>
					</div>
				</div>
				<div class="rm-card-body border-top"><button type="submit" class="btn btn-dark">Salvează aspectul</button></div>
			</form>
		</div>
		<div class="col-xl-7">
			<div class="rm-card">
				<div class="rm-card-header"><h2 class="rm-card-title">Previzualizare (Bun venit, cu date demo)</h2></div>
				<div class="rm-card-body">
					<iframe class="rm-email-frame" title="Previzualizare aspect email" sandbox src="<?php echo esc_url( app_url( 'admin/email-preview.php', [ 'slug' => 'welcome_trial' ] ) ); ?>"></iframe>
				</div>
			</div>
		</div>
	</div>
<?php else : ?>
	<?php if ( $view_log ) : ?>
		<section class="rm-card mb-3">
			<div class="rm-card-header">
				<h2 class="rm-card-title">Email trimis #<?php echo esc_html( (int) $view_log['id'] ); ?></h2>
				<a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'admin/emails.php', array_filter( array_merge( [ 'tab' => 'logs' ], $log_filters ) ) ) ); ?>"><i class="bi bi-x-lg"></i> Închide</a>
			</div>
			<div class="rm-card-body">
				<iframe class="rm-email-frame" title="Email trimis" sandbox src="<?php echo esc_url( app_url( 'admin/email-preview.php', [ 'log' => (int) $view_log['id'] ] ) ); ?>"></iframe>
			</div>
		</section>
	<?php endif; ?>

	<form method="get" class="rm-card rm-card-body d-flex flex-wrap align-items-end gap-2 mb-3">
		<input type="hidden" name="tab" value="logs">
		<div>
			<label class="form-label small mb-1" for="log_status">Status</label>
			<select class="form-select form-select-sm" id="log_status" name="status" data-autosubmit>
				<option value="">Toate</option>
				<?php foreach ( $statuses as $status_key => $status ) : ?>
					<option value="<?php echo esc_attr( $status_key ); ?>"<?php echo $status_key === $log_filters['status'] ? ' selected' : ''; ?>><?php echo esc_html( $status[0] ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div>
			<label class="form-label small mb-1" for="log_template">Șablon</label>
			<select class="form-select form-select-sm" id="log_template" name="template" data-autosubmit>
				<option value="">Toate</option>
				<?php foreach ( $templates as $template ) : ?>
					<option value="<?php echo esc_attr( $template['slug'] ); ?>"<?php echo $template['slug'] === $log_filters['template'] ? ' selected' : ''; ?>><?php echo esc_html( $template['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div>
			<label class="form-label small mb-1" for="log_q">Caută</label>
			<input type="search" class="form-control form-control-sm" id="log_q" name="q" value="<?php echo esc_attr( $log_filters['q'] ); ?>" placeholder="Destinatar sau subiect">
		</div>
		<button type="submit" class="btn btn-sm btn-dark">Filtrează</button>
		<a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'admin/emails.php', [ 'tab' => 'logs' ] ) ); ?>">Resetează</a>
	</form>

	<div class="rm-card">
		<div class="rm-card-header">
			<h2 class="rm-card-title">Jurnal emailuri <span class="text-muted fw-normal small">(<?php echo esc_html( format_number( $pagination['total'] ) ); ?>)</span></h2>
			<form method="post">
				<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<input type="hidden" name="action" value="purge_logs">
				<button type="submit" class="btn btn-sm btn-light" data-confirm="Ștergi definitiv înregistrările mai vechi de 30 de zile (fără cele din coadă)?"><i class="bi bi-trash"></i> Curăță jurnalul mai vechi de 30 de zile</button>
			</form>
		</div>
		<?php if ( empty( $logs ) ) : ?>
			<div class="rm-empty"><i class="bi bi-inbox"></i><p class="mt-2 mb-0">Niciun email pentru filtrele alese.</p></div>
		<?php else : ?>
			<div class="table-responsive">
				<table class="table rm-table align-middle">
					<thead>
						<tr>
							<th>Data</th>
							<th>Destinatar</th>
							<th>Șablon</th>
							<th>Subiect</th>
							<th>Status</th>
							<th class="text-end">Acțiuni</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td class="small text-nowrap"><?php echo esc_html( format_datetime( $log['created_at'] ) ); ?></td>
								<td class="small"><?php echo esc_html( $log['recipient_email'] ); ?></td>
								<td class="small"><?php echo null !== $log['template_slug'] ? '<code>' . esc_html( $log['template_slug'] ) . '</code>' : '<span class="text-muted">—</span>'; ?></td>
								<td class="small rm-truncate"><?php echo esc_html( $log['subject'] ); ?></td>
								<td>
									<span class="rm-pill <?php echo esc_attr( $statuses[ $log['status'] ][1] ?? 'rm-pill-muted' ); ?>"><?php echo esc_html( $statuses[ $log['status'] ][0] ?? $log['status'] ); ?></span>
									<?php if ( ! empty( $log['error_message'] ) ) : ?>
										<div class="small text-danger mt-1 rm-truncate" title="<?php echo esc_attr( $log['error_message'] ); ?>"><?php echo esc_html( $log['error_message'] ); ?></div>
									<?php endif; ?>
								</td>
								<td class="text-end text-nowrap">
									<?php if ( 1 === (int) $log['has_body'] ) : ?>
										<a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'admin/emails.php', array_filter( array_merge( [ 'tab' => 'logs', 'view' => (int) $log['id'] ], $log_filters ) ) ) ); ?>" title="Vezi emailul trimis"><i class="bi bi-eye"></i></a>
										<form method="post" class="d-inline">
											<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
											<input type="hidden" name="action" value="retry_log">
											<input type="hidden" name="log_id" value="<?php echo esc_attr( (int) $log['id'] ); ?>">
											<input type="hidden" name="return_status" value="<?php echo esc_attr( $log_filters['status'] ); ?>">
											<button type="submit" class="btn btn-sm btn-light" title="Retrimite acum" data-confirm="<?php echo esc_attr( 'Retrimiți acum emailul către ' . $log['recipient_email'] . '?' ); ?>"><i class="bi bi-arrow-repeat"></i></button>
										</form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="rm-card-body border-top">
				<?php render_pagination( $pagination, 'admin/emails.php', array_filter( array_merge( [ 'tab' => 'logs' ], $log_filters ) ) ); ?>
			</div>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php require REPUVIO_ROOT . '/templates/app-footer.php'; ?>
