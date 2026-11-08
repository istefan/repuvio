<?php
/**
 * WordPress-style output escaping, input sanitizing and formatting helpers.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

/**
 * Escapes text for output between HTML tags.
 *
 * @param mixed $text Text.
 * @return string
 */
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
}

/**
 * Escapes a value for an HTML attribute.
 *
 * @param mixed $text Value.
 * @return string
 */
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
}

/**
 * Escapes text for a <textarea>.
 *
 * @param mixed $text Text.
 * @return string
 */
function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
}

/**
 * Escapes and validates a URL for href/src. Only http(s), mailto, tel and relative URLs are allowed.
 *
 * @param mixed $url URL.
 * @return string Escaped URL or empty string when unsafe.
 */
function esc_url( $url ) {
	$url = trim( (string) $url );

	if ( '' === $url ) {
		return '';
	}

	// Strip control characters and whitespace that could hide a scheme.
	$url = preg_replace( '/[\x00-\x20\x7F]/', '', $url );

	if ( preg_match( '/^([a-z][a-z0-9+.\-]*):/i', $url, $matches ) ) {
		$scheme = strtolower( $matches[1] );

		if ( ! in_array( $scheme, [ 'http', 'https', 'mailto', 'tel' ], true ) ) {
			return '';
		}
	} elseif ( 0 === strpos( $url, '//' ) ) {
		$url = 'https:' . $url;
	}

	return htmlspecialchars( $url, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
}

/**
 * Escapes a string for use inside a single- or double-quoted inline JavaScript string.
 *
 * @param mixed $text Text.
 * @return string
 */
function esc_js( $text ) {
	$encoded = json_encode( (string) $text, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE );

	// Remove the surrounding double quotes added by json_encode.
	return substr( (string) $encoded, 1, -1 );
}

/**
 * JSON-encodes data safely for embedding inside <script> tags.
 *
 * @param mixed $data Data.
 * @return string
 */
function safe_json( $data ) {
	return (string) json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE );
}

/**
 * Sanitizes a single-line text field: strips tags, control chars, collapses whitespace, limits length.
 *
 * @param mixed $text       Raw input.
 * @param int   $max_length Maximum characters.
 * @return string
 */
function sanitize_text( $text, $max_length = 255 ) {
	if ( ! is_scalar( $text ) ) {
		return '';
	}

	$text = strip_tags( (string) $text );
	$text = preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $text );
	$text = preg_replace( '/\s+/u', ' ', (string) $text );
	$text = trim( (string) $text );

	return mb_substr( $text, 0, $max_length, 'UTF-8' );
}

/**
 * Sanitizes multi-line text (keeps line breaks).
 *
 * @param mixed $text       Raw input.
 * @param int   $max_length Maximum characters.
 * @return string
 */
function sanitize_textarea( $text, $max_length = 5000 ) {
	if ( ! is_scalar( $text ) ) {
		return '';
	}

	$text = strip_tags( (string) $text );
	$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
	$text = preg_replace( '/[\x00-\x09\x0B-\x1F\x7F]/u', '', $text );
	$text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );
	$text = trim( (string) $text );

	return mb_substr( $text, 0, $max_length, 'UTF-8' );
}

/**
 * Normalizes and validates an email address.
 *
 * @param mixed $email Raw input.
 * @return string Lower-cased email or empty string when invalid.
 */
function sanitize_email_address( $email ) {
	if ( ! is_scalar( $email ) ) {
		return '';
	}

	$email = strtolower( trim( (string) $email ) );

	if ( 190 < strlen( $email ) || false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
		return '';
	}

	return $email;
}

/**
 * Parses a comma/semicolon/newline separated list of emails.
 *
 * @param string $list  Raw list.
 * @param int    $limit Max addresses.
 * @return array Valid unique emails.
 */
function parse_email_list( $list, $limit = 5 ) {
	$parts  = preg_split( '/[\s,;]+/', (string) $list );
	$emails = [];

	foreach ( $parts as $part ) {
		$email = sanitize_email_address( $part );

		if ( '' !== $email && ! in_array( $email, $emails, true ) ) {
			$emails[] = $email;
		}

		if ( count( $emails ) >= $limit ) {
			break;
		}
	}

	return $emails;
}

/**
 * Builds a URL-safe slug (with Romanian diacritics transliteration).
 *
 * @param string $text       Text.
 * @param int    $max_length Max length.
 * @return string
 */
function sanitize_slug( $text, $max_length = 80 ) {
	$map = [
		'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
		'Ă' => 'a', 'Â' => 'a', 'Î' => 'i', 'Ș' => 's', 'Ş' => 's', 'Ț' => 't', 'Ţ' => 't',
		'é' => 'e', 'è' => 'e', 'ö' => 'o', 'ü' => 'u', 'ä' => 'a', 'ß' => 'ss', '&' => ' si ',
	];

	$text = strtr( (string) $text, $map );

	if ( function_exists( 'iconv' ) ) {
		$converted = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false !== $converted ) {
			$text = $converted;
		}
	}

	$text = strtolower( $text );
	$text = preg_replace( '/[^a-z0-9]+/', '-', $text );
	$text = trim( (string) $text, '-' );
	$text = substr( $text, 0, $max_length );

	return trim( $text, '-' );
}

/**
 * Returns an integer bounded to a range.
 *
 * @param mixed $value   Raw value.
 * @param int   $min     Minimum.
 * @param int   $max     Maximum.
 * @param int   $default Default when not numeric.
 * @return int
 */
function sanitize_int_range( $value, $min, $max, $default ) {
	if ( ! is_scalar( $value ) || ! is_numeric( $value ) ) {
		return $default;
	}

	return max( $min, min( $max, (int) $value ) );
}

/**
 * Validates a Y-m-d date string.
 *
 * @param mixed $value Raw date.
 * @return string Valid date or empty string.
 */
function sanitize_date( $value ) {
	if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
		return '';
	}

	$date = DateTime::createFromFormat( '!Y-m-d', $value );

	return ( $date && $date->format( 'Y-m-d' ) === $value ) ? $value : '';
}

/**
 * Formats a datetime string for display.
 *
 * @param string|null $datetime DB datetime.
 * @param string      $format   PHP date format.
 * @return string
 */
function format_datetime( $datetime, $format = 'd.m.Y H:i' ) {
	if ( empty( $datetime ) ) {
		return '—';
	}

	$timestamp = strtotime( (string) $datetime );

	return false === $timestamp ? '—' : date( $format, $timestamp );
}

/**
 * Formats a date for display.
 *
 * @param string|null $datetime DB datetime.
 * @return string
 */
function format_date( $datetime ) {
	return format_datetime( $datetime, 'd.m.Y' );
}

/**
 * Formats an amount in RON.
 *
 * @param float|string $amount Amount.
 * @return string
 */
function format_ron( $amount ) {
	return number_format( (float) $amount, 2, ',', '.' ) . ' RON';
}

/**
 * Formats an integer with thousands separators.
 *
 * @param int|float $number Number.
 * @param int       $decimals Decimals.
 * @return string
 */
function format_number( $number, $decimals = 0 ) {
	return number_format( (float) $number, $decimals, ',', '.' );
}

/**
 * Returns a relative "time ago" string in Romanian.
 *
 * @param string $datetime DB datetime.
 * @return string
 */
function time_ago( $datetime ) {
	$timestamp = strtotime( (string) $datetime );

	if ( false === $timestamp ) {
		return '';
	}

	$diff = time() - $timestamp;

	if ( 60 > $diff ) {
		return 'acum câteva secunde';
	}
	if ( 3600 > $diff ) {
		return 'acum ' . (int) floor( $diff / 60 ) . ' min';
	}
	if ( 86400 > $diff ) {
		return 'acum ' . (int) floor( $diff / 3600 ) . ' h';
	}
	if ( 604800 > $diff ) {
		return 'acum ' . (int) floor( $diff / 86400 ) . ' zile';
	}

	return format_date( $datetime );
}

/**
 * Masks a string for locked/private display (keeps first character).
 *
 * @param string $text Text.
 * @return string
 */
function mask_text( $text ) {
	$text = (string) $text;

	if ( '' === $text ) {
		return '';
	}

	return mb_substr( $text, 0, 1, 'UTF-8' ) . str_repeat( '•', min( 12, max( 4, mb_strlen( $text, 'UTF-8' ) - 1 ) ) );
}

/**
 * Renders a 1-5 star string.
 *
 * @param int $rating Rating.
 * @return string Plain text stars.
 */
function stars_text( $rating ) {
	$rating = max( 0, min( 5, (int) $rating ) );

	return str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating );
}
