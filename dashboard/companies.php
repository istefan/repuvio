<?php
/**
 * Company list (multi-company management): rich cards with cover banner, overlapping logo,
 * threshold / white-label badges, operators count, copy-link bar with QR preview modal,
 * KPI boxes and quick actions. Business owners only.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$user = require_owner();

if ( is_post() && 'delete' === input_post( 'action' ) ) {
	$company = get_owned_company( (int) input_post( 'company_id' ) );

	if ( $company ) {
		delete_company( $company );
		audit_log( 'company_deleted', 'company=' . (int) $company['id'] . ' slug=' . $company['slug'], (int) $user['id'] );
		flash( 'success', 'Compania „' . $company['name'] . '” a fost ștearsă.' );
	} else {
		flash( 'danger', 'Compania nu a fost găsită.' );
	}

	redirect( app_url( 'dashboard/companies.php' ) );
}

$companies       = get_user_companies( (int) $user['id'] );
$limits          = get_user_limits( $user );
$can_add         = count( $companies ) < $limits['max_companies'];
$operator_counts = get_company_operator_counts( (int) $user['id'] );

$stats_rows = db_all(
	"SELECT c.id,
		(SELECT COUNT(*) FROM funnel_views v WHERE v.company_id = c.id) AS views,
		(SELECT COUNT(*) FROM reviews r WHERE r.company_id = c.id AND r.action_taken = 'redirected_google') AS redirects,
		(SELECT COUNT(*) FROM reviews r WHERE r.company_id = c.id AND r.action_taken = 'private_feedback') AS feedback
	FROM companies c WHERE c.user_id = ?",
	[ (int) $user['id'] ]
);
$stats      = array_column( $stats_rows, null, 'id' );

$page_title       = 'Companii';
$active_nav       = 'companies';
$page_breadcrumbs = [ [ 'Acasă', app_url( 'dashboard/index.php' ) ], [ 'Companii', '' ] ];
$page_scripts     = [ 'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js', asset_url( 'js/printables.js' ) ];

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
	<p class="text-muted mb-0">Folosești <strong><?php echo esc_html( count( $companies ) ); ?> din <?php echo esc_html( $limits['max_companies'] ); ?></strong> <?php echo esc_html( 1 === $limits['max_companies'] ? 'locație inclusă' : 'locații incluse' ); ?> în <?php echo esc_html( $limits['plan_name'] ); ?>.</p>
	<?php if ( $can_add ) : ?>
		<a href="<?php echo esc_url( app_url( 'dashboard/company-edit.php' ) ); ?>" class="btn btn-dark"><i class="bi bi-plus-lg"></i> Adaugă companie</a>
	<?php else : ?>
		<a href="<?php echo esc_url( app_url( 'dashboard/billing.php' ) ); ?>" class="btn btn-light"><i class="bi bi-arrow-up-circle"></i> Upgrade pentru mai multe locații</a>
	<?php endif; ?>
</div>

<?php if ( empty( $companies ) ) : ?>
	<div class="rm-card">
		<div class="rm-empty">
			<i class="bi bi-shop"></i>
			<h2 class="h5 mt-3">Nu ai adăugat încă nicio companie</h2>
			<p>Adaugă afacerea ta pentru a primi un link public și coduri QR.</p>
			<a href="<?php echo esc_url( app_url( 'dashboard/company-edit.php' ) ); ?>" class="btn btn-dark"><i class="bi bi-plus-lg"></i> Adaugă companie</a>
		</div>
	</div>
<?php else : ?>
	<div class="row g-4">
		<?php foreach ( $companies as $company ) : ?>
			<?php
			$company_id    = (int) $company['id'];
			$company_stats = $stats[ $company_id ] ?? [
				'views'     => 0,
				'redirects' => 0,
				'feedback'  => 0,
			];
			$public_url    = get_company_public_url( $company );
			$qr_url        = get_company_public_url( $company, 'qr' );
			$google_url    = get_company_google_url( $company );
			$logo_url      = upload_url( $company['logo_path'] );
			$cover_url     = upload_url( $company['cover_path'] );
			$edit_url      = app_url( 'dashboard/company-edit.php', [ 'id' => $company_id ] );
			$operators     = $operator_counts[ $company_id ] ?? 0;
			$description   = trim( (string) $company['description'] );
			?>
			<div class="col-md-6 col-xxl-4">
				<article class="rm-company-card h-100">
					<div class="rm-company-cover<?php echo '' === $cover_url ? ' is-empty' : ''; ?>">
						<?php if ( '' !== $cover_url ) : ?>
							<img src="<?php echo esc_url( $cover_url ); ?>" alt="" loading="lazy">
						<?php else : ?>
							<a class="rm-cover-upload" href="<?php echo esc_url( $edit_url ); ?>"><i class="bi bi-image"></i> Încarcă o copertă</a>
						<?php endif; ?>
						<div class="rm-glass-badges">
							<span class="rm-glass-badge" title="Prag minim pentru redirecționarea către Google"><i class="bi bi-star-fill"></i> Prag: <?php echo esc_html( (int) $company['min_star_threshold'] ); ?>★+ → Google</span>
							<span class="rm-glass-badge" title="Mențiunea „Protejat de” de pe pagina publică">
								<i class="bi <?php echo esc_attr( 1 === (int) $company['white_label_enabled'] ? 'bi-eye-slash' : 'bi-badge-tm' ); ?>"></i>
								<?php echo esc_html( 1 === (int) $company['white_label_enabled'] ? 'White-label: ON' : 'White-label: OFF' ); ?>
							</span>
						</div>
					</div>

					<div class="rm-company-body">
						<div class="d-flex align-items-end gap-3">
							<?php if ( '' !== $logo_url ) : ?>
								<img class="rm-company-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $company['name'] ); ?>" loading="lazy">
							<?php else : ?>
								<span class="rm-company-logo is-empty"><?php echo esc_html( mb_strtoupper( mb_substr( $company['name'], 0, 1 ) ) ); ?></span>
							<?php endif; ?>
							<div class="d-flex flex-wrap gap-1 pb-1">
								<?php if ( 1 !== (int) $company['is_active'] ) : ?>
									<span class="rm-pill rm-pill-muted">Inactivă</span>
								<?php endif; ?>
								<?php if ( '' === $google_url ) : ?>
									<span class="rm-pill rm-pill-negative">Link Google lipsă</span>
								<?php endif; ?>
								<?php if ( '' === $company['tax_id'] ) : ?>
									<a class="rm-pill rm-pill-negative" href="<?php echo esc_url( $edit_url ); ?>">Cod fiscal lipsă</a>
								<?php endif; ?>
							</div>
						</div>

						<h2 class="h5 mt-3 mb-0 text-truncate"><?php echo esc_html( $company['name'] ); ?></h2>
						<div class="small text-muted mb-1"><?php echo esc_html( '' !== $company['tax_id'] ? 'CUI ' . $company['tax_id'] : 'CUI necompletat' ); ?></div>
						<p class="rm-company-desc"><?php echo esc_html( '' !== $description ? $description : 'Fără mesaj de bun venit.' ); ?></p>
						<div class="small text-muted mb-3"><i class="bi bi-headset"></i> <?php echo esc_html( 0 === $operators ? 'Niciun operator alocat' : $operators . ( 1 === $operators ? ' operator activ' : ' operatori activi' ) ); ?></div>

						<div class="rm-link-bar">
							<i class="bi bi-link-45deg text-muted"></i>
							<input type="text" class="rm-link-input" id="url-<?php echo esc_attr( $company_id ); ?>" value="<?php echo esc_attr( $public_url ); ?>" readonly aria-label="Link public">
							<button class="btn btn-sm btn-light" type="button" data-copy="#url-<?php echo esc_attr( $company_id ); ?>" title="Copiază linkul"><i class="bi bi-clipboard"></i></button>
							<button class="btn btn-sm btn-light" type="button" data-bs-toggle="modal" data-bs-target="#qr-<?php echo esc_attr( $company_id ); ?>" title="Previzualizare cod QR"><i class="bi bi-qr-code"></i></button>
							<a class="btn btn-sm btn-light" href="<?php echo esc_url( $public_url ); ?>" target="_blank" rel="noopener" title="Deschide pagina publică"><i class="bi bi-box-arrow-up-right"></i></a>
						</div>

						<div class="row g-2 mt-1">
							<div class="col-4">
								<div class="rm-metric">
									<i class="bi bi-eye"></i>
									<div class="rm-metric-value"><?php echo esc_html( format_number( $company_stats['views'] ) ); ?></div>
									<div class="rm-metric-label">Vizualizări</div>
								</div>
							</div>
							<div class="col-4">
								<div class="rm-metric">
									<i class="bi bi-google text-success"></i>
									<div class="rm-metric-value"><?php echo esc_html( format_number( $company_stats['redirects'] ) ); ?></div>
									<div class="rm-metric-label">Redirecționări Google</div>
								</div>
							</div>
							<div class="col-4">
								<div class="rm-metric">
									<i class="bi bi-shield-lock text-danger"></i>
									<div class="rm-metric-value"><?php echo esc_html( format_number( $company_stats['feedback'] ) ); ?></div>
									<div class="rm-metric-label">Feedback privat</div>
								</div>
							</div>
						</div>
					</div>

					<div class="rm-company-actions">
						<a class="btn btn-sm btn-dark" href="<?php echo esc_url( $edit_url ); ?>"><i class="bi bi-pencil"></i> Editează</a>
						<a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'dashboard/printables.php', [ 'company_id' => $company_id ] ) ); ?>"><i class="bi bi-qr-code"></i> Print</a>
						<a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'dashboard/promote.php', [ 'company_id' => $company_id ] ) ); ?>"><i class="bi bi-window-stack"></i> Widget & Email</a>
						<a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'dashboard/reviews.php', [ 'company_id' => $company_id ] ) ); ?>"><i class="bi bi-chat-square-text"></i> Feedback</a>
						<form method="post" class="ms-auto" data-confirm="Ștergi definitiv compania și tot istoricul ei de recenzii? Acțiunea nu poate fi anulată.">
							<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<input type="hidden" name="action" value="delete">
							<input type="hidden" name="company_id" value="<?php echo esc_attr( $company_id ); ?>">
							<button type="submit" class="btn btn-sm btn-light text-danger" title="Șterge compania" aria-label="Șterge compania"><i class="bi bi-trash"></i></button>
						</form>
					</div>
				</article>
			</div>

			<div class="modal fade" id="qr-<?php echo esc_attr( $company_id ); ?>" tabindex="-1" aria-labelledby="qr-title-<?php echo esc_attr( $company_id ); ?>" aria-hidden="true">
				<div class="modal-dialog modal-dialog-centered modal-sm">
					<div class="modal-content rm-modal">
						<div class="modal-header">
							<h2 class="modal-title h6" id="qr-title-<?php echo esc_attr( $company_id ); ?>"><?php echo esc_html( $company['name'] ); ?></h2>
							<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
						</div>
						<div class="modal-body text-center">
							<div class="rm-qr-preview" data-qr="<?php echo esc_attr( $qr_url ); ?>"></div>
							<div class="small text-muted mt-2 text-break"><?php echo esc_html( $qr_url ); ?></div>
						</div>
						<div class="modal-footer">
							<a class="btn btn-dark btn-sm w-100" href="<?php echo esc_url( app_url( 'dashboard/printables.php', [ 'company_id' => $company_id ] ) ); ?>"><i class="bi bi-printer"></i> Deschide în Print Studio</a>
						</div>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<?php
require REPUVIO_ROOT . '/templates/app-footer.php';
