<?php
/**
 * Physical print upsell ("Comandă Printuri Fizice"): product catalogue, order creation and
 * Super Admin notification. Hidden everywhere while `enable_physical_print_upsell` = 0.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

/**
 * Whether the physical print upsell is visible to business owners.
 *
 * @return bool
 */
function is_print_upsell_enabled() {
	return '1' === get_setting( 'enable_physical_print_upsell', '0' );
}

/**
 * Print products.
 *
 * @param bool $only_active Only active products.
 * @return array
 */
function get_print_products( $only_active = true ) {
	return db_all( 'SELECT * FROM print_products' . ( $only_active ? ' WHERE is_active = 1' : '' ) . ' ORDER BY sort_order ASC, id ASC' );
}

/**
 * One print product.
 *
 * @param int $product_id Product ID.
 * @return array|null
 */
function get_print_product( $product_id ) {
	return 0 < (int) $product_id ? db_row( 'SELECT * FROM print_products WHERE id = ?', [ (int) $product_id ] ) : null;
}

/**
 * Order status labels.
 *
 * @return array
 */
function get_print_order_statuses() {
	return [
		'new'           => 'Nouă',
		'confirmed'     => 'Confirmată',
		'in_production' => 'În producție',
		'shipped'       => 'Expediată',
		'delivered'     => 'Livrată',
		'canceled'      => 'Anulată',
	];
}

/**
 * Items of an order.
 *
 * @param int $order_id Order ID.
 * @return array
 */
function get_print_order_items( $order_id ) {
	return db_all( 'SELECT * FROM print_order_items WHERE order_id = ? ORDER BY id ASC', [ (int) $order_id ] );
}

/**
 * Creates a print order from requested quantities (prices taken from the DB, never from the request).
 *
 * @param array $owner      Owner row.
 * @param int   $company_id Owned company ID or 0.
 * @param array $quantities product_id => quantity.
 * @param array $contact    contact_name, contact_phone, shipping_address, notes.
 * @return array{success:bool,error:string,order_id:int}
 */
function create_print_order( array $owner, $company_id, array $quantities, array $contact ) {
	$lines = [];
	$total = 0.0;

	foreach ( get_print_products( true ) as $product ) {
		$quantity = sanitize_int_range( $quantities[ $product['id'] ] ?? 0, 0, 100, 0 );

		if ( 0 < $quantity ) {
			$lines[] = [
				'product_id' => (int) $product['id'],
				'title'      => $product['title'],
				'unit_price' => (float) $product['price_ron'],
				'quantity'   => $quantity,
			];
			$total  += (float) $product['price_ron'] * $quantity;
		}
	}

	if ( empty( $lines ) ) {
		return [
			'success'  => false,
			'error'    => 'Alege cel puțin un produs.',
			'order_id' => 0,
		];
	}

	if ( 2 > mb_strlen( $contact['contact_name'] ) || 6 > strlen( preg_replace( '/[^0-9]/', '', $contact['contact_phone'] ) ) || 10 > mb_strlen( $contact['shipping_address'] ) ) {
		return [
			'success'  => false,
			'error'    => 'Completează numele, telefonul și adresa completă de livrare.',
			'order_id' => 0,
		];
	}

	$order_id = db_transaction(
		function () use ( $owner, $company_id, $lines, $total, $contact ) {
			db_query(
				'INSERT INTO print_orders (user_id, company_id, total_ron, contact_name, contact_phone, shipping_address, notes) VALUES (?, ?, ?, ?, ?, ?, ?)',
				[
					(int) $owner['id'],
					0 < (int) $company_id ? (int) $company_id : null,
					number_format( $total, 2, '.', '' ),
					$contact['contact_name'],
					$contact['contact_phone'],
					$contact['shipping_address'],
					$contact['notes'],
				]
			);

			$order_id = db_insert_id();

			foreach ( $lines as $line ) {
				db_query(
					'INSERT INTO print_order_items (order_id, product_id, title, unit_price_ron, quantity) VALUES (?, ?, ?, ?, ?)',
					[ $order_id, $line['product_id'], $line['title'], number_format( $line['unit_price'], 2, '.', '' ), $line['quantity'] ]
				);
			}

			return $order_id;
		}
	);

	send_print_order_admin_email( $owner, (int) $order_id );

	return [
		'success'  => true,
		'error'    => '',
		'order_id' => (int) $order_id,
	];
}

/**
 * Notifies the Super Admin(s) about a new print order through the `admin_contact_or_order` template.
 *
 * @param array $owner    Owner row.
 * @param int   $order_id Order ID.
 * @return array send_templated_email() result.
 */
function send_print_order_admin_email( array $owner, $order_id ) {
	$order = db_row(
		'SELECT o.*, c.name AS company_name, c.tax_id AS company_tax_id FROM print_orders o LEFT JOIN companies c ON c.id = o.company_id WHERE o.id = ?',
		[ (int) $order_id ]
	);
	$rows  = '';

	foreach ( get_print_order_items( (int) $order_id ) as $item ) {
		$rows .= '<tr><td style="border-top:1px solid #E2E8F0;">' . esc_html( $item['title'] ) . '</td>'
			. '<td align="right" style="border-top:1px solid #E2E8F0;">' . esc_html( (int) $item['quantity'] ) . ' ×</td>'
			. '<td align="right" style="border-top:1px solid #E2E8F0;">' . esc_html( format_ron( $item['unit_price_ron'] ) ) . '</td></tr>';
	}

	$details = '<p style="margin-top:14px;">Comanda de printuri fizice <strong>#' . esc_html( (int) $order_id ) . '</strong>'
		. ( ! empty( $order['company_name'] ) ? ' pentru <strong>' . esc_html( $order['company_name'] ) . '</strong>' : '' ) . ':</p>'
		. '<table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="border:1px solid #E2E8F0;border-radius:10px;font-size:13px;border-collapse:separate;">'
		. $rows
		. '<tr><td colspan="2" style="border-top:1px solid #E2E8F0;font-weight:700;">Total</td><td align="right" style="border-top:1px solid #E2E8F0;font-weight:700;">' . esc_html( format_ron( $order['total_ron'] ) ) . '</td></tr>'
		. '</table>'
		. ( ! empty( $order['company_tax_id'] ) ? '<p style="margin-top:14px;"><strong>Facturare:</strong> ' . esc_html( $order['company_name'] ) . ' · CUI ' . esc_html( $order['company_tax_id'] ) . '</p>' : '' )
		. '<p style="margin-top:14px;"><strong>Contact livrare:</strong> ' . esc_html( $order['contact_name'] ) . ', ' . esc_html( $order['contact_phone'] ) . '<br>'
		. '<strong>Adresă:</strong> ' . nl2br( esc_html( $order['shipping_address'] ) ) . '</p>'
		. ( '' !== $order['notes'] ? '<p><strong>Observații:</strong> ' . nl2br( esc_html( $order['notes'] ) ) . '</p>' : '' );

	return send_admin_notification(
		'Comandă printuri #' . (int) $order_id,
		[
			'name'  => $owner['name'],
			'email' => $owner['email'],
			'phone' => (string) $order['contact_phone'],
		],
		$details,
		app_url( 'admin/print-products.php', [ 'tab' => 'orders' ] )
	);
}
