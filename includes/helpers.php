<?php
/**
 * General helpers: URLs, redirects, request input, flash messages, pagination, templates.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

/**
 * Builds an absolute application URL.
 *
 * @param string $path  Relative path (e.g. 'dashboard/index.php').
 * @param array  $query Query args.
 * @return string
 */
function app_url( $path = '', array $query = [] ) {
	$url = rtrim( APP_URL, '/' ) . '/' . ltrim( (string) $path, '/' );

	if ( ! empty( $query ) ) {
		$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	return $url;
}

/**
 * Builds a versioned asset URL.
 *
 * @param string $path Path relative to /assets/.
 * @return string
 */
function asset_url( $path ) {
	$file    = REPUVIO_ROOT . '/assets/' . ltrim( $path, '/' );
	$version = is_file( $file ) ? (string) filemtime( $file ) : '1';

	return app_url( 'assets/' . ltrim( $path, '/' ), [ 'v' => $version ] );
}

/**
 * Builds a public URL for an uploaded file.
 *
 * @param string|null $relative_path Path relative to the app root (uploads/...).
 * @return string Empty when no file.
 */
function upload_url( $relative_path ) {
	if ( empty( $relative_path ) || ! preg_match( '#^uploads/companies/[a-f0-9]{32}\.(webp|jpg|png)$#', (string) $relative_path ) ) {
		return '';
	}

	return app_url( $relative_path );
}

/**
 * Sends a redirect and stops execution.
 *
 * @param string $url    Absolute URL.
 * @param int    $status HTTP status.
 * @return never
 */
function redirect( $url, $status = 302 ) {
	header( 'Location: ' . str_replace( [ "\r", "\n" ], '', $url ), true, $status );
	exit;
}

/**
 * Redirects only to an internal URL (open-redirect protection).
 *
 * @param string $url      Candidate URL.
 * @param string $fallback Internal fallback path.
 * @return never
 */
function safe_redirect( $url, $fallback = 'index.php' ) {
	redirect( is_internal_url( $url ) ? $url : app_url( $fallback ) );
}

/**
 * Checks whether a URL points inside this application.
 *
 * @param string $url URL.
 * @return bool
 */
function is_internal_url( $url ) {
	$url  = (string) $url;
	$base = rtrim( APP_URL, '/' ) . '/';

	if ( preg_match( '/[\r\n\\\\]/', $url ) ) {
		return false;
	}

	return 0 === strpos( $url, $base );
}

/**
 * Returns the request method.
 *
 * @return string
 */
function request_method() {
	return strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
}

/**
 * Whether the request is a POST.
 *
 * @return bool
 */
function is_post() {
	return 'POST' === request_method();
}

/**
 * Reads a scalar POST value.
 *
 * @param string $key     Key.
 * @param mixed  $default Default.
 * @return mixed
 */
function input_post( $key, $default = '' ) {
	if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
		return $default;
	}

	return (string) $_POST[ $key ];
}

/**
 * Reads a scalar GET value.
 *
 * @param string $key     Key.
 * @param mixed  $default Default.
 * @return mixed
 */
function input_get( $key, $default = '' ) {
	if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) {
		return $default;
	}

	return (string) $_GET[ $key ];
}

/**
 * Returns the client IP (REMOTE_ADDR only; proxy headers are spoofable).
 *
 * @return string
 */
function client_ip() {
	$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

	return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
}

/**
 * Returns a salted hash of the client IP (privacy-friendly analytics).
 *
 * @return string
 */
function client_ip_hash() {
	return hash_hmac( 'sha256', client_ip(), APP_KEY );
}

/**
 * Returns a truncated user agent string.
 *
 * @return string
 */
function client_user_agent() {
	return mb_substr( sanitize_text( $_SERVER['HTTP_USER_AGENT'] ?? '', 255 ), 0, 255 );
}

/**
 * Stores a flash message for the next page load.
 *
 * @param string $type    success|danger|warning|info.
 * @param string $message Message (plain text).
 * @return void
 */
function flash( $type, $message ) {
	$_SESSION['flash'][] = [
		'type'    => in_array( $type, [ 'success', 'danger', 'warning', 'info' ], true ) ? $type : 'info',
		'message' => (string) $message,
	];
}

/**
 * Returns and clears flash messages.
 *
 * @return array
 */
function get_flash_messages() {
	$messages = $_SESSION['flash'] ?? [];
	unset( $_SESSION['flash'] );

	return is_array( $messages ) ? $messages : [];
}

/**
 * Stores old form input for re-display after a failed POST.
 *
 * @param array $data Data (never include passwords).
 * @return void
 */
function set_old_input( array $data ) {
	$_SESSION['old_input'] = $data;
}

/**
 * Reads old form input once.
 *
 * @param string $key     Key.
 * @param string $default Default.
 * @return string
 */
function old( $key, $default = '' ) {
	return isset( $_SESSION['old_input'][ $key ] ) ? (string) $_SESSION['old_input'][ $key ] : (string) $default;
}

/**
 * Clears old input (called after rendering).
 *
 * @return void
 */
function clear_old_input() {
	unset( $_SESSION['old_input'] );
}

/**
 * Builds pagination data.
 *
 * @param int $total    Total rows.
 * @param int $per_page Rows per page.
 * @param int $page     Current page.
 * @return array{page:int,per_page:int,pages:int,offset:int,total:int}
 */
function paginate( $total, $per_page, $page ) {
	$pages = max( 1, (int) ceil( $total / $per_page ) );
	$page  = max( 1, min( $pages, (int) $page ) );

	return [
		'page'     => $page,
		'per_page' => $per_page,
		'pages'    => $pages,
		'offset'   => ( $page - 1 ) * $per_page,
		'total'    => $total,
	];
}

/**
 * Renders Bootstrap pagination links preserving current GET filters.
 *
 * @param array  $pagination Result of paginate().
 * @param string $path       Page path.
 * @param array  $query      Current filters.
 * @return void
 */
function render_pagination( array $pagination, $path, array $query = [] ) {
	if ( 2 > $pagination['pages'] ) {
		return;
	}

	echo '<nav aria-label="Paginare"><ul class="pagination pagination-sm mb-0">';

	$start = max( 1, $pagination['page'] - 2 );
	$end   = min( $pagination['pages'], $pagination['page'] + 2 );

	if ( 1 < $pagination['page'] ) {
		echo '<li class="page-item"><a class="page-link" href="' . esc_url( app_url( $path, array_merge( $query, [ 'page' => $pagination['page'] - 1 ] ) ) ) . '">&laquo;</a></li>';
	}

	for ( $i = $start; $i <= $end; $i++ ) {
		$active = $i === $pagination['page'] ? ' active' : '';
		echo '<li class="page-item' . esc_attr( $active ) . '"><a class="page-link" href="' . esc_url( app_url( $path, array_merge( $query, [ 'page' => $i ] ) ) ) . '">' . esc_html( $i ) . '</a></li>';
	}

	if ( $pagination['page'] < $pagination['pages'] ) {
		echo '<li class="page-item"><a class="page-link" href="' . esc_url( app_url( $path, array_merge( $query, [ 'page' => $pagination['page'] + 1 ] ) ) ) . '">&raquo;</a></li>';
	}

	echo '</ul></nav>';
}

/**
 * Includes a template file with variables.
 *
 * @param string $name Template name without extension.
 * @param array  $vars Variables to extract.
 * @return void
 */
function render_template( $name, array $vars = [] ) {
	$file = REPUVIO_ROOT . '/templates/' . preg_replace( '/[^a-z0-9\-]/', '', $name ) . '.php';

	if ( ! is_file( $file ) ) {
		return;
	}

	extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
	require $file;
}

/**
 * Renders an error page and stops.
 *
 * @param int    $status  HTTP status code.
 * @param string $title   Title.
 * @param string $message Message.
 * @return never
 */
function render_error_page( $status, $title, $message ) {
	http_response_code( $status );
	render_template(
		'error',
		[
			'error_status'  => $status,
			'error_title'   => $title,
			'error_message' => $message,
		]
	);
	exit;
}

/**
 * Sends a JSON response and stops.
 *
 * @param array $data   Payload.
 * @param int   $status HTTP status.
 * @return never
 */
function send_json( array $data, $status = 200 ) {
	http_response_code( $status );
	header( 'Content-Type: application/json; charset=utf-8' );
	echo safe_json( $data );
	exit;
}

/**
 * Writes a line to a log file in storage/logs.
 *
 * @param string $file    Log file name.
 * @param string $message Message.
 * @return void
 */
function write_log( $file, $message ) {
	$file = preg_replace( '/[^a-z0-9\-_.]/', '', $file );
	$line = '[' . date( 'Y-m-d H:i:s' ) . '] ' . $message . PHP_EOL;

	@file_put_contents( REPUVIO_ROOT . '/storage/logs/' . $file, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

/**
 * Current datetime string for DB.
 *
 * @param string $modifier Optional strtotime modifier (e.g. '+30 days').
 * @return string
 */
function now_db( $modifier = '' ) {
	$date = new DateTime( 'now' );

	if ( '' !== $modifier ) {
		$date->modify( $modifier );
	}

	return $date->format( 'Y-m-d H:i:s' );
}
