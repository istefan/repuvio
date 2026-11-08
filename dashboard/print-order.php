<?php
/**
 * "Comandă Printuri Fizice": business owners order ready-made printed materials (stands, flyers,
 * business cards, stickers, onboarding bundle). Only reachable while the Super Admin has
 * enabled `enable_physical_print_upsell`. Orders are stored in print_orders and emailed to the admin.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$user = require_owner();

if ( ! is_print_upsell_enabled() ) {
	render_error_page( 404, 'Pagină indisponibilă', 'Comanda de printuri fizice nu este disponibilă momentan.' );
}

$companies  = get_user_companies( (int) $user['id'] );
$products   = get_print_products( true );
$statuses   = get_print_order_statuses();
$errors     = [];
$company_id = sanitize_int_range( input_get( 'company_id', (string) get_current_company_id() ), 0, PHP_INT_MAX, 0 );
$form       = [
	'company_id'       => $company_id,
	'quantities'       => [],
	'contact_name'     => $user['name'],
	'contact_phone'    => '',
	'shipping_address' => '',
	'notes'            => '',
];

if ( is_post() ) {
	$form['company_id']       = sanitize_int_range( input_post( 'company_id', '0' ), 0, PHP_INT_MAX, 0 );
	$form['quantities']       = array_map( 'intval', (array) ( $_POST['qty'] ?? [] ) );
	$form['contact_name']     = sanitize_text( input_post( 'contact_name' ), 150 );
	$form['contact_phone']    = sanitize_text( input_post( 'contact_phone' ), 50 );
	$form['shipping_address'] = sanitize_textarea( input_post( 'shipping_address' ), 500 );
	$form['notes']            = sanitize_textarea( input_post( 'notes' ), 1000 );
	$company                  = 0 < $form['company_id'] ? get_owned_company( $form['company_id'] ) : null;

	if ( rate_limit_is_locked( 'print_order' ) ) {
		$errors[] = 'Ai trimis deja mai multe comenzi. Te contactăm în curând.';
	} elseif ( is_showcase_user( $user ) ) {
		$errors[] = 'Cont demo: comanda nu a fost trimisă. Într-un cont real o primim imediat și te contactăm pentru confirmare.';
	} elseif ( ! $company ) {
		$errors[] = 'Alege compania pentru care se emite factura.';
	} elseif ( '' === $company['tax_id'] ) {
		$errors[] = 'Compania „' . $company['name'] . '” nu are cod fiscal (CUI / CIF), necesar pentru facturare. Completează-l din Companii → Editează.';
	} else {
		$result = create_print_order( $user, $company ? (int) $company['id'] : 0, $form['quantities'], $form );

		if ( $result['success'] ) {
			rate_limit_hit( 'print_order', 5, 3600, 3600 );
			audit_log( 'print_order_created', 'order=' . $result['order_id'] );
			flash( 'success', 'Comanda #' . $result['order_id'] . ' a fost trimisă. Te contactăm pentru confirmare, factură și termenul de livrare.' );
			redirect( app_url( 'dashboard/print-order.php' ) );
		}

		$errors[] = $result['error'];
	}
}

$orders = db_all( 'SELECT o.*, c.name AS company_name FROM print_orders o LEFT JOIN companies c ON c.id = o.company_id WHERE o.user_id = ? ORDER BY o.created_at DESC LIMIT 20', [ (int) $user['id'] ] );

$page_title       = 'Comandă printuri';
$active_nav       = 'print_order';
$page_breadcrumbs = [ [ 'Acasă', app_url( 'dashboard/index.php' ) ], [ 'Print Studio', app_url( 'dashboard/printables.php' ) ], [ 'Comandă printuri', '' ] ];

require REPUVIO_ROOT . '/templates/app-header.php';
?>

<?php if ( ! empty( $errors ) ) : ?>
	<div class="alert alert-danger">
		<?php foreach ( $errors as $error ) : ?>
			<div><?php echo esc_html( $error ); ?></div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<form method="post" class="row g-3" data-order-form novalidate>
	<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<div class="col-xl-7">
		<div class="rm-card">
			<div class="rm-card-header"><h2 class="rm-card-title">Alege materialele</h2><span class="small text-muted">Personalizate cu logo-ul și codul tău QR</span></div>
			<div class="rm-card-body">
				<?php foreach ( $products as $product ) : ?>
					<div class="d-flex align-items-center gap-3 py-2 border-bottom">
						<div class="flex-grow-1 min-w-0">
							<div class="fw-semibold text-dark"><?php echo esc_html( $product['title'] ); ?></div>
							<div class="small text-muted"><?php echo esc_html( $product['description'] ); ?></div>
						</div>
						<div class="fw-semibold text-nowrap"><?php echo esc_html( format_ron( $product['price_ron'] ) ); ?></div>
						<input type="number" min="0" max="100" class="form-control form-control-sm" style="width: 80px;" name="qty[<?php echo esc_attr( $product['id'] ); ?>]" value="<?php echo esc_attr( (int) ( $form['quantities'][ $product['id'] ] ?? 0 ) ); ?>" data-order-qty data-price="<?php echo esc_attr( $product['price_ron'] ); ?>" aria-label="<?php echo esc_attr( 'Cantitate ' . $product['title'] ); ?>">
					</div>
				<?php endforeach; ?>
				<?php if ( empty( $products ) ) : ?>
					<p class="text-muted mb-0">Catalogul este gol momentan.</p>
				<?php endif; ?>
				<div class="d-flex justify-content-between align-items-center pt-3">
					<span class="text-muted">Total estimat (fără TVA, dacă este cazul)</span>
					<span class="h5 mb-0" data-order-total>0,00 RON</span>
				</div>
			</div>
		</div>
	</div>
	<div class="col-xl-5">
		<div class="rm-card">
			<div class="rm-card-header"><h2 class="rm-card-title">Livrare</h2></div>
			<div class="rm-card-body">
				<div class="mb-3">
					<label class="form-label" for="company_id">Pentru compania</label>
					<select class="form-select" id="company_id" name="company_id">
						<?php foreach ( $companies as $company_option ) : ?>
							<option value="<?php echo esc_attr( $company_option['id'] ); ?>"<?php echo (int) $company_option['id'] === $form['company_id'] ? ' selected' : ''; ?>><?php echo esc_html( $company_option['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="row g-2 mb-3">
					<div class="col-sm-6">
						<label class="form-label" for="contact_name">Persoană de contact</label>
						<input type="text" class="form-control" id="contact_name" name="contact_name" value="<?php echo esc_attr( $form['contact_name'] ); ?>" maxlength="150" required>
					</div>
					<div class="col-sm-6">
						<label class="form-label" for="contact_phone">Telefon</label>
						<input type="tel" class="form-control" id="contact_phone" name="contact_phone" value="<?php echo esc_attr( $form['contact_phone'] ); ?>" maxlength="50" required>
					</div>
				</div>
				<div class="mb-3">
					<label class="form-label" for="shipping_address">Adresă de livrare</label>
					<textarea class="form-control" id="shipping_address" name="shipping_address" rows="3" maxlength="500" required><?php echo esc_textarea( $form['shipping_address'] ); ?></textarea>
				</div>
				<div class="mb-3">
					<label class="form-label" for="notes">Observații (opțional)</label>
					<textarea class="form-control" id="notes" name="notes" rows="2" maxlength="1000" placeholder="Ex: etichete pentru Chelner Mihai și Recepție"><?php echo esc_textarea( $form['notes'] ); ?></textarea>
				</div>
				<button type="submit" class="btn btn-dark w-100"><i class="bi bi-send"></i> Trimite comanda</button>
				<div class="small text-muted mt-2">Nu se face nicio plată acum. Primești confirmarea și factura proformă pe email.</div>
			</div>
		</div>
	</div>
</form>

<?php if ( ! empty( $orders ) ) : ?>
	<div class="rm-card mt-3">
		<div class="rm-card-header"><h2 class="rm-card-title">Comenzile tale</h2></div>
		<div class="table-responsive">
			<table class="table rm-table">
				<thead><tr><th>#</th><th>Data</th><th>Companie</th><th>Produse</th><th class="text-end">Total</th><th>Status</th></tr></thead>
				<tbody>
					<?php foreach ( $orders as $order ) : ?>
						<tr>
							<td class="fw-semibold">#<?php echo esc_html( (int) $order['id'] ); ?></td>
							<td class="small text-nowrap"><?php echo esc_html( format_datetime( $order['created_at'] ) ); ?></td>
							<td class="small"><?php echo esc_html( $order['company_name'] ?? '—' ); ?></td>
							<td class="small">
								<?php foreach ( get_print_order_items( (int) $order['id'] ) as $item ) : ?>
									<div><?php echo esc_html( (int) $item['quantity'] . ' × ' . $item['title'] ); ?></div>
								<?php endforeach; ?>
							</td>
							<td class="text-end fw-semibold text-nowrap"><?php echo esc_html( format_ron( $order['total_ron'] ) ); ?></td>
							<td><span class="rm-pill rm-pill-muted"><?php echo esc_html( $statuses[ $order['status'] ] ?? $order['status'] ); ?></span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
<?php endif; ?>

<?php
require REPUVIO_ROOT . '/templates/app-footer.php';
