<?php
/**
 * Super Admin: physical print upsell — master toggle status, product / bundle catalogue CRUD
 * (title, description, price in RON, order, visibility) and incoming order management.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

require_super_admin();

$active_tab = 'orders' === input_get( 'tab' ) ? 'orders' : 'products';
$statuses   = get_print_order_statuses();
$errors     = [];
$edit_id    = sanitize_int_range( is_post() ? input_post( 'product_id', '0' ) : input_get( 'edit', '0' ), 0, PHP_INT_MAX, 0 );
$editing    = get_print_product( $edit_id );
$form       = $editing ? $editing : [
	'title'       => '',
	'description' => '',
	'price_ron'   => '0.00',
	'is_active'   => 1,
	'sort_order'  => 0,
];

if ( is_post() ) {
	$action = input_post( 'action' );

	if ( 'save_product' === $action ) {
		$form['title']       = sanitize_text( input_post( 'title' ), 150 );
		$form['description'] = sanitize_text( input_post( 'description' ), 500 );
		$form['price_ron']   = number_format( max( 0, (float) str_replace( ',', '.', input_post( 'price_ron', '0' ) ) ), 2, '.', '' );
		$form['is_active']   = '1' === input_post( 'is_active' ) ? 1 : 0;
		$form['sort_order']  = sanitize_int_range( input_post( 'sort_order', '0' ), -1000, 1000, 0 );

		if ( 2 > mb_strlen( $form['title'] ) ) {
			$errors[] = 'Introdu denumirea produsului.';
		}

		if ( empty( $errors ) ) {
			$params = [ $form['title'], $form['description'], $form['price_ron'], $form['is_active'], $form['sort_order'] ];

			if ( $editing ) {
				db_query( 'UPDATE print_products SET title = ?, description = ?, price_ron = ?, is_active = ?, sort_order = ? WHERE id = ?', array_merge( $params, [ (int) $editing['id'] ] ) );
			} else {
				db_query( 'INSERT INTO print_products (title, description, price_ron, is_active, sort_order) VALUES (?, ?, ?, ?, ?)', $params );
			}

			audit_log( 'admin_print_product_saved', 'title=' . $form['title'] . ' price=' . $form['price_ron'] );
			flash( 'success', 'Produsul a fost salvat.' );
			redirect( app_url( 'admin/print-products.php' ) );
		}
	} elseif ( 'delete_product' === $action && $editing ) {
		db_query( 'DELETE FROM print_products WHERE id = ?', [ (int) $editing['id'] ] );
		audit_log( 'admin_print_product_deleted', 'id=' . (int) $editing['id'] );
		flash( 'success', 'Produsul a fost șters. Comenzile existente își păstrează denumirea și prețul.' );
		redirect( app_url( 'admin/print-products.php' ) );
	} elseif ( 'order_status' === $action ) {
		$status = input_post( 'status' );

		if ( array_key_exists( $status, $statuses ) ) {
			db_query( 'UPDATE print_orders SET status = ? WHERE id = ?', [ $status, (int) input_post( 'order_id' ) ] );
			audit_log( 'admin_print_order_status', 'order=' . (int) input_post( 'order_id' ) . ' status=' . $status );
			flash( 'success', 'Statusul comenzii a fost actualizat.' );
		}

		redirect( app_url( 'admin/print-products.php', [ 'tab' => 'orders' ] ) );
	}
}

$products = get_print_products( false );
$orders   = db_all(
	'SELECT o.*, u.name AS user_name, u.email AS user_email, c.name AS company_name, c.tax_id AS company_tax_id
	FROM print_orders o INNER JOIN users u ON u.id = o.user_id LEFT JOIN companies c ON c.id = o.company_id
	ORDER BY o.created_at DESC LIMIT 100'
);

$page_title       = 'Printuri fizice';
$active_nav       = 'print_admin';
$page_breadcrumbs = [ [ 'Super Admin', app_url( 'admin/index.php' ) ], [ 'Printuri fizice', '' ] ];

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<?php if ( ! empty( $errors ) ) : ?>
	<div class="alert alert-danger">
		<?php foreach ( $errors as $error ) : ?>
			<div><?php echo esc_html( $error ); ?></div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<div class="alert <?php echo esc_attr( is_print_upsell_enabled() ? 'alert-success' : 'rm-alert-info' ); ?> d-flex flex-wrap justify-content-between align-items-center gap-2">
	<div>
		<i class="bi <?php echo esc_attr( is_print_upsell_enabled() ? 'bi-eye' : 'bi-eye-slash' ); ?>"></i>
		Modulul „Comandă Printuri Fizice” este <strong><?php echo esc_html( is_print_upsell_enabled() ? 'Activat' : 'Ascuns' ); ?></strong> pentru Business Owners.
	</div>
	<a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'admin/settings.php', [ 'tab' => 'features' ] ) ); ?>">Schimbă din Setări</a>
</div>

<ul class="nav nav-pills rm-tabs gap-1 mb-3" role="tablist">
	<li class="nav-item" role="presentation"><button class="nav-link<?php echo 'products' === $active_tab ? ' active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#tab-products" type="button" role="tab">Produse & prețuri</button></li>
	<li class="nav-item" role="presentation"><button class="nav-link<?php echo 'orders' === $active_tab ? ' active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#tab-orders" type="button" role="tab">Comenzi (<?php echo esc_html( count( $orders ) ); ?>)</button></li>
</ul>

<div class="tab-content">
	<div class="tab-pane fade<?php echo 'products' === $active_tab ? ' show active' : ''; ?>" id="tab-products" role="tabpanel">
		<div class="row g-3">
			<div class="col-xl-7">
				<div class="rm-card">
					<div class="table-responsive">
						<table class="table rm-table">
							<thead><tr><th>Produs / pachet</th><th class="text-end">Preț</th><th>Stare</th><th></th></tr></thead>
							<tbody>
								<?php foreach ( $products as $product ) : ?>
									<tr>
										<td><div class="fw-medium text-dark"><?php echo esc_html( $product['title'] ); ?></div><div class="small text-muted"><?php echo esc_html( $product['description'] ); ?></div></td>
										<td class="text-end fw-semibold text-nowrap"><?php echo esc_html( format_ron( $product['price_ron'] ) ); ?></td>
										<td><span class="rm-pill <?php echo esc_attr( 1 === (int) $product['is_active'] ? 'rm-pill-positive' : 'rm-pill-muted' ); ?>"><?php echo esc_html( 1 === (int) $product['is_active'] ? 'Vizibil' : 'Ascuns' ); ?></span></td>
										<td class="text-end"><a class="btn btn-sm btn-light" href="<?php echo esc_url( app_url( 'admin/print-products.php', [ 'edit' => (int) $product['id'] ] ) ); ?>"><i class="bi bi-pencil"></i></a></td>
									</tr>
								<?php endforeach; ?>
								<?php if ( empty( $products ) ) : ?>
									<tr><td colspan="4" class="text-center text-muted py-4">Niciun produs.</td></tr>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
			<div class="col-xl-5">
				<form method="post" class="rm-card" novalidate>
					<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<input type="hidden" name="action" value="save_product">
					<input type="hidden" name="product_id" value="<?php echo esc_attr( $editing ? $editing['id'] : 0 ); ?>">
					<div class="rm-card-header"><h2 class="rm-card-title"><?php echo esc_html( $editing ? 'Editează produsul' : 'Produs / pachet nou' ); ?></h2></div>
					<div class="rm-card-body">
						<div class="mb-3">
							<label class="form-label" for="title">Denumire</label>
							<input type="text" class="form-control" id="title" name="title" value="<?php echo esc_attr( $form['title'] ); ?>" maxlength="150" required>
						</div>
						<div class="mb-3">
							<label class="form-label" for="description">Descriere</label>
							<textarea class="form-control" id="description" name="description" rows="3" maxlength="500"><?php echo esc_textarea( $form['description'] ); ?></textarea>
						</div>
						<div class="row g-2 mb-3">
							<div class="col-6">
								<label class="form-label" for="price_ron">Preț (RON)</label>
								<input type="text" inputmode="decimal" class="form-control" id="price_ron" name="price_ron" value="<?php echo esc_attr( $form['price_ron'] ); ?>" required>
							</div>
							<div class="col-6">
								<label class="form-label" for="sort_order">Ordine</label>
								<input type="number" class="form-control" id="sort_order" name="sort_order" value="<?php echo esc_attr( $form['sort_order'] ); ?>">
							</div>
						</div>
						<div class="form-check form-switch">
							<input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"<?php echo 1 === (int) $form['is_active'] ? ' checked' : ''; ?>>
							<label class="form-check-label" for="is_active">Vizibil în formularul de comandă</label>
						</div>
					</div>
					<div class="rm-card-body border-top d-flex gap-2">
						<button type="submit" class="btn btn-dark">Salvează</button>
						<?php if ( $editing ) : ?>
							<a class="btn btn-light" href="<?php echo esc_url( app_url( 'admin/print-products.php' ) ); ?>">Renunță</a>
						<?php endif; ?>
					</div>
				</form>
				<?php if ( $editing ) : ?>
					<form method="post" class="rm-card rm-card-body mt-3 d-flex justify-content-between align-items-center" data-confirm="Ștergi produsul din catalog?">
						<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<input type="hidden" name="action" value="delete_product">
						<input type="hidden" name="product_id" value="<?php echo esc_attr( $editing['id'] ); ?>">
						<span class="small text-muted">Șterge produsul din catalog</span>
						<button type="submit" class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i> Șterge</button>
					</form>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<div class="tab-pane fade<?php echo 'orders' === $active_tab ? ' show active' : ''; ?>" id="tab-orders" role="tabpanel">
		<div class="rm-card">
			<?php if ( empty( $orders ) ) : ?>
				<div class="rm-empty"><i class="bi bi-box-seam"></i><p class="mt-2 mb-0">Nicio comandă încă.</p></div>
			<?php else : ?>
				<div class="table-responsive">
					<table class="table rm-table">
						<thead><tr><th>#</th><th>Data</th><th>Client</th><th>Produse</th><th>Livrare</th><th class="text-end">Total</th><th>Status</th></tr></thead>
						<tbody>
							<?php foreach ( $orders as $order ) : ?>
								<tr>
									<td class="fw-semibold">#<?php echo esc_html( (int) $order['id'] ); ?></td>
									<td class="small text-nowrap"><?php echo esc_html( format_datetime( $order['created_at'] ) ); ?></td>
									<td class="small">
										<a href="<?php echo esc_url( app_url( 'admin/user-edit.php', [ 'id' => (int) $order['user_id'] ] ) ); ?>"><?php echo esc_html( $order['user_name'] ); ?></a>
										<div class="text-muted"><?php echo esc_html( ( $order['company_name'] ?? '—' ) . ( ! empty( $order['company_tax_id'] ) ? ' · CUI ' . $order['company_tax_id'] : '' ) ); ?></div>
									</td>
									<td class="small">
										<?php foreach ( get_print_order_items( (int) $order['id'] ) as $item ) : ?>
											<div><?php echo esc_html( (int) $item['quantity'] . ' × ' . $item['title'] ); ?></div>
										<?php endforeach; ?>
									</td>
									<td class="small">
										<?php echo esc_html( $order['contact_name'] . ' · ' . $order['contact_phone'] ); ?>
										<div class="text-muted"><?php echo nl2br( esc_html( $order['shipping_address'] ) ); ?></div>
										<?php if ( '' !== $order['notes'] ) : ?>
											<div class="text-muted fst-italic"><?php echo esc_html( $order['notes'] ); ?></div>
										<?php endif; ?>
									</td>
									<td class="text-end fw-semibold text-nowrap"><?php echo esc_html( format_ron( $order['total_ron'] ) ); ?></td>
									<td>
										<form method="post" class="d-flex gap-1">
											<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
											<input type="hidden" name="action" value="order_status">
											<input type="hidden" name="order_id" value="<?php echo esc_attr( $order['id'] ); ?>">
											<select class="form-select form-select-sm" name="status" data-autosubmit aria-label="Status comandă">
												<?php foreach ( $statuses as $key => $label ) : ?>
													<option value="<?php echo esc_attr( $key ); ?>"<?php echo $key === $order['status'] ? ' selected' : ''; ?>><?php echo esc_html( $label ); ?></option>
												<?php endforeach; ?>
											</select>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>

<?php
require REPUVIO_ROOT . '/templates/app-footer.php';
