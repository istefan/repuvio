<?php
/**
 * Feedback & review history CRM with filters (incl. employee / source tag), pagination and CSV export link.
 * Available to owners and operators (operators: assigned companies only, no export).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$user     = require_panel_access();
$is_owner = 'user' === $user['role'];
$owner_id = get_account_owner_id( $user );
$owner    = get_account_owner( $user );

unlock_pending_reviews( $owner_id );

$filters = get_review_filters_from_request();

if ( 0 === $filters['company_id'] && '' === input_get( 'company_id' ) ) {
	$filters['company_id'] = get_current_company_id();
}

if ( 0 < $filters['company_id'] && ! get_owned_company( $filters['company_id'] ) ) {
	$filters['company_id'] = 0;
}

$companies  = get_accessible_companies( $user );
$total      = count_reviews( $owner_id, $filters );
$pagination = paginate( $total, 25, (int) input_get( 'page', '1' ) );
$reviews    = get_reviews( $owner_id, $filters, $pagination['per_page'], $pagination['offset'] );
$history    = get_history_months( $owner_id );
$ref_tags   = [];

foreach ( $companies as $company ) {
	if ( 0 < $filters['company_id'] && (int) $company['id'] !== $filters['company_id'] ) {
		continue;
	}

	foreach ( get_company_ref_tags( (int) $company['id'], false ) as $tag ) {
		$ref_tags[ $tag['slug'] ] = $tag['label'] . ( 0 === $filters['company_id'] && 1 < count( $companies ) ? ' — ' . $company['name'] : '' );
	}
}
$statuses   = get_ticket_statuses();
$actions    = get_review_actions();
$query      = array_filter(
	$filters,
	function ( $value ) {
		return '' !== $value && 0 !== $value;
	}
);

$page_title       = 'Feedback & Recenzii';
$active_nav       = 'reviews';
$page_breadcrumbs = [ [ 'Acasă', app_url( 'dashboard/index.php' ) ], [ 'Feedback & Recenzii', '' ] ];

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<div class="rm-card mb-3">
	<div class="rm-card-body">
		<form method="get" action="<?php echo esc_url( app_url( 'dashboard/reviews.php' ) ); ?>" class="row g-2 align-items-end">
			<div class="col-md-6 col-xl-2">
				<label class="form-label small" for="f-company">Companie</label>
				<select class="form-select form-select-sm" id="f-company" name="company_id">
					<option value="0">Toate</option>
					<?php foreach ( $companies as $company ) : ?>
						<option value="<?php echo esc_attr( $company['id'] ); ?>"<?php echo (int) $company['id'] === $filters['company_id'] ? ' selected' : ''; ?>><?php echo esc_html( $company['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="col-6 col-md-3 col-xl-1">
				<label class="form-label small" for="f-rating">Stele</label>
				<select class="form-select form-select-sm" id="f-rating" name="rating">
					<option value="0">Toate</option>
					<?php for ( $stars = 5; 1 <= $stars; $stars-- ) : ?>
						<option value="<?php echo esc_attr( $stars ); ?>"<?php echo $stars === $filters['rating'] ? ' selected' : ''; ?>><?php echo esc_html( $stars ); ?>★</option>
					<?php endfor; ?>
				</select>
			</div>
			<div class="col-6 col-md-3 col-xl-2">
				<label class="form-label small" for="f-type">Tip</label>
				<select class="form-select form-select-sm" id="f-type" name="type">
					<option value="">Toate</option>
					<?php foreach ( $actions as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"<?php echo $key === $filters['type'] ? ' selected' : ''; ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="col-6 col-md-4 col-xl-1">
				<label class="form-label small" for="f-status">Status</label>
				<select class="form-select form-select-sm" id="f-status" name="status">
					<option value="">Toate</option>
					<?php foreach ( $statuses as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"<?php echo $key === $filters['status'] ? ' selected' : ''; ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="col-6 col-md-4 col-xl-2">
				<label class="form-label small" for="f-from">De la</label>
				<input type="date" class="form-control form-control-sm" id="f-from" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>">
			</div>
			<div class="col-6 col-md-4 col-xl-2">
				<label class="form-label small" for="f-to">Până la</label>
				<input type="date" class="form-control form-control-sm" id="f-to" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>">
			</div>
			<?php if ( ! empty( $ref_tags ) ) : ?>
				<div class="col-6 col-md-4 col-xl-2">
					<label class="form-label small" for="f-ref">Angajat / sursă</label>
					<select class="form-select form-select-sm" id="f-ref" name="ref">
						<option value="">Toate</option>
						<?php foreach ( $ref_tags as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"<?php echo $slug === $filters['ref'] ? ' selected' : ''; ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endif; ?>
			<div class="col-6 col-md-8 col-xl-2">
				<label class="form-label small" for="f-search">Caută</label>
				<input type="search" class="form-control form-control-sm" id="f-search" name="search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="Nume, contact, mesaj">
			</div>
			<div class="col-12 d-flex flex-wrap gap-2 justify-content-end">
				<a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'dashboard/reviews.php', [ 'company_id' => 0 ] ) ); ?>">Resetează</a>
				<?php if ( $is_owner ) : ?>
					<a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'dashboard/export.php', $query ) ); ?>"><i class="bi bi-download"></i> Export CSV</a>
				<?php endif; ?>
				<button type="submit" class="btn btn-sm btn-dark"><i class="bi bi-funnel"></i> Filtrează</button>
			</div>
		</form>
	</div>
</div>

<div class="rm-card">
	<div class="rm-card-header">
		<h2 class="rm-card-title"><?php echo esc_html( format_number( $total ) ); ?> rezultate</h2>
		<?php if ( 0 < $history ) : ?>
			<span class="small text-muted"><i class="bi bi-clock-history"></i> Istoric afișat: ultimele <?php echo esc_html( $history ); ?> luni (<?php echo esc_html( get_user_limits( $owner )['plan_name'] ); ?>)</span>
		<?php endif; ?>
	</div>
	<?php if ( empty( $reviews ) ) : ?>
		<div class="rm-empty"><i class="bi bi-inbox"></i><p class="mt-2 mb-0">Nu există rezultate pentru filtrele selectate.</p></div>
	<?php else : ?>
		<div class="table-responsive">
			<table class="table rm-table">
				<thead>
					<tr>
						<th>Data</th>
						<th>Companie</th>
						<th>Rating</th>
						<th>Tip</th>
						<th>Client</th>
						<th>Mesaj</th>
						<th>Sursă / Etichetă</th>
						<th>Status</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $reviews as $review ) : ?>
						<?php
						$is_feedback = 'private_feedback' === $review['action_taken'];
						$is_locked   = 1 === (int) $review['is_locked'];
						?>
						<tr>
							<td class="text-nowrap small text-muted"><?php echo esc_html( format_datetime( $review['created_at'] ) ); ?></td>
							<td class="text-nowrap"><?php echo esc_html( $review['company_name'] ); ?></td>
							<td><?php echo rating_badge( $review['rating'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper. ?></td>
							<td class="text-nowrap small">
								<?php if ( $is_feedback ) : ?>
									<i class="bi bi-shield-lock text-danger"></i>
								<?php else : ?>
									<i class="bi bi-google"></i>
								<?php endif; ?>
								<?php echo esc_html( $actions[ $review['action_taken'] ] ); ?>
							</td>
							<td class="text-nowrap">
								<?php if ( ! $is_feedback ) : ?>
									<span class="text-muted">—</span>
								<?php elseif ( $is_locked ) : ?>
									<span class="rm-blur-inline"><?php echo esc_html( mask_text( 'Client anonim' ) ); ?></span>
								<?php else : ?>
									<?php echo esc_html( '' !== $review['customer_name'] ? $review['customer_name'] : 'Anonim' ); ?>
									<?php if ( '' !== $review['customer_contact'] ) : ?>
										<div class="small text-muted"><?php echo esc_html( $review['customer_contact'] ); ?></div>
									<?php endif; ?>
								<?php endif; ?>
							</td>
							<td class="rm-truncate">
								<?php if ( ! $is_feedback ) : ?>
									<span class="text-muted small">Trimis către Google</span>
								<?php elseif ( $is_locked ) : ?>
									<span class="rm-pill rm-pill-negative"><i class="bi bi-lock-fill"></i> Blocat — abonament inactiv / cotă atinsă</span>
								<?php else : ?>
									<?php echo esc_html( mb_substr( (string) $review['message'], 0, 120 ) ); ?>
								<?php endif; ?>
							</td>
							<td class="small text-nowrap text-muted">
								<?php echo esc_html( get_source_label( $review['source'] ) ); ?>
								<?php if ( '' !== $review['ref_tag'] ) : ?>
									<div><span class="rm-pill rm-pill-muted"><i class="bi bi-tag"></i> <?php echo esc_html( format_ref_tag( $review['ref_tag'], $review['ref_label'] ) ); ?></span></div>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $is_feedback ) : ?>
									<?php echo ticket_status_badge( $review['ticket_status'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper. ?>
								<?php else : ?>
									<span class="text-muted">—</span>
								<?php endif; ?>
							</td>
							<td class="text-end">
								<?php if ( $is_feedback ) : ?>
									<a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'dashboard/review-view.php', [ 'id' => (int) $review['id'] ] ) ); ?>">Deschide</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="rm-card-body border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
			<span class="small text-muted">Pagina <?php echo esc_html( $pagination['page'] ); ?> din <?php echo esc_html( $pagination['pages'] ); ?></span>
			<?php render_pagination( $pagination, 'dashboard/reviews.php', $query ); ?>
		</div>
	<?php endif; ?>
</div>

<?php
require REPUVIO_ROOT . '/templates/app-footer.php';
