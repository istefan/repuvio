<?php
/**
 * CSV export of filtered reviews (tenant-scoped, locked content masked, formula-injection safe).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

$user    = require_owner();
$filters = get_review_filters_from_request();

if ( 0 < $filters['company_id'] && ! get_owned_company( $filters['company_id'] ) ) {
	$filters['company_id'] = 0;
}

/**
 * Neutralizes spreadsheet formula injection.
 *
 * @param mixed $value Cell value.
 * @return string
 */
function csv_safe_cell( $value ) {
	$value = (string) $value;

	if ( '' !== $value && in_array( $value[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
		$value = "'" . $value;
	}

	return $value;
}

$statuses = get_ticket_statuses();
$actions  = get_review_actions();
$filename = 'feedback-' . date( 'Y-m-d-His' ) . '.csv';

header( 'Content-Type: text/csv; charset=utf-8' );
header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
header( 'X-Content-Type-Options: nosniff' );

$output = fopen( 'php://output', 'w' );

// UTF-8 BOM so Excel shows Romanian diacritics correctly.
fwrite( $output, "\xEF\xBB\xBF" );
fputcsv( $output, [ 'ID', 'Data', 'Companie', 'Rating', 'Tip', 'Nume client', 'Contact', 'Mesaj', 'Sursă', 'Angajat / etichetă', 'Status', 'Blocat' ], ',', '"', '\\' );

$offset = 0;
$batch  = 500;

do {
	$rows = get_reviews( (int) $user['id'], $filters, $batch, $offset );

	foreach ( $rows as $row ) {
		$is_locked   = 1 === (int) $row['is_locked'];
		$is_feedback = 'private_feedback' === $row['action_taken'];

		fputcsv(
			$output,
			[
				(int) $row['id'],
				$row['created_at'],
				csv_safe_cell( $row['company_name'] ),
				(int) $row['rating'],
				$actions[ $row['action_taken'] ],
				csv_safe_cell( $is_locked ? '[blocat]' : $row['customer_name'] ),
				csv_safe_cell( $is_locked ? '[blocat]' : $row['customer_contact'] ),
				csv_safe_cell( $is_locked ? '[blocat]' : (string) $row['message'] ),
				csv_safe_cell( get_source_label( $row['source'] ) ),
				csv_safe_cell( '' !== $row['ref_tag'] ? format_ref_tag( $row['ref_tag'], $row['ref_label'] ) : '' ),
				$is_feedback ? $statuses[ $row['ticket_status'] ] : '',
				$is_locked ? 'da' : 'nu',
			],
			',',
			'"',
			'\\'
		);
	}

	$offset += $batch;
} while ( count( $rows ) === $batch );

fclose( $output );
exit;
