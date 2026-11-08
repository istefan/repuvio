<?php
/**
 * Super Admin: renders an email inside an <iframe sandbox> of the Email Center.
 *
 * GET  ?slug=welcome_trial  saved template + master layout + demo data;
 * GET  ?log=123             a logged email exactly as it was sent;
 * POST action=draft         unsaved editor content (slug, subject, body_html) with demo data.
 *
 * A thin bar above the email shows the subject (and the recipient for logs). The document runs
 * under the global CSP (no scripts) and inside a sandboxed frame.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

require_once dirname( __DIR__ ) . '/includes/bootstrap.php';

require_super_admin();
send_no_cache_headers();

$html    = '';
$subject = '';
$meta    = '';

if ( is_post() && 'draft' === input_post( 'action' ) ) {
	$template = get_email_template( input_post( 'slug' ) );

	if ( $template ) {
		$rendered = render_email_template(
			[
				'subject'   => mb_substr( (string) ( $_POST['subject'] ?? '' ), 0, 255 ),
				'body_html' => (string) ( $_POST['body_html'] ?? '' ),
			],
			get_email_template_demo_data( $template['slug'] )
		);
		$html     = $rendered['html'];
		$subject  = $rendered['subject'];
		$meta     = 'Previzualizare modificări nesalvate · date demo';
	}
} elseif ( '' !== input_get( 'log' ) ) {
	$row = db_row( 'SELECT * FROM email_logs WHERE id = ?', [ (int) input_get( 'log' ) ] );

	if ( $row ) {
		$html    = '' !== $row['body_html'] ? $row['body_html'] : render_email( 'Conținut indisponibil', '<p>Acest email a fost trimis înainte ca jurnalul să păstreze conținutul emailurilor.</p>' );
		$subject = $row['subject'];
		$meta    = 'Către ' . $row['recipient_email'] . ' · ' . format_datetime( $row['created_at'] );
	}
} else {
	$template = get_email_template( input_get( 'slug' ) );

	if ( $template ) {
		$rendered = render_email_template( $template, get_email_template_demo_data( $template['slug'] ) );
		$html     = $rendered['html'];
		$subject  = $rendered['subject'];
		$meta     = 'Previzualizare cu date demo' . ( 1 === (int) $template['is_enabled'] ? '' : ' · șablon OPRIT' );
	}
}

if ( '' === $html ) {
	http_response_code( 404 );
	$html = render_email( 'Email inexistent', '<p>Șablonul sau emailul cerut nu există.</p>' );
}

$bar = '<div style="font:13px/1.5 Inter,Segoe UI,Arial,sans-serif;background:#0F172A;color:#F8FAFC;padding:10px 16px;">'
	. '<strong>Subiect:</strong> ' . esc_html( $subject )
	. ( '' !== $meta ? '<br><span style="color:#94A3B8;">' . esc_html( $meta ) . '</span>' : '' )
	. '</div>';

// Insert the bar right after <body ...> (or before the content when the document has no body tag).
$output = preg_replace( '/(<body[^>]*>)/i', '$1' . str_replace( '$', '\$', $bar ), $html, 1, $count );

header( 'Content-Type: text/html; charset=utf-8' );
echo 0 < $count ? $output : $bar . $html; // phpcs:ignore WordPress.Security.EscapeOutput -- complete email document built from escaped values.
