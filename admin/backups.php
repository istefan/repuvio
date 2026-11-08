<?php
/**
 * Super Admin: "Backup Google Drive".
 *
 * Card 1  Google Drive connection (OAuth credentials, connect / disconnect, destination folder).
 * Card 2  Content & schedule: master switch, database / files switches, files scope, frequency,
 *         hour, retention in Drive, keep a local copy.
 * Card 3  Manual run: full backup, database only, direct .sql.gz download in the browser.
 * Card 4  History (backup_logs) with Drive links and delete.
 *
 * Generators, Drive API and scheduler live in includes/google-drive-backup.php.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$admin  = require_super_admin();
$errors = [];

if ( is_post() ) {
	$action = input_post( 'action' );

	if ( 'save_credentials' === $action ) {
		$use_mail    = '1' === input_post( 'gdrive_use_mail_credentials' );
		$folder_name = trim( sanitize_text( input_post( 'gdrive_folder_name' ), 100 ) );
		$folder_name = '' !== $folder_name ? $folder_name : 'ahoi-backups';

		update_setting( 'gdrive_use_mail_credentials', $use_mail ? '1' : '0' );
		update_setting( 'gdrive_client_id', sanitize_text( input_post( 'gdrive_client_id' ), 255 ) );

		if ( '1' === input_post( 'clear_gdrive_client_secret' ) ) {
			update_setting( 'gdrive_client_secret', '' );
		} elseif ( '' !== input_post( 'gdrive_client_secret' ) ) {
			update_setting( 'gdrive_client_secret', mb_substr( input_post( 'gdrive_client_secret' ), 0, 255 ) );
		}

		// A new folder name means a new destination: it is found or created on the next run.
		if ( $folder_name !== get_backup_setting( 'gdrive_folder_name' ) ) {
			update_setting( 'gdrive_folder_name', $folder_name );
			update_setting( 'gdrive_folder_id', '' );

			if ( gdrive_is_connected() ) {
				gdrive_ensure_folder( true );
			}
		}

		audit_log( 'admin_gdrive_credentials', 'mail_credentials=' . ( $use_mail ? 1 : 0 ) );
		flash( 'success', 'Credențialele și folderul au fost salvate.' );
		redirect( app_url( 'admin/backups.php' ) );
	} elseif ( 'ensure_folder' === $action ) {
		$folder = gdrive_ensure_folder( true );

		flash( '' === $folder['error'] ? 'success' : 'danger', '' === $folder['error'] ? ( $folder['created'] ? 'Folderul „' . get_backup_setting( 'gdrive_folder_name' ) . '” a fost creat în Google Drive.' : 'Folderul „' . get_backup_setting( 'gdrive_folder_name' ) . '” există și este folosit pentru backup-uri.' ) : 'Folderul nu a putut fi verificat: ' . $folder['error'] );
		redirect( app_url( 'admin/backups.php' ) );
	} elseif ( 'save_schedule' === $action ) {
		$frequency = input_post( 'gdrive_frequency' );
		$scope     = input_post( 'gdrive_files_scope' );
		$retention = (int) input_post( 'gdrive_retention_count' );

		update_setting( 'gdrive_backup_enabled', '1' === input_post( 'gdrive_backup_enabled' ) ? '1' : '0' );
		update_setting( 'gdrive_backup_db', '1' === input_post( 'gdrive_backup_db' ) ? '1' : '0' );
		update_setting( 'gdrive_backup_files', '1' === input_post( 'gdrive_backup_files' ) ? '1' : '0' );
		update_setting( 'gdrive_files_scope', array_key_exists( $scope, get_backup_file_scopes() ) ? $scope : 'uploads_and_config' );
		update_setting( 'gdrive_frequency', array_key_exists( $frequency, get_backup_frequencies() ) ? $frequency : 'daily' );
		update_setting( 'gdrive_backup_hour', (string) sanitize_int_range( input_post( 'gdrive_backup_hour' ), 0, 23, 3 ) );
		update_setting( 'gdrive_retention_count', (string) ( array_key_exists( $retention, get_backup_retention_options() ) ? $retention : 14 ) );
		update_setting( 'gdrive_keep_local', '1' === input_post( 'gdrive_keep_local' ) ? '1' : '0' );
		update_setting( 'gdrive_next_backup_at', compute_next_backup_at() );

		audit_log( 'admin_gdrive_schedule', 'enabled=' . get_backup_setting( 'gdrive_backup_enabled' ) . ' frequency=' . get_backup_setting( 'gdrive_frequency' ) );

		if ( '1' === get_backup_setting( 'gdrive_backup_enabled' ) && '1' !== get_backup_setting( 'gdrive_backup_db' ) && '1' !== get_backup_setting( 'gdrive_backup_files' ) ) {
			flash( 'warning', 'Programarea este activă, dar nu ai ales nimic de salvat: pornește baza de date și / sau fișierele.' );
		} else {
			flash( 'success', 'Configurarea backup-ului a fost salvată. Următorul backup programat: ' . format_datetime( get_backup_setting( 'gdrive_next_backup_at' ) ) . '.' );
		}

		redirect( app_url( 'admin/backups.php' ) );
	} elseif ( 'run_full' === $action || 'run_db' === $action ) {
		if ( ! gdrive_is_connected() ) {
			flash( 'danger', 'Conectează mai întâi contul Google Drive. Pentru o copie imediată folosește „Descarcă backup SQL direct în browser”.' );
		} else {
			$result = run_backup( 'run_full' === $action ? 'full' : 'db', 'manual' );

			audit_log( 'admin_backup_manual', $action . ' success=' . ( $result['success'] ? 1 : 0 ) );

			if ( $result['success'] ) {
				$summary = [];

				foreach ( $result['results'] as $item ) {
					$summary[] = get_backup_labels()[ $item['type'] ] . ' (' . format_bytes( $item['size'] ) . ')';
				}

				flash( 'success', 'Backup salvat în Google Drive: ' . implode( ', ', $summary ) . '.' );
			} else {
				flash( 'danger', 'Backup-ul nu a reușit: ' . $result['error'] );
			}
		}

		redirect( app_url( 'admin/backups.php' ) );
	} elseif ( 'download_sql' === $action ) {
		stream_database_backup_download();
	} elseif ( 'delete_log' === $action ) {
		if ( delete_backup_log( (int) input_post( 'log_id' ) ) ) {
			audit_log( 'admin_backup_log_deleted', 'log=' . (int) input_post( 'log_id' ) );
			flash( 'success', 'Înregistrarea a fost ștearsă (împreună cu arhiva din Google Drive, dacă mai exista).' );
		}

		redirect( app_url( 'admin/backups.php' ) );
	}
}

$connected   = gdrive_is_connected();
$credentials = gdrive_credentials();
$folder_url  = gdrive_folder_url();
$labels      = get_backup_labels();
$logs        = db_all( 'SELECT * FROM backup_logs ORDER BY id DESC LIMIT 50' );
$last_ok     = db_row( "SELECT * FROM backup_logs WHERE status = 'success' AND destination = 'gdrive' ORDER BY id DESC LIMIT 1" );
$enabled     = '1' === get_backup_setting( 'gdrive_backup_enabled' );
$next_run    = get_backup_setting( 'gdrive_next_backup_at' );
$mail_ready  = '' !== (string) get_setting( 'google_client_id' ) && '' !== (string) get_setting( 'google_client_secret' );

$page_title       = 'Backup Google Drive';
$active_nav       = 'backups';
$page_breadcrumbs = [ [ 'Super Admin', app_url( 'admin/index.php' ) ], [ 'Backup Google Drive', '' ] ];

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<?php if ( $enabled && ! $connected ) : ?>
	<div class="alert rm-alert-danger"><i class="bi bi-exclamation-triangle"></i> Backup-ul automat este pornit, dar contul Google Drive nu este conectat: programarea va eșua și vei primi un email de alertă.</div>
<?php endif; ?>

<div class="row g-3">
	<div class="col-xl-6">
		<section class="rm-card h-100" aria-labelledby="bkConnTitle">
			<div class="rm-card-header">
				<h2 class="rm-card-title" id="bkConnTitle"><i class="bi bi-google"></i> Conexiune Google Drive & folder destinație</h2>
				<?php if ( $connected ) : ?>
					<span class="rm-pill rm-pill-positive"><i class="bi bi-check2-circle"></i> Conectat</span>
				<?php else : ?>
					<span class="rm-pill rm-pill-neutral"><i class="bi bi-plug"></i> Neconectat</span>
				<?php endif; ?>
			</div>
			<div class="rm-card-body">
				<?php if ( $connected ) : ?>
					<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
						<span class="small text-muted">Cont:</span>
						<strong class="text-dark"><?php echo esc_html( '' !== get_backup_setting( 'gdrive_account_email' ) ? get_backup_setting( 'gdrive_account_email' ) : 'cont Google' ); ?></strong>
						<?php if ( '' !== $folder_url ) : ?>
							<a class="rm-pill rm-pill-muted text-decoration-none" href="<?php echo esc_url( $folder_url ); ?>" target="_blank" rel="noopener">📁 <?php echo esc_html( get_backup_setting( 'gdrive_folder_name' ) ); ?> <i class="bi bi-box-arrow-up-right"></i></a>
						<?php else : ?>
							<span class="rm-pill rm-pill-neutral">📁 <?php echo esc_html( get_backup_setting( 'gdrive_folder_name' ) ); ?> · neverificat</span>
						<?php endif; ?>
					</div>
					<p class="small text-muted">Aplicația are acces doar la fișierele și folderele create de ea (permisiunea <code>drive.file</code>), nu și la restul documentelor din Google Drive.</p>
					<div class="d-flex flex-wrap gap-2">
						<form method="post">
							<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<input type="hidden" name="action" value="ensure_folder">
							<button type="submit" class="btn btn-sm btn-light"><i class="bi bi-folder-check"></i> Verifică / recreează folderul <?php echo esc_html( get_backup_setting( 'gdrive_folder_name' ) ); ?></button>
						</form>
						<form method="post" action="<?php echo esc_url( app_url( 'admin/gdrive-oauth.php' ) ); ?>">
							<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<input type="hidden" name="action" value="disconnect">
							<button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Deconectezi contul Google Drive? Backup-urile automate se opresc până la reconectare; arhivele existente rămân în Google Drive."><i class="bi bi-x-circle"></i> Deconectează</button>
						</form>
					</div>
				<?php else : ?>
					<p class="small text-muted">Conectează contul Google în care vrei să păstrezi backup-urile. Folderul <strong><?php echo esc_html( get_backup_setting( 'gdrive_folder_name' ) ); ?></strong> este găsit sau creat automat.</p>
					<form method="post" action="<?php echo esc_url( app_url( 'admin/gdrive-oauth.php' ) ); ?>" class="mb-2">
						<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<input type="hidden" name="action" value="connect">
						<button type="submit" class="btn btn-dark"<?php echo '' === $credentials['client_id'] || '' === $credentials['client_secret'] ? ' disabled' : ''; ?>><i class="bi bi-google"></i> Conectează contul Google Drive</button>
					</form>
					<?php if ( '' === $credentials['client_id'] || '' === $credentials['client_secret'] ) : ?>
						<div class="small text-muted">Completează mai întâi credențialele OAuth de mai jos.</div>
					<?php endif; ?>
				<?php endif; ?>

				<details class="rm-details mt-3"<?php echo $connected ? '' : ' open'; ?>>
					<summary class="fw-semibold text-dark">Credențiale OAuth & folder</summary>
					<form method="post" class="mt-3" novalidate>
						<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<input type="hidden" name="action" value="save_credentials">
						<div class="mb-3">
							<label class="form-label" for="gdrive_redirect">Authorized redirect URI <span class="text-muted fw-normal">(de adăugat în Google Cloud)</span></label>
							<div class="input-group">
								<input type="text" class="form-control font-monospace small" id="gdrive_redirect" value="<?php echo esc_attr( gdrive_redirect_uri() ); ?>" readonly>
								<button type="button" class="btn btn-light" data-copy="#gdrive_redirect"><i class="bi bi-clipboard"></i> Copiază</button>
							</div>
						</div>
						<div class="form-check mb-3">
							<input class="form-check-input" type="checkbox" value="1" id="gdrive_use_mail_credentials" name="gdrive_use_mail_credentials"<?php echo '1' === get_backup_setting( 'gdrive_use_mail_credentials' ) ? ' checked' : ''; ?><?php echo $mail_ready ? '' : ' disabled'; ?>>
							<label class="form-check-label small" for="gdrive_use_mail_credentials">Folosește clientul OAuth Google deja configurat pentru email (Setări → Livrare email)<?php echo $mail_ready ? '' : ' — neconfigurat'; ?>. Adaugă și URI-ul de mai sus la acel client.</label>
						</div>
						<div class="row g-3">
							<div class="col-md-6">
								<label class="form-label" for="gdrive_client_id">Client ID</label>
								<input type="text" class="form-control" id="gdrive_client_id" name="gdrive_client_id" value="<?php echo esc_attr( get_backup_setting( 'gdrive_client_id' ) ); ?>" placeholder="…apps.googleusercontent.com">
							</div>
							<div class="col-md-6">
								<label class="form-label" for="gdrive_client_secret">Client Secret</label>
								<input type="password" class="form-control" id="gdrive_client_secret" name="gdrive_client_secret" autocomplete="new-password" placeholder="<?php echo esc_attr( '' !== get_backup_setting( 'gdrive_client_secret' ) ? '•••••••• (salvat — lasă gol pentru a păstra)' : 'Nesetat' ); ?>">
								<?php if ( '' !== get_backup_setting( 'gdrive_client_secret' ) ) : ?>
									<div class="form-check mt-1">
										<input class="form-check-input" type="checkbox" value="1" id="clear_gdrive_client_secret" name="clear_gdrive_client_secret">
										<label class="form-check-label small" for="clear_gdrive_client_secret">Șterge secretul salvat</label>
									</div>
								<?php endif; ?>
							</div>
							<div class="col-md-6">
								<label class="form-label" for="gdrive_folder_name">Folder în Google Drive</label>
								<input type="text" class="form-control" id="gdrive_folder_name" name="gdrive_folder_name" maxlength="100" value="<?php echo esc_attr( get_backup_setting( 'gdrive_folder_name' ) ); ?>">
							</div>
						</div>
						<div class="d-flex flex-wrap align-items-center gap-2 mt-3">
							<button type="submit" class="btn btn-dark btn-sm">Salvează credențialele</button>
							<button type="button" class="btn btn-link btn-sm" data-bs-toggle="modal" data-bs-target="#rmGdriveHelpModal"><i class="bi bi-question-circle"></i> Cum obțin credențialele din Google Cloud Console în 2 minute?</button>
						</div>
					</form>
				</details>
			</div>
		</section>
	</div>

	<div class="col-xl-6">
		<section class="rm-card h-100" aria-labelledby="bkRunTitle">
			<div class="rm-card-header"><h2 class="rm-card-title" id="bkRunTitle"><i class="bi bi-lightning-charge"></i> Backup acum</h2></div>
			<div class="rm-card-body">
				<div class="row g-2 small mb-3">
					<div class="col-sm-6">
						<div class="rm-backup-stat">
							<div class="text-muted">Ultimul backup în Google Drive</div>
							<div class="fw-semibold text-dark"><?php echo esc_html( $last_ok ? format_datetime( $last_ok['created_at'] ) : '—' ); ?></div>
							<?php if ( 'failed' === get_backup_setting( 'gdrive_last_backup_status' ) ) : ?>
								<div class="text-danger">Ultima încercare a eșuat (<?php echo esc_html( format_datetime( get_backup_setting( 'gdrive_last_backup_at' ) ) ); ?>)</div>
							<?php endif; ?>
						</div>
					</div>
					<div class="col-sm-6">
						<div class="rm-backup-stat">
							<div class="text-muted">Următorul backup programat</div>
							<div class="fw-semibold text-dark"><?php echo esc_html( $enabled && '' !== $next_run ? format_datetime( $next_run ) : 'Programarea este oprită' ); ?></div>
						</div>
					</div>
				</div>
				<div class="d-grid gap-2">
					<form method="post">
						<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<input type="hidden" name="action" value="run_full">
						<button type="submit" class="btn btn-dark w-100" data-confirm="Rulezi acum backup-ul complet (bază de date + fișiere) în Google Drive? Poate dura câteva minute."<?php echo $connected ? '' : ' disabled'; ?>><i class="bi bi-cloud-arrow-up"></i> Rulează backup complet acum (DB + fișiere în Google Drive)</button>
					</form>
					<form method="post">
						<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<input type="hidden" name="action" value="run_db">
						<button type="submit" class="btn btn-light w-100"<?php echo $connected ? '' : ' disabled'; ?>><i class="bi bi-database-up"></i> Doar backup bază de date acum</button>
					</form>
					<form method="post">
						<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<input type="hidden" name="action" value="download_sql">
						<button type="submit" class="btn btn-outline-dark w-100"><i class="bi bi-download"></i> Descarcă backup SQL direct în browser (local)</button>
					</form>
				</div>
				<p class="small text-muted mt-3 mb-0">Descărcarea locală funcționează și fără Google Drive. Arhivele conțin date personale și setări (inclusiv <code>config/</code> la backup-ul de fișiere): păstrează-le doar în locuri private.</p>
			</div>
		</section>
	</div>

	<div class="col-12">
		<form method="post" class="rm-card" novalidate aria-labelledby="bkSchedTitle">
			<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<input type="hidden" name="action" value="save_schedule">
			<div class="rm-card-header"><h2 class="rm-card-title" id="bkSchedTitle"><i class="bi bi-calendar2-check"></i> Conținut & periodicitate backup</h2></div>
			<div class="rm-card-body">
				<div class="form-check form-switch mb-4">
					<input class="form-check-input" type="checkbox" role="switch" id="gdrive_backup_enabled" name="gdrive_backup_enabled" value="1"<?php echo $enabled ? ' checked' : ''; ?>>
					<label class="form-check-label fw-semibold text-dark" for="gdrive_backup_enabled">Activează backup-ul automat programat</label>
				</div>

				<div class="row g-4">
					<div class="col-lg-6">
						<h3 class="h6 text-dark">Ce salvăm în „<?php echo esc_html( get_backup_setting( 'gdrive_folder_name' ) ); ?>”</h3>
						<div class="form-check form-switch mb-1">
							<input class="form-check-input" type="checkbox" role="switch" id="gdrive_backup_db" name="gdrive_backup_db" value="1"<?php echo '1' === get_backup_setting( 'gdrive_backup_db' ) ? ' checked' : ''; ?>>
							<label class="form-check-label fw-semibold text-dark" for="gdrive_backup_db">Salvare bază de date MySQL (.sql.gz)</label>
						</div>
						<p class="small text-muted ms-5">Export complet al tuturor tabelelor: conturi, companii, recenzii, setări.</p>

						<div class="form-check form-switch mb-1">
							<input class="form-check-input" type="checkbox" role="switch" id="gdrive_backup_files" name="gdrive_backup_files" value="1"<?php echo '1' === get_backup_setting( 'gdrive_backup_files' ) ? ' checked' : ''; ?>>
							<label class="form-check-label fw-semibold text-dark" for="gdrive_backup_files">Salvare fișiere aplicație (.zip)</label>
						</div>
						<div class="ms-5 d-grid gap-2">
							<?php foreach ( get_backup_file_scopes() as $scope_key => $scope ) : ?>
								<label class="rm-template-option">
									<input type="radio" class="visually-hidden" name="gdrive_files_scope" value="<?php echo esc_attr( $scope_key ); ?>"<?php echo $scope_key === get_backup_setting( 'gdrive_files_scope' ) ? ' checked' : ''; ?>>
									<span class="rm-template-card d-block">
										<span class="fw-semibold text-dark d-block small"><?php echo esc_html( $scope[0] ); ?></span>
										<span class="small text-muted"><?php echo esc_html( $scope[1] ); ?></span>
									</span>
								</label>
							<?php endforeach; ?>
							<div class="small text-muted">Excluse mereu: backup-urile anterioare (<code>storage/backups</code>), <code>.git</code>, <code>node_modules</code> și fișierele .zip / .gz / .log.<?php echo class_exists( 'ZipArchive' ) ? '' : ' Serverul nu are extensia PHP zip: folosim arhivatorul intern (mai lent, dar compatibil).'; ?></div>
						</div>
					</div>
					<div class="col-lg-6">
						<h3 class="h6 text-dark">Periodicitate</h3>
						<div class="row g-2 mb-3">
							<?php foreach ( get_backup_frequencies() as $frequency_key => $frequency ) : ?>
								<div class="col-sm-6">
									<label class="rm-template-option d-block">
										<input type="radio" class="visually-hidden" name="gdrive_frequency" value="<?php echo esc_attr( $frequency_key ); ?>"<?php echo $frequency_key === get_backup_setting( 'gdrive_frequency' ) ? ' checked' : ''; ?>>
										<span class="rm-template-card d-block h-100">
											<span class="fw-semibold text-dark d-block small"><?php echo esc_html( $frequency[0] ); ?></span>
											<span class="small text-muted"><?php echo esc_html( $frequency[1] ); ?></span>
										</span>
									</label>
								</div>
							<?php endforeach; ?>
						</div>
						<div class="row g-3">
							<div class="col-sm-6">
								<label class="form-label" for="gdrive_backup_hour">Ora preferată</label>
								<select class="form-select" id="gdrive_backup_hour" name="gdrive_backup_hour">
									<?php for ( $hour = 0; 23 >= $hour; $hour++ ) : ?>
										<option value="<?php echo esc_attr( $hour ); ?>"<?php echo (int) get_backup_setting( 'gdrive_backup_hour' ) === $hour ? ' selected' : ''; ?>><?php echo esc_html( sprintf( '%02d:00', $hour ) ); ?></option>
									<?php endfor; ?>
								</select>
								<div class="form-text">Rulează la primul cron de după această oră (cron-ul trebuie programat orar).</div>
							</div>
							<div class="col-sm-6">
								<label class="form-label" for="gdrive_retention_count">Păstrează în Google Drive</label>
								<select class="form-select" id="gdrive_retention_count" name="gdrive_retention_count">
									<?php foreach ( get_backup_retention_options() as $count => $label ) : ?>
										<option value="<?php echo esc_attr( $count ); ?>"<?php echo (int) get_backup_setting( 'gdrive_retention_count' ) === $count ? ' selected' : ''; ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<div class="form-text">Separat pentru baza de date și pentru fișiere; cele mai vechi sunt șterse automat.</div>
							</div>
						</div>
						<div class="form-check form-switch mt-3">
							<input class="form-check-input" type="checkbox" role="switch" id="gdrive_keep_local" name="gdrive_keep_local" value="1"<?php echo '1' === get_backup_setting( 'gdrive_keep_local' ) ? ' checked' : ''; ?>>
							<label class="form-check-label" for="gdrive_keep_local">Păstrează o copie a ultimului backup și local pe server (<code>storage/backups</code>)</label>
						</div>
					</div>
				</div>
			</div>
			<div class="rm-card-body border-top"><button type="submit" class="btn btn-dark">Salvează configurarea</button></div>
		</form>
	</div>

	<div class="col-12">
		<section class="rm-card" aria-labelledby="bkLogTitle">
			<div class="rm-card-header">
				<h2 class="rm-card-title" id="bkLogTitle"><i class="bi bi-clock-history"></i> Jurnal & istoric backup-uri</h2>
				<?php if ( '' !== $folder_url ) : ?>
					<a class="btn btn-sm btn-light" href="<?php echo esc_url( $folder_url ); ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Deschide folderul în Google Drive</a>
				<?php endif; ?>
			</div>
			<?php if ( empty( $logs ) ) : ?>
				<div class="rm-empty"><i class="bi bi-cloud"></i><p class="mt-2 mb-0">Niciun backup încă. Rulează primul backup din „Backup acum”.</p></div>
			<?php else : ?>
				<div class="table-responsive">
					<table class="table rm-table align-middle">
						<thead>
							<tr>
								<th>Data</th>
								<th>Tip</th>
								<th>Declanșare</th>
								<th>Fișier</th>
								<th class="text-end">Dimensiune</th>
								<th>Status</th>
								<th class="text-end">Acțiuni</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $logs as $log ) : ?>
								<tr>
									<td class="small text-nowrap"><?php echo esc_html( format_datetime( $log['created_at'] ) ); ?></td>
									<td class="small"><?php echo esc_html( $labels[ $log['backup_type'] ] ?? $log['backup_type'] ); ?></td>
									<td class="small"><?php echo esc_html( $labels[ $log['trigger_type'] ] ?? $log['trigger_type'] ); ?></td>
									<td class="small rm-truncate"><?php echo '' !== $log['file_name'] ? '<code>' . esc_html( $log['file_name'] ) . '</code>' : '<span class="text-muted">—</span>'; ?></td>
									<td class="small text-end text-nowrap"><?php echo esc_html( 0 < (int) $log['file_size_bytes'] ? format_bytes( (int) $log['file_size_bytes'] ) : '—' ); ?></td>
									<td>
										<?php if ( 'success' === $log['status'] ) : ?>
											<span class="rm-pill rm-pill-positive">Reușit</span>
											<?php if ( 'download' === $log['destination'] ) : ?>
												<div class="small text-muted mt-1">Descărcat în browser</div>
											<?php elseif ( null !== $log['gdrive_deleted_at'] ) : ?>
												<div class="small text-muted mt-1">Șters din Drive (retenție)</div>
											<?php endif; ?>
										<?php elseif ( 'running' === $log['status'] ) : ?>
											<span class="rm-pill rm-pill-trial">În curs</span>
										<?php else : ?>
											<span class="rm-pill rm-pill-negative">Eșuat</span>
											<?php if ( ! empty( $log['error_message'] ) ) : ?>
												<div class="small text-danger mt-1 rm-truncate" title="<?php echo esc_attr( $log['error_message'] ); ?>"><?php echo esc_html( $log['error_message'] ); ?></div>
											<?php endif; ?>
										<?php endif; ?>
									</td>
									<td class="text-end text-nowrap">
										<?php if ( '' !== $log['gdrive_web_link'] && null === $log['gdrive_deleted_at'] ) : ?>
											<a class="btn btn-sm btn-light" href="<?php echo esc_url( $log['gdrive_web_link'] ); ?>" target="_blank" rel="noopener" title="Deschide în Google Drive"><i class="bi bi-google"></i></a>
										<?php endif; ?>
										<form method="post" class="d-inline">
											<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
											<input type="hidden" name="action" value="delete_log">
											<input type="hidden" name="log_id" value="<?php echo esc_attr( (int) $log['id'] ); ?>">
											<button type="submit" class="btn btn-sm btn-light" title="Șterge înregistrarea și arhiva" aria-label="Șterge" data-confirm="Ștergi această înregistrare? Arhiva din Google Drive (dacă mai există) este ștearsă definitiv."><i class="bi bi-trash"></i></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
	</div>
</div>

<div class="modal fade" id="rmGdriveHelpModal" tabindex="-1" aria-labelledby="rmGdriveHelpTitle" aria-hidden="true">
	<div class="modal-dialog modal-lg modal-dialog-scrollable">
		<div class="modal-content">
			<div class="modal-header">
				<h2 class="modal-title h5" id="rmGdriveHelpTitle">Cum obțin credențialele din Google Cloud Console în 2 minute?</h2>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
			</div>
			<div class="modal-body small">
				<ol class="rm-how-list">
					<li>Deschide <strong>console.cloud.google.com</strong> cu contul Google în care vrei backup-urile și creează un proiect (ex: „Backup <?php echo esc_html( app_name() ); ?>”).</li>
					<li>Meniu → <strong>APIs & Services → Library</strong> → caută <strong>Google Drive API</strong> → <strong>Enable</strong>.</li>
					<li><strong>OAuth consent screen</strong>: tip <strong>External</strong>, numele aplicației și emailul tău. La „Scopes” nu trebuie adăugat nimic.</li>
					<li>Important: la „Publishing status” apasă <strong>Publish app</strong> (In production). În modul „Testing”, Google invalidează conexiunea după 7 zile și backup-urile automate se opresc.</li>
					<li><strong>Credentials → Create credentials → OAuth client ID</strong>, tip <strong>Web application</strong>. La „Authorized redirect URIs” lipește:<br><code><?php echo esc_html( gdrive_redirect_uri() ); ?></code></li>
					<li>Copiază <strong>Client ID</strong> și <strong>Client Secret</strong> în formularul de aici, salvează, apoi apasă <strong>Conectează contul Google Drive</strong>.</li>
					<li>Pe ecranul Google poate apărea „Google hasn't verified this app”: apasă <strong>Advanced → Go to …</strong> (este aplicația ta) și permite accesul.</li>
				</ol>
				<div class="alert rm-alert-info small mb-0"><i class="bi bi-shield-check"></i> Permisiunea cerută este <code>drive.file</code>: aplicația vede doar folderul și arhivele create de ea, nu restul fișierelor din Google Drive.</div>
			</div>
		</div>
	</div>
</div>

<?php require REPUVIO_ROOT . '/templates/app-footer.php'; ?>
