<?php
/**
 * Mailer service: dependency-free SMTP client (STARTTLS / SSL, AUTH LOGIN, XOAUTH2),
 * Google OAuth2 token handling, PHP mail() and local log drivers, plus the email template engine
 * (send_templated_email(), {{placeholder}} parser, master layout), the delivery log `email_logs`
 * and the SMTP batch queue used by cron.php.
 *
 * Drivers (setting `mail_driver`):
 *  - smtp                 Standard SMTP with username/password.
 *  - google_oauth         smtp.gmail.com via XOAUTH2 (refresh token from the OAuth consent flow).
 *  - google_app_password  smtp.gmail.com with a Google App Password.
 *  - php_mail             PHP mail() (host MTA).
 *  - log                  Writes messages to storage/logs/mail.log (development).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

const GOOGLE_OAUTH_AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const GOOGLE_OAUTH_SCOPE     = 'https://mail.google.com/';

/**
 * Available mail drivers with labels.
 *
 * @return array
 */
function get_mail_drivers() {
	return [
		'smtp'                => 'SMTP standard',
		'google_oauth'        => 'Cont Google (OAuth2 / XOAUTH2)',
		'google_app_password' => 'Cont Google (App Password)',
		'php_mail'            => 'PHP mail() (server)',
		'log'                 => 'Jurnal local (fără trimitere – dezvoltare)',
	];
}

/**
 * Sends an email using the configured driver and records it in `email_logs` (one row per
 * recipient, with the final HTML body so it can be viewed and re-sent from the Email Center).
 *
 * Showcase (sales demo) recipients are replaced by the demo notification address first.
 *
 * @param string|array $to      Recipient email or list.
 * @param string       $subject Subject.
 * @param string       $html    Complete HTML document (already wrapped in the layout).
 * @param string       $text    Plain text body (generated from HTML when empty).
 * @param array        $options reply_to (string), debug (bool), template_slug (string), user_id (int),
 *                              log_id (int: update this email_logs row instead of inserting), log (bool, default true).
 * @return array{success:bool,error:string,transcript:array}
 */
function send_mail( $to, $subject, $html, $text = '', array $options = [] ) {
	$recipients = is_array( $to ) ? $to : [ $to ];
	$recipients = array_values( array_unique( array_filter( array_map( 'sanitize_email_address', $recipients ) ) ) );
	$subject    = str_replace( [ "\r", "\n" ], ' ', (string) $subject );
	$transcript = [];

	if ( empty( $recipients ) ) {
		return [
			'success'    => false,
			'error'      => 'Destinatar invalid.',
			'transcript' => $transcript,
		];
	}

	// Showcase (sales demo) accounts never receive email: it goes to the configured demo inbox instead.
	$showcase = showcase_filter_recipients( $recipients );

	if ( $showcase['redirected'] ) {
		$recipients = $showcase['recipients'];
		$subject    = 0 === strpos( $subject, '[Demo] ' ) ? $subject : '[Demo] ' . $subject;

		if ( empty( $recipients ) ) {
			return [
				'success'    => true,
				'error'      => '',
				'transcript' => [ 'Cont demo: emailul nu a fost trimis (nu este setată o adresă pentru notificările demo).' ],
			];
		}
	}

	if ( '' === $text ) {
		$text = html_to_text( $html );
	}

	$driver     = get_setting( 'mail_driver', 'log' );
	$from_email = sanitize_email_address( get_setting( 'mail_from_email' ) );
	$from_name  = sanitize_text( get_setting( 'mail_from_name', app_name() ), 100 );

	if ( 'google_oauth' === $driver || 'google_app_password' === $driver ) {
		// Gmail requires the authenticated address (or a verified alias) as sender.
		$google_email = sanitize_email_address( get_setting( 'google_email' ) );
		$from_email   = '' !== $from_email ? $from_email : $google_email;
	}

	if ( '' === $from_email ) {
		$from_email = 'no-reply@' . ( parse_url( APP_URL, PHP_URL_HOST ) ?: 'localhost' );
	}

	$reply_to = isset( $options['reply_to'] ) ? sanitize_email_address( $options['reply_to'] ) : '';
	$reply_to = '' !== $reply_to ? $reply_to : sanitize_email_address( get_setting( 'email_reply_to' ) );
	$message  = build_mime_message( $from_email, $from_name, $recipients, $subject, $html, $text, $reply_to );
	$error    = '';
	$success  = false;

	try {
		switch ( $driver ) {
			case 'smtp':
				$success = smtp_deliver(
					[
						'host'       => get_setting( 'smtp_host' ),
						'port'       => get_setting_int( 'smtp_port', 587 ),
						'encryption' => get_setting( 'smtp_encryption', 'tls' ),
						'username'   => get_setting( 'smtp_username' ),
						'password'   => get_setting( 'smtp_password' ),
						'auth'       => 'login',
					],
					$from_email,
					$recipients,
					$message,
					$transcript
				);
				break;

			case 'google_app_password':
				$success = smtp_deliver(
					[
						'host'       => 'smtp.gmail.com',
						'port'       => 587,
						'encryption' => 'tls',
						'username'   => get_setting( 'google_email' ),
						'password'   => str_replace( ' ', '', get_setting( 'google_app_password' ) ),
						'auth'       => 'login',
					],
					$from_email,
					$recipients,
					$message,
					$transcript
				);
				break;

			case 'google_oauth':
				$access_token = google_get_access_token();

				if ( '' === $access_token ) {
					throw new RuntimeException( 'Contul Google nu este conectat sau token-ul nu a putut fi reîmprospătat.' );
				}

				$success = smtp_deliver(
					[
						'host'       => 'smtp.gmail.com',
						'port'       => 587,
						'encryption' => 'tls',
						'username'   => get_setting( 'google_email' ),
						'password'   => $access_token,
						'auth'       => 'xoauth2',
					],
					$from_email,
					$recipients,
					$message,
					$transcript
				);
				break;

			case 'php_mail':
				$success = php_mail_deliver( $recipients, $subject, $message, $from_email );
				if ( ! $success ) {
					throw new RuntimeException( 'Funcția mail() a returnat eroare.' );
				}
				$transcript[] = 'mail() accepted the message.';
				break;

			case 'log':
			default:
				write_log( 'mail.log', "TO: " . implode( ', ', $recipients ) . "\n" . $message['headers'] . "\nSubject: " . $subject . "\n\n" . $text . "\n" . str_repeat( '-', 70 ) );
				$transcript[] = 'Driver "log": mesajul a fost scris în storage/logs/mail.log (nu a fost trimis).';
				$success      = true;
				break;
		}
	} catch ( Throwable $e ) {
		$error        = $e->getMessage();
		$transcript[] = 'ERROR: ' . $error;
	}

	if ( false !== ( $options['log'] ?? true ) ) {
		$status = $success ? 'sent' : 'failed';

		try {
			if ( ! empty( $options['log_id'] ) ) {
				db_query(
					'UPDATE email_logs SET recipient_email = ?, subject = ?, status = ?, error_message = ?, sent_at = ? WHERE id = ?',
					[ $recipients[0], mb_substr( $subject, 0, 255 ), $status, '' === $error ? null : mb_substr( $error, 0, 2000 ), $success ? now_db() : null, (int) $options['log_id'] ]
				);
			} else {
				foreach ( $recipients as $recipient ) {
					log_email( $recipient, $subject, $html, $status, $error, $options );
				}
			}
		} catch ( Throwable $e ) {
			write_log( 'php-error.log', 'email_logs write failed: ' . $e->getMessage() );
		}
	}

	if ( ! $success ) {
		write_log( 'php-error.log', 'Mail to ' . implode( ', ', $recipients ) . ' failed: ' . $error );
	}

	return [
		'success'    => $success,
		'error'      => $error,
		'transcript' => $transcript,
	];
}

/**
 * Inserts one `email_logs` row.
 *
 * @param string $recipient Recipient.
 * @param string $subject   Subject.
 * @param string $html      Complete HTML body.
 * @param string $status    sent|failed|queued|skipped_disabled.
 * @param string $error     Error message.
 * @param array  $options   template_slug, user_id, reply_to.
 * @return int Log ID.
 */
function log_email( $recipient, $subject, $html, $status, $error = '', array $options = [] ) {
	db_query(
		'INSERT INTO email_logs (template_slug, recipient_email, recipient_user_id, reply_to, subject, body_html, status, error_message, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
		[
			empty( $options['template_slug'] ) ? null : mb_substr( (string) $options['template_slug'], 0, 64 ),
			mb_substr( (string) $recipient, 0, 190 ),
			empty( $options['user_id'] ) ? null : (int) $options['user_id'],
			mb_substr( sanitize_email_address( $options['reply_to'] ?? '' ), 0, 190 ),
			mb_substr( (string) $subject, 0, 255 ),
			(string) $html,
			$status,
			'' === (string) $error ? null : mb_substr( (string) $error, 0, 2000 ),
			'sent' === $status ? now_db() : null,
		]
	);

	return db_insert_id();
}

/*
 * -----------------------------------------------------------------------------
 * Template engine (email_templates) + master layout + queue
 * -----------------------------------------------------------------------------
 */

/**
 * Master layout settings with their defaults.
 *
 * @return array
 */
function get_email_layout_settings() {
	$accent = (string) get_setting( 'email_accent_color', '#0F172A' );

	return [
		'header_title' => (string) get_setting( 'email_header_title', '' ),
		'logo_url'     => (string) get_setting( 'email_logo_url', '' ),
		'accent_color' => preg_match( '/^#[0-9A-Fa-f]{6}$/', $accent ) ? $accent : '#0F172A',
		'footer_text'  => (string) get_setting( 'email_footer_text', get_default_email_footer_text() ),
		'reply_to'     => (string) get_setting( 'email_reply_to', '' ),
	];
}

/**
 * Factory footer text of the master layout (placeholders: {{year}}, {{app_name}}, {{provider_name}}).
 *
 * @return string
 */
function get_default_email_footer_text() {
	return '© {{year}} {{app_name}} · Un serviciu {{provider_name}} · Toate drepturile rezervate.';
}

/**
 * Global placeholder values available in every template.
 *
 * @return array
 */
function get_global_email_placeholder_values() {
	return [
		'app_name'      => app_name(),
		'app_url'       => app_url( '' ),
		'support_email' => (string) get_setting( 'support_email' ),
		'year'          => date( 'Y' ),
		'accent_color'  => get_email_layout_settings()['accent_color'],
	];
}

/**
 * Replaces {{placeholders}} in a template string.
 *
 * Values are HTML-escaped (newlines become <br> in HTML mode), except keys ending in `_html`
 * (already escaped, pre-built HTML) and `_url` (esc_url()). In text mode (subjects) values are
 * inserted as plain text with tags and line breaks removed. Unknown placeholders become empty.
 *
 * @param string $template Template string.
 * @param array  $values   key => value.
 * @param bool   $html     HTML mode.
 * @return string
 */
function replace_email_placeholders( $template, array $values, $html = true ) {
	return (string) preg_replace_callback(
		'/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
		function ( $match ) use ( $values, $html ) {
			$key   = strtolower( $match[1] );
			$value = array_key_exists( $key, $values ) ? (string) $values[ $key ] : '';

			if ( ! $html ) {
				return trim( str_replace( [ "\r", "\n" ], ' ', email_plain_text( $value ) ) );
			}

			if ( '_html' === substr( $key, -5 ) ) {
				return $value;
			}

			if ( '_url' === substr( $key, -4 ) ) {
				return esc_url( $value );
			}

			return nl2br( esc_html( $value ), false );
		},
		(string) $template
	);
}

/**
 * Removes HTML tags (subjects and plain values).
 *
 * @param string $value Value.
 * @return string
 */
function email_plain_text( $value ) {
	return html_entity_decode( strip_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/**
 * Wraps inner HTML in the master email layout (header title / logo, accent bar, footer text).
 *
 * @param string $inner_html Body content.
 * @param string $title      Document title (plain text).
 * @return string Complete HTML document.
 */
function render_email_layout( $inner_html, $title = '' ) {
	$layout = get_email_layout_settings();
	$legal  = function_exists( 'get_legal_settings' ) ? get_legal_settings() : [];
	$footer = replace_email_placeholders(
		'' !== trim( $layout['footer_text'] ) ? $layout['footer_text'] : get_default_email_footer_text(),
		[
			'year'          => date( 'Y' ),
			'app_name'      => app_name(),
			'provider_name' => $legal['legal_company_name'] ?? '',
		]
	);
	$header = '' !== trim( $layout['header_title'] ) ? $layout['header_title'] : app_name();
	$brand  = esc_html( $header );

	if ( '' !== $layout['logo_url'] && preg_match( '#^https?://#i', $layout['logo_url'] ) ) {
		$brand = '<img src="' . esc_url( $layout['logo_url'] ) . '" alt="' . esc_attr( $header ) . '" style="max-height:40px;max-width:220px;display:block;border:0;">';
	}

	return '<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . esc_html( '' !== $title ? $title : $header ) . '</title></head>'
		. '<body style="margin:0;padding:0;background:#F8FAFC;font-family:Inter,Segoe UI,Arial,sans-serif;color:#0F172A;">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F8FAFC;padding:32px 12px;"><tr><td align="center">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #E2E8F0;border-top:4px solid ' . esc_attr( $layout['accent_color'] ) . ';border-radius:14px;">'
		. '<tr><td style="padding:22px 28px;border-bottom:1px solid #E2E8F0;font-weight:700;font-size:18px;color:#0F172A;">' . $brand . '</td></tr>'
		. '<tr><td style="padding:28px;font-size:15px;line-height:1.6;color:#334155;">' . $inner_html . '</td></tr>'
		. '<tr><td style="padding:18px 28px;border-top:1px solid #E2E8F0;font-size:12px;color:#64748B;">' . $footer . '</td></tr>'
		. '</table></td></tr></table></body></html>';
}

/**
 * Renders a template (row or draft) with placeholders: subject + complete HTML.
 *
 * @param array $template     Template row (subject, body_html).
 * @param array $placeholders key => value.
 * @return array{subject:string,html:string}
 */
function render_email_template( array $template, array $placeholders ) {
	$values  = array_merge( get_global_email_placeholder_values(), $placeholders );
	$subject = replace_email_placeholders( $template['subject'], $values, false );

	return [
		'subject' => $subject,
		'html'    => render_email_layout( replace_email_placeholders( $template['body_html'], $values ), $subject ),
	];
}

/**
 * Sends a system email from an `email_templates` row.
 *
 * Disabled templates are not sent: one `skipped_disabled` row per recipient is logged instead
 * (unless options.force, used by the admin "Trimite test"). With options.queue the message is
 * stored as `queued` and sent later by process_email_queue() (cron, SMTP batch throttling).
 *
 * @param string       $template_slug     Template slug.
 * @param string|array $recipient_email   Recipient(s).
 * @param array        $placeholders      key => value.
 * @param int|null     $recipient_user_id Recipient user ID (for the log).
 * @param array        $options           reply_to, queue (bool), force (bool), subject_prefix (string), debug (bool).
 * @return array{success:bool,error:string,transcript:array,skipped:bool,queued:bool}
 */
function send_templated_email( $template_slug, $recipient_email, array $placeholders, $recipient_user_id = null, array $options = [] ) {
	$template   = get_email_template( $template_slug );
	$recipients = array_values( array_unique( array_filter( array_map( 'sanitize_email_address', is_array( $recipient_email ) ? $recipient_email : [ $recipient_email ] ) ) ) );
	$result     = [
		'success'    => false,
		'error'      => '',
		'transcript' => [],
		'skipped'    => false,
		'queued'     => false,
	];

	if ( ! $template ) {
		$result['error'] = 'Șablonul „' . $template_slug . '” nu există.';
		write_log( 'php-error.log', 'Missing email template: ' . $template_slug );

		return $result;
	}

	if ( empty( $recipients ) ) {
		$result['error'] = 'Destinatar invalid.';

		return $result;
	}

	$rendered = render_email_template( $template, $placeholders );
	$subject  = ( $options['subject_prefix'] ?? '' ) . $rendered['subject'];
	$log_opts = [
		'template_slug' => $template_slug,
		'user_id'       => $recipient_user_id,
		'reply_to'      => $options['reply_to'] ?? '',
	];

	if ( 1 !== (int) $template['is_enabled'] && empty( $options['force'] ) ) {
		foreach ( $recipients as $recipient ) {
			log_email( $recipient, $subject, $rendered['html'], 'skipped_disabled', 'Șablon dezactivat din Email & Șabloane.', $log_opts );
		}

		$result['skipped'] = true;
		$result['error']   = 'Șablonul este dezactivat.';

		return $result;
	}

	if ( ! empty( $options['queue'] ) ) {
		foreach ( $recipients as $recipient ) {
			log_email( $recipient, $subject, $rendered['html'], 'queued', '', $log_opts );
		}

		$result['success'] = true;
		$result['queued']  = true;

		return $result;
	}

	return array_merge(
		$result,
		send_mail( $recipients, $subject, $rendered['html'], '', array_merge( $log_opts, [ 'debug' => ! empty( $options['debug'] ) ] ) )
	);
}

/**
 * Sends queued emails, oldest first, at most `smtp_batch_limit_per_run` per call, pausing
 * `smtp_delay_ms` between messages (shared hosting SMTP limits).
 *
 * @param int|null $limit Override of the batch limit.
 * @return array{sent:int,failed:int,remaining:int}
 */
function process_email_queue( $limit = null ) {
	$limit  = null === $limit ? get_setting_int( 'smtp_batch_limit_per_run', 30 ) : (int) $limit;
	$limit  = max( 1, min( 500, $limit ) );
	$delay  = max( 0, min( 5000, get_setting_int( 'smtp_delay_ms', 250 ) ) );
	$report = [
		'sent'      => 0,
		'failed'    => 0,
		'remaining' => 0,
	];

	foreach ( db_all( "SELECT * FROM email_logs WHERE status = 'queued' ORDER BY id ASC LIMIT " . (int) $limit ) as $index => $row ) {
		if ( 0 < $index && 0 < $delay ) {
			usleep( $delay * 1000 );
		}

		$sent = send_logged_email( $row );
		++$report[ $sent['success'] ? 'sent' : 'failed' ];
	}

	$report['remaining'] = (int) db_value( "SELECT COUNT(*) FROM email_logs WHERE status = 'queued'" );

	return $report;
}

/**
 * (Re)sends a logged email exactly as it was stored, updating the same log row.
 *
 * @param array $row email_logs row.
 * @return array send_mail() result.
 */
function send_logged_email( array $row ) {
	return send_mail(
		$row['recipient_email'],
		$row['subject'],
		$row['body_html'],
		'',
		[
			'reply_to'      => $row['reply_to'],
			'template_slug' => $row['template_slug'],
			'user_id'       => $row['recipient_user_id'],
			'log_id'        => (int) $row['id'],
		]
	);
}

/**
 * Status labels and pill classes of the email log.
 *
 * @return array status => [ label, pill class ]
 */
function get_email_log_statuses() {
	return [
		'sent'             => [ 'Trimis', 'rm-pill-positive' ],
		'failed'           => [ 'Eroare', 'rm-pill-negative' ],
		'queued'           => [ 'În coadă', 'rm-pill-trial' ],
		'skipped_disabled' => [ 'Oprit (șablon dezactivat)', 'rm-pill-muted' ],
	];
}

/**
 * Converts HTML to readable plain text.
 *
 * @param string $html HTML.
 * @return string
 */
function html_to_text( $html ) {
	$html = preg_replace( '#<(br|/p|/div|/h[1-6]|/tr|/li)[^>]*>#i', "\n", (string) $html );
	$html = preg_replace( '#</t[dh]>#i', ' ', (string) $html );
	$html = preg_replace( '#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', (string) $html );
	$text = html_entity_decode( strip_tags( (string) $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = preg_replace( "/[ \t]+/", ' ', $text );
	$text = preg_replace( "/\n\s*\n\s*\n+/", "\n\n", (string) $text );

	return trim( (string) $text );
}

/**
 * Encodes a header value (RFC 2047) when it contains non-ASCII characters.
 *
 * @param string $value Value.
 * @return string
 */
function encode_mail_header( $value ) {
	$value = str_replace( [ "\r", "\n" ], '', (string) $value );

	if ( preg_match( '/[^\x20-\x7E]/', $value ) ) {
		return '=?UTF-8?B?' . base64_encode( $value ) . '?=';
	}

	return $value;
}

/**
 * Builds a multipart/alternative MIME message.
 *
 * @param string $from_email From address.
 * @param string $from_name  From name.
 * @param array  $recipients Recipients.
 * @param string $subject    Subject.
 * @param string $html       HTML body.
 * @param string $text       Text body.
 * @param string $reply_to   Reply-To.
 * @return array{headers:string,body:string,subject:string}
 */
function build_mime_message( $from_email, $from_name, array $recipients, $subject, $html, $text, $reply_to = '' ) {
	$boundary = 'b1_' . bin2hex( random_bytes( 12 ) );
	$host     = parse_url( APP_URL, PHP_URL_HOST ) ?: 'localhost';
	$name     = str_replace( '"', '', $from_name );

	$headers   = [];
	$headers[] = 'Date: ' . date( 'r' );
	$headers[] = 'From: "' . encode_mail_header( $name ) . '" <' . $from_email . '>';
	$headers[] = 'To: ' . implode( ', ', $recipients );

	if ( '' !== $reply_to ) {
		$headers[] = 'Reply-To: ' . $reply_to;
	}

	$headers[] = 'Message-ID: <' . bin2hex( random_bytes( 16 ) ) . '@' . $host . '>';
	$headers[] = 'MIME-Version: 1.0';
	$headers[] = 'X-Mailer: ' . str_replace( [ "\r", "\n" ], '', app_name() );
	$headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

	$body  = '--' . $boundary . "\r\n";
	$body .= "Content-Type: text/plain; charset=UTF-8\r\n";
	$body .= "Content-Transfer-Encoding: base64\r\n\r\n";
	$body .= chunk_split( base64_encode( $text ), 76, "\r\n" );
	$body .= '--' . $boundary . "\r\n";
	$body .= "Content-Type: text/html; charset=UTF-8\r\n";
	$body .= "Content-Transfer-Encoding: base64\r\n\r\n";
	$body .= chunk_split( base64_encode( $html ), 76, "\r\n" );
	$body .= '--' . $boundary . "--\r\n";

	return [
		'headers' => implode( "\r\n", $headers ),
		'body'    => $body,
		'subject' => encode_mail_header( $subject ),
	];
}

/**
 * Delivers via PHP mail().
 *
 * @param array  $recipients Recipients.
 * @param string $subject    Raw subject.
 * @param array  $message    Built message.
 * @param string $from_email Envelope sender.
 * @return bool
 */
function php_mail_deliver( array $recipients, $subject, array $message, $from_email ) {
	// mail() adds To and Subject itself.
	$headers = preg_replace( '/^To: .*\r\n/m', '', $message['headers'] );

	return mail( implode( ', ', $recipients ), $message['subject'], $message['body'], $headers, '-f' . $from_email );
}

/**
 * Delivers a message over SMTP.
 *
 * @param array  $config     host, port, encryption (tls|ssl|none), username, password, auth (login|xoauth2).
 * @param string $from_email Envelope sender.
 * @param array  $recipients Recipients.
 * @param array  $message    Built message.
 * @param array  $transcript Debug transcript (by reference).
 * @return bool
 * @throws RuntimeException On SMTP errors.
 */
function smtp_deliver( array $config, $from_email, array $recipients, array $message, array &$transcript ) {
	$host       = trim( (string) $config['host'] );
	$port       = (int) $config['port'];
	$encryption = strtolower( (string) $config['encryption'] );

	if ( '' === $host || 0 >= $port ) {
		throw new RuntimeException( 'Serverul SMTP nu este configurat.' );
	}

	$context = stream_context_create(
		[
			'ssl' => [
				'verify_peer'       => true,
				'verify_peer_name'  => true,
				'allow_self_signed' => false,
				'peer_name'         => $host,
			],
		]
	);

	$remote = ( 'ssl' === $encryption ? 'ssl://' : 'tcp://' ) . $host . ':' . $port;
	$errno  = 0;
	$errstr = '';

	$transcript[] = '* Connecting to ' . $remote;
	$socket       = @stream_socket_client( $remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

	if ( false === $socket ) {
		throw new RuntimeException( 'Conectare SMTP eșuată: ' . $errstr . ' (' . $errno . ')' );
	}

	stream_set_timeout( $socket, 20 );

	try {
		smtp_expect( $socket, [ 220 ], $transcript );

		$helo_host = parse_url( APP_URL, PHP_URL_HOST ) ?: 'localhost';
		smtp_command( $socket, 'EHLO ' . $helo_host, [ 250 ], $transcript );

		if ( 'tls' === $encryption ) {
			smtp_command( $socket, 'STARTTLS', [ 220 ], $transcript );

			$crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
			if ( defined( 'STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT' ) ) {
				$crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
			}

			if ( true !== stream_socket_enable_crypto( $socket, true, $crypto ) ) {
				throw new RuntimeException( 'Negocierea TLS a eșuat.' );
			}

			$transcript[] = '* TLS negotiated';
			smtp_command( $socket, 'EHLO ' . $helo_host, [ 250 ], $transcript );
		}

		$username = (string) $config['username'];
		$password = (string) $config['password'];

		if ( '' !== $username ) {
			if ( 'xoauth2' === $config['auth'] ) {
				$token    = base64_encode( 'user=' . $username . "\x01auth=Bearer " . $password . "\x01\x01" );
				$response = smtp_command( $socket, 'AUTH XOAUTH2 ' . $token, [ 235, 334 ], $transcript, 'AUTH XOAUTH2 ********' );

				if ( 0 === strpos( $response, '334' ) ) {
					// Server sent an error challenge; send empty line to get the final error.
					smtp_command( $socket, '', [ 235 ], $transcript );
				}
			} else {
				smtp_command( $socket, 'AUTH LOGIN', [ 334 ], $transcript );
				smtp_command( $socket, base64_encode( $username ), [ 334 ], $transcript, '******** (username)' );
				smtp_command( $socket, base64_encode( $password ), [ 235 ], $transcript, '******** (password)' );
			}
		}

		smtp_command( $socket, 'MAIL FROM:<' . $from_email . '>', [ 250 ], $transcript );

		foreach ( $recipients as $recipient ) {
			smtp_command( $socket, 'RCPT TO:<' . $recipient . '>', [ 250, 251 ], $transcript );
		}

		smtp_command( $socket, 'DATA', [ 354 ], $transcript );

		$data = $message['headers'] . "\r\nSubject: " . $message['subject'] . "\r\n\r\n" . $message['body'];
		$data = str_replace( "\r\n.", "\r\n..", $data );

		fwrite( $socket, $data . "\r\n.\r\n" );
		$transcript[] = '> [message body, ' . strlen( $data ) . ' bytes]';
		smtp_expect( $socket, [ 250 ], $transcript );

		smtp_command( $socket, 'QUIT', [ 221 ], $transcript );
	} finally {
		fclose( $socket );
	}

	return true;
}

/**
 * Sends an SMTP command and checks the reply code.
 *
 * @param resource $socket      Socket.
 * @param string   $command     Command.
 * @param array    $expected    Accepted codes.
 * @param array    $transcript  Transcript.
 * @param string   $log_as      Masked log line.
 * @return string Response.
 */
function smtp_command( $socket, $command, array $expected, array &$transcript, $log_as = '' ) {
	fwrite( $socket, $command . "\r\n" );
	$transcript[] = '> ' . ( '' !== $log_as ? $log_as : $command );

	return smtp_expect( $socket, $expected, $transcript );
}

/**
 * Reads a (multi-line) SMTP response and validates the code.
 *
 * @param resource $socket     Socket.
 * @param array    $expected   Accepted codes.
 * @param array    $transcript Transcript.
 * @return string Full response.
 * @throws RuntimeException On unexpected code or timeout.
 */
function smtp_expect( $socket, array $expected, array &$transcript ) {
	$response = '';

	while ( false !== ( $line = fgets( $socket, 1024 ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
		$response    .= $line;
		$transcript[] = '< ' . rtrim( $line );

		if ( 4 > strlen( $line ) || ' ' === $line[3] ) {
			break;
		}
	}

	$meta = stream_get_meta_data( $socket );

	if ( ! empty( $meta['timed_out'] ) ) {
		throw new RuntimeException( 'Timeout la comunicarea cu serverul SMTP.' );
	}

	$code = (int) substr( $response, 0, 3 );

	if ( ! in_array( $code, $expected, true ) ) {
		throw new RuntimeException( 'Răspuns SMTP neașteptat: ' . trim( $response ) );
	}

	return $response;
}

/*
 * -----------------------------------------------------------------------------
 * Google OAuth2
 * -----------------------------------------------------------------------------
 */

/**
 * The redirect URI configured for the OAuth client.
 *
 * @return string
 */
function google_redirect_uri() {
	$configured = get_setting( 'google_redirect_uri' );

	return '' !== $configured ? $configured : app_url( 'admin/google-oauth.php' );
}

/**
 * Builds the Google consent URL.
 *
 * @param string $state Anti-CSRF state.
 * @return string
 */
function google_oauth_authorize_url( $state ) {
	return GOOGLE_OAUTH_AUTH_URL . '?' . http_build_query(
		[
			'client_id'     => get_setting( 'google_client_id' ),
			'redirect_uri'  => google_redirect_uri(),
			'response_type' => 'code',
			'scope'         => GOOGLE_OAUTH_SCOPE,
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => $state,
			'login_hint'    => get_setting( 'google_email' ),
		],
		'',
		'&',
		PHP_QUERY_RFC3986
	);
}

/**
 * Exchanges an authorization code for tokens and stores them (encrypted).
 *
 * @param string $code Authorization code.
 * @return string Error message or empty string on success.
 */
function google_oauth_exchange_code( $code ) {
	$result = http_post_form(
		GOOGLE_OAUTH_TOKEN_URL,
		[
			'code'          => $code,
			'client_id'     => get_setting( 'google_client_id' ),
			'client_secret' => get_setting( 'google_client_secret' ),
			'redirect_uri'  => google_redirect_uri(),
			'grant_type'    => 'authorization_code',
		]
	);

	$data = json_decode( $result['body'], true );

	if ( 200 !== $result['status'] || ! is_array( $data ) || empty( $data['access_token'] ) ) {
		$message = is_array( $data ) && isset( $data['error_description'] ) ? $data['error_description'] : ( $result['error'] ?: 'Răspuns invalid de la Google.' );

		return (string) $message;
	}

	if ( ! empty( $data['refresh_token'] ) ) {
		update_setting( 'google_refresh_token', (string) $data['refresh_token'] );
	}

	update_setting( 'google_access_token', (string) $data['access_token'] );
	update_setting( 'google_token_expires', (string) ( time() + (int) ( $data['expires_in'] ?? 3600 ) ) );

	if ( '' === get_setting( 'google_refresh_token' ) ) {
		return 'Google nu a returnat un refresh token. Revocă accesul aplicației în contul Google și reîncearcă.';
	}

	return '';
}

/**
 * Returns a valid access token, refreshing it when needed.
 *
 * @return string Empty string on failure.
 */
function google_get_access_token() {
	$token   = get_setting( 'google_access_token' );
	$expires = get_setting_int( 'google_token_expires', 0 );

	if ( '' !== $token && $expires > ( time() + 60 ) ) {
		return $token;
	}

	$refresh_token = get_setting( 'google_refresh_token' );

	if ( '' === $refresh_token ) {
		return '';
	}

	$result = http_post_form(
		GOOGLE_OAUTH_TOKEN_URL,
		[
			'client_id'     => get_setting( 'google_client_id' ),
			'client_secret' => get_setting( 'google_client_secret' ),
			'refresh_token' => $refresh_token,
			'grant_type'    => 'refresh_token',
		]
	);

	$data = json_decode( $result['body'], true );

	if ( 200 !== $result['status'] || ! is_array( $data ) || empty( $data['access_token'] ) ) {
		write_log( 'php-error.log', 'Google token refresh failed: HTTP ' . $result['status'] . ' ' . mb_substr( $result['body'], 0, 300 ) );

		return '';
	}

	update_setting( 'google_access_token', (string) $data['access_token'] );
	update_setting( 'google_token_expires', (string) ( time() + (int) ( $data['expires_in'] ?? 3600 ) ) );

	return (string) $data['access_token'];
}

/**
 * Removes stored Google tokens.
 *
 * @return void
 */
function google_oauth_disconnect() {
	update_setting( 'google_refresh_token', '' );
	update_setting( 'google_access_token', '' );
	update_setting( 'google_token_expires', '0' );
}

/**
 * POSTs form data over HTTPS using cURL (fallback: streams).
 *
 * @param string $url    URL.
 * @param array  $fields Fields.
 * @return array{status:int,body:string,error:string}
 */
function http_post_form( $url, array $fields ) {
	$payload = http_build_query( $fields, '', '&' );

	if ( function_exists( 'curl_init' ) ) {
		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			[
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $payload,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 20,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_HTTPHEADER     => [ 'Content-Type: application/x-www-form-urlencoded', 'Accept: application/json' ],
			]
		);

		$body   = curl_exec( $ch );
		$status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$error  = curl_error( $ch );

		return [
			'status' => $status,
			'body'   => false === $body ? '' : (string) $body,
			'error'  => $error,
		];
	}

	$context = stream_context_create(
		[
			'http' => [
				'method'        => 'POST',
				'header'        => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
				'content'       => $payload,
				'timeout'       => 20,
				'ignore_errors' => true,
			],
		]
	);

	$body   = @file_get_contents( $url, false, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	$status = 0;
	$lines  = function_exists( 'http_get_last_response_headers' ) ? http_get_last_response_headers() : ( $http_response_header ?? [] );

	if ( isset( $lines[0] ) && preg_match( '/\s(\d{3})\s/', $lines[0], $matches ) ) {
		$status = (int) $matches[1];
	}

	return [
		'status' => $status,
		'body'   => false === $body ? '' : (string) $body,
		'error'  => false === $body ? 'Cererea HTTP a eșuat.' : '',
	];
}
