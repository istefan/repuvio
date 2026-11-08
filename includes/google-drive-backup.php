<?php
/**
 * Google Drive Backup Manager (Super Admin → Backup Google Drive).
 *
 * Shared-hosting safe, no Composer, no exec():
 * - Google OAuth 2.0 (scope drive.file: the app only sees the files and folders it created);
 *   refresh / access tokens and the client secret are encrypted at rest like the SMTP password.
 * - Destination folder (default "ahoi-backups") found or created automatically.
 * - Pure-PHP streaming MySQL dump (PDO, 500-row batches) written straight into a .sql.gz file.
 * - Files archive: ZipArchive when available, otherwise a streaming pure-PHP ZIP writer. Previous
 *   backups, .git, node_modules, .zip / .gz / .log files are always excluded.
 * - Google Drive resumable upload in 2 MB chunks (memory stays flat whatever the file size).
 * - Retention: keeps the newest N database and N files archives in the folder, removes older ones.
 * - Scheduler for cron.php (daily / twice weekly / weekly / monthly at a chosen hour) and
 *   email alerts through the `admin_backup_failed` / `admin_backup_success` templates.
 *
 * Every run is logged in `backup_logs` (one row per archive).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

const GDRIVE_SCOPE      = 'https://www.googleapis.com/auth/drive.file';
const GDRIVE_API_URL    = 'https://www.googleapis.com/drive/v3';
const GDRIVE_UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';
const GDRIVE_CHUNK_SIZE = 2097152; // 2 MB, a multiple of 256 KB as required by Google.
const BACKUP_DIR        = 'storage/backups';
const BACKUP_DB_BATCH   = 500;

/*
 * -----------------------------------------------------------------------------
 * Settings
 * -----------------------------------------------------------------------------
 */

/**
 * Backup settings with their defaults.
 *
 * @return array
 */
function get_backup_defaults() {
	return [
		'gdrive_backup_enabled'       => '0',
		'gdrive_backup_db'            => '1',
		'gdrive_backup_files'         => '1',
		'gdrive_files_scope'          => 'uploads_and_config',
		'gdrive_frequency'            => 'daily',
		'gdrive_backup_hour'          => '3',
		'gdrive_retention_count'      => '14',
		'gdrive_keep_local'           => '0',
		'gdrive_folder_name'          => 'ahoi-backups',
		'gdrive_folder_id'            => '',
		'gdrive_account_email'        => '',
		'gdrive_use_mail_credentials' => '0',
		'gdrive_client_id'            => '',
		'gdrive_client_secret'        => '',
		'gdrive_refresh_token'        => '',
		'gdrive_access_token'         => '',
		'gdrive_token_expires'        => '0',
		'gdrive_next_backup_at'       => '',
		'gdrive_last_backup_at'       => '',
		'gdrive_last_backup_status'   => '',
	];
}

/**
 * Reads a backup setting with its default.
 *
 * @param string $key Setting key.
 * @return string
 */
function get_backup_setting( $key ) {
	return (string) get_setting( $key, get_backup_defaults()[ $key ] ?? '' );
}

/**
 * Backup frequencies (key => [ label, description ]).
 *
 * @return array
 */
function get_backup_frequencies() {
	return [
		'daily'        => [ 'Zilnic', 'În fiecare noapte' ],
		'twice_weekly' => [ 'De două ori pe săptămână', 'Lunea și joia' ],
		'weekly'       => [ 'Săptămânal', 'În fiecare duminică' ],
		'monthly'      => [ 'Lunar', 'Pe data de 1 a fiecărei luni' ],
	];
}

/**
 * Files backup scopes (key => [ label, description ]).
 *
 * @return array
 */
function get_backup_file_scopes() {
	return [
		'uploads_and_config' => [ 'Recomandat & rapid', 'Doar imaginile încărcate (/uploads/) + configurarea (/config/)' ],
		'full_app'           => [ 'Complet', 'Tot codul aplicației + /uploads/ + /config/' ],
	];
}

/**
 * Retention options (count => label; 0 = unlimited).
 *
 * @return array
 */
function get_backup_retention_options() {
	return [
		5  => 'Ultimele 5',
		10 => 'Ultimele 10',
		14 => 'Ultimele 14',
		30 => 'Ultimele 30',
		0  => 'Nelimitat',
	];
}

/**
 * Labels of backup types and triggers.
 *
 * @return array
 */
function get_backup_labels() {
	return [
		'db'     => 'Bază de date',
		'files'  => 'Fișiere',
		'full'   => 'Complet (bază de date + fișiere)',
		'cron'   => 'Automat (cron)',
		'manual' => 'Manual',
	];
}

/*
 * -----------------------------------------------------------------------------
 * HTTP + Google OAuth 2.0
 * -----------------------------------------------------------------------------
 */

/**
 * OAuth client credentials: dedicated ones, or the Gmail SMTP client when "reuse" is on.
 *
 * @return array{client_id:string,client_secret:string}
 */
function gdrive_credentials() {
	if ( '1' === get_backup_setting( 'gdrive_use_mail_credentials' ) ) {
		return [
			'client_id'     => (string) get_setting( 'google_client_id' ),
			'client_secret' => (string) get_setting( 'google_client_secret' ),
		];
	}

	return [
		'client_id'     => get_backup_setting( 'gdrive_client_id' ),
		'client_secret' => get_backup_setting( 'gdrive_client_secret' ),
	];
}

/**
 * Authorized redirect URI to register in Google Cloud Console.
 *
 * @return string
 */
function gdrive_redirect_uri() {
	return app_url( 'admin/gdrive-oauth.php' );
}

/**
 * Whether a Google Drive account is connected (refresh token stored).
 *
 * @return bool
 */
function gdrive_is_connected() {
	return '' !== get_backup_setting( 'gdrive_refresh_token' );
}

/**
 * Google consent URL (offline access, forced consent so a refresh token is always returned).
 *
 * @param string $state Single-use anti-CSRF token.
 * @return string
 */
function gdrive_authorize_url( $state ) {
	return GOOGLE_OAUTH_AUTH_URL . '?' . http_build_query(
		[
			'client_id'     => gdrive_credentials()['client_id'],
			'redirect_uri'  => gdrive_redirect_uri(),
			'response_type' => 'code',
			'scope'         => GDRIVE_SCOPE,
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => $state,
		],
		'',
		'&',
		PHP_QUERY_RFC3986
	);
}

/**
 * Generic HTTPS request with cURL.
 *
 * @param string          $method  HTTP method.
 * @param string          $url     URL.
 * @param array           $headers Header lines.
 * @param string|null     $body    Request body.
 * @param int             $timeout Seconds.
 * @return array{status:int,headers:array,body:string,error:string,json:mixed}
 */
function gdrive_http( $method, $url, array $headers = [], $body = null, $timeout = 60 ) {
	$response_headers = [];

	if ( ! function_exists( 'curl_init' ) ) {
		return [
			'status'  => 0,
			'headers' => [],
			'body'    => '',
			'error'   => 'Extensia PHP cURL nu este disponibilă pe server.',
			'json'    => null,
		];
	}

	$ch = curl_init( $url );

	curl_setopt_array(
		$ch,
		[
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_HEADERFUNCTION => function ( $handle, $line ) use ( &$response_headers ) {
				$parts = explode( ':', $line, 2 );

				if ( 2 === count( $parts ) ) {
					$response_headers[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
				}

				return strlen( $line );
			},
		]
	);

	if ( null !== $body ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
	}

	$result = curl_exec( $ch );
	$status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$error  = curl_error( $ch );
	$text   = false === $result ? '' : (string) $result;

	return [
		'status'  => $status,
		'headers' => $response_headers,
		'body'    => $text,
		'error'   => $error,
		'json'    => json_decode( $text, true ),
	];
}

/**
 * Readable error from a Google API response.
 *
 * @param array $response gdrive_http() result.
 * @return string
 */
function gdrive_error_message( array $response ) {
	$json = $response['json'];

	if ( is_array( $json ) ) {
		if ( isset( $json['error']['message'] ) ) {
			return 'Google Drive: ' . $json['error']['message'] . ' (HTTP ' . $response['status'] . ')';
		}

		if ( isset( $json['error_description'] ) ) {
			return 'Google: ' . $json['error_description'];
		}

		if ( isset( $json['error'] ) && is_string( $json['error'] ) ) {
			return 'Google: ' . $json['error'];
		}
	}

	return '' !== $response['error'] ? 'Conexiune eșuată: ' . $response['error'] : 'Răspuns neașteptat de la Google (HTTP ' . $response['status'] . ').';
}

/**
 * Exchanges the OAuth code, stores the encrypted tokens, the account email and resolves the folder.
 *
 * @param string $code Authorization code.
 * @return string Error message, empty on success.
 */
function gdrive_exchange_code( $code ) {
	$credentials = gdrive_credentials();
	$result      = http_post_form(
		GOOGLE_OAUTH_TOKEN_URL,
		[
			'code'          => $code,
			'client_id'     => $credentials['client_id'],
			'client_secret' => $credentials['client_secret'],
			'redirect_uri'  => gdrive_redirect_uri(),
			'grant_type'    => 'authorization_code',
		]
	);
	$data        = json_decode( $result['body'], true );

	if ( 200 !== $result['status'] || ! is_array( $data ) || empty( $data['access_token'] ) ) {
		return is_array( $data ) && isset( $data['error_description'] ) ? (string) $data['error_description'] : ( '' !== $result['error'] ? $result['error'] : 'Răspuns invalid de la Google.' );
	}

	if ( empty( $data['refresh_token'] ) ) {
		return 'Google nu a returnat un refresh token. Elimină accesul aplicației din contul Google (myaccount.google.com → Securitate → Aplicații terțe) și reconectează.';
	}

	update_setting( 'gdrive_refresh_token', (string) $data['refresh_token'] );
	update_setting( 'gdrive_access_token', (string) $data['access_token'] );
	update_setting( 'gdrive_token_expires', (string) ( time() + (int) ( $data['expires_in'] ?? 3600 ) ) );

	$about = gdrive_api( 'GET', '/about?fields=user(emailAddress,displayName)' );

	update_setting( 'gdrive_account_email', (string) ( $about['json']['user']['emailAddress'] ?? '' ) );

	$folder = gdrive_ensure_folder( true );

	return '' !== $folder['error'] ? 'Contul a fost conectat, dar folderul nu a putut fi pregătit: ' . $folder['error'] : '';
}

/**
 * Valid access token (refreshed when it expires in less than a minute).
 *
 * @param string $error Filled with the reason on failure.
 * @return string Empty on failure.
 */
function gdrive_access_token( &$error = '' ) {
	$token = get_backup_setting( 'gdrive_access_token' );

	if ( '' !== $token && (int) get_backup_setting( 'gdrive_token_expires' ) > time() + 60 ) {
		return $token;
	}

	if ( ! gdrive_is_connected() ) {
		$error = 'Contul Google Drive nu este conectat.';

		return '';
	}

	$credentials = gdrive_credentials();
	$result      = http_post_form(
		GOOGLE_OAUTH_TOKEN_URL,
		[
			'client_id'     => $credentials['client_id'],
			'client_secret' => $credentials['client_secret'],
			'refresh_token' => get_backup_setting( 'gdrive_refresh_token' ),
			'grant_type'    => 'refresh_token',
		]
	);
	$data        = json_decode( $result['body'], true );

	if ( 200 !== $result['status'] || ! is_array( $data ) || empty( $data['access_token'] ) ) {
		$error = 'Token-ul Google Drive nu a putut fi reîmprospătat (' . ( is_array( $data ) && isset( $data['error'] ) ? (string) $data['error'] : 'HTTP ' . $result['status'] ) . '). Reconectează contul din Backup Google Drive.';
		write_log( 'php-error.log', 'Google Drive token refresh failed: HTTP ' . $result['status'] . ' ' . mb_substr( $result['body'], 0, 300 ) );

		return '';
	}

	update_setting( 'gdrive_access_token', (string) $data['access_token'] );
	update_setting( 'gdrive_token_expires', (string) ( time() + (int) ( $data['expires_in'] ?? 3600 ) ) );

	return (string) $data['access_token'];
}

/**
 * Authenticated Drive API v3 call (JSON).
 *
 * @param string     $method HTTP method.
 * @param string     $path   Path after /drive/v3 (with query string).
 * @param array|null $json   JSON body.
 * @return array gdrive_http() result; status 0 + error when there is no token.
 */
function gdrive_api( $method, $path, $json = null ) {
	$error = '';
	$token = gdrive_access_token( $error );

	if ( '' === $token ) {
		return [
			'status'  => 0,
			'headers' => [],
			'body'    => '',
			'error'   => $error,
			'json'    => null,
		];
	}

	$headers = [ 'Authorization: Bearer ' . $token, 'Accept: application/json' ];

	if ( null !== $json ) {
		$headers[] = 'Content-Type: application/json; charset=UTF-8';
	}

	return gdrive_http( $method, GDRIVE_API_URL . $path, $headers, null === $json ? null : (string) json_encode( $json, JSON_UNESCAPED_UNICODE ) );
}

/**
 * Revokes the token at Google (best effort) and forgets the connection.
 *
 * @return void
 */
function gdrive_disconnect() {
	$token = get_backup_setting( 'gdrive_refresh_token' );

	if ( '' !== $token ) {
		http_post_form( 'https://oauth2.googleapis.com/revoke', [ 'token' => $token ] );
	}

	foreach ( [ 'gdrive_refresh_token', 'gdrive_access_token', 'gdrive_account_email', 'gdrive_folder_id' ] as $key ) {
		update_setting( $key, '' );
	}

	update_setting( 'gdrive_token_expires', '0' );
}

/*
 * -----------------------------------------------------------------------------
 * Drive folder, upload, listing, retention
 * -----------------------------------------------------------------------------
 */

/**
 * Finds (or creates) the destination folder and stores its ID.
 *
 * @param bool $lookup Ignore the stored ID and search by name first.
 * @return array{id:string,created:bool,error:string}
 */
function gdrive_ensure_folder( $lookup = false ) {
	$name   = trim( get_backup_setting( 'gdrive_folder_name' ) );
	$name   = '' !== $name ? $name : 'ahoi-backups';
	$stored = get_backup_setting( 'gdrive_folder_id' );

	if ( ! $lookup && '' !== $stored ) {
		$check = gdrive_api( 'GET', '/files/' . rawurlencode( $stored ) . '?fields=id,trashed' );

		if ( 200 === $check['status'] && empty( $check['json']['trashed'] ) ) {
			return [
				'id'      => $stored,
				'created' => false,
				'error'   => '',
			];
		}

		if ( 0 === $check['status'] ) {
			return [
				'id'      => '',
				'created' => false,
				'error'   => gdrive_error_message( $check ),
			];
		}
	}

	$query  = "name = '" . str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $name ) . "' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
	$search = gdrive_api( 'GET', '/files?' . http_build_query( [ 'q' => $query, 'fields' => 'files(id,name)', 'pageSize' => 10, 'spaces' => 'drive' ], '', '&', PHP_QUERY_RFC3986 ) );

	if ( 200 !== $search['status'] ) {
		return [
			'id'      => '',
			'created' => false,
			'error'   => gdrive_error_message( $search ),
		];
	}

	if ( ! empty( $search['json']['files'][0]['id'] ) ) {
		update_setting( 'gdrive_folder_id', (string) $search['json']['files'][0]['id'] );

		return [
			'id'      => (string) $search['json']['files'][0]['id'],
			'created' => false,
			'error'   => '',
		];
	}

	$create = gdrive_api(
		'POST',
		'/files?fields=id',
		[
			'name'     => $name,
			'mimeType' => 'application/vnd.google-apps.folder',
		]
	);

	if ( 200 !== $create['status'] || empty( $create['json']['id'] ) ) {
		return [
			'id'      => '',
			'created' => false,
			'error'   => gdrive_error_message( $create ),
		];
	}

	update_setting( 'gdrive_folder_id', (string) $create['json']['id'] );

	return [
		'id'      => (string) $create['json']['id'],
		'created' => true,
		'error'   => '',
	];
}

/**
 * Web URL of the destination folder.
 *
 * @return string Empty when unknown.
 */
function gdrive_folder_url() {
	$id = get_backup_setting( 'gdrive_folder_id' );

	return '' !== $id ? 'https://drive.google.com/drive/folders/' . rawurlencode( $id ) : '';
}

/**
 * Uploads a local file with the Drive resumable protocol, in GDRIVE_CHUNK_SIZE chunks.
 *
 * @param string $path      Local file.
 * @param string $name      File name in Drive.
 * @param string $mime      MIME type.
 * @param string $folder_id Parent folder ID.
 * @return array{id:string,web_link:string,error:string}
 */
function gdrive_upload_file( $path, $name, $mime, $folder_id ) {
	$fail  = function ( $message ) {
		return [
			'id'       => '',
			'web_link' => '',
			'error'    => $message,
		];
	};
	$size  = (int) filesize( $path );
	$error = '';
	$token = gdrive_access_token( $error );

	if ( '' === $token ) {
		return $fail( $error );
	}

	$session = gdrive_http(
		'POST',
		GDRIVE_UPLOAD_URL . '?uploadType=resumable&fields=id,webViewLink',
		[
			'Authorization: Bearer ' . $token,
			'Content-Type: application/json; charset=UTF-8',
			'X-Upload-Content-Type: ' . $mime,
			'X-Upload-Content-Length: ' . $size,
		],
		(string) json_encode(
			[
				'name'    => $name,
				'parents' => [ $folder_id ],
			]
		)
	);

	if ( 200 !== $session['status'] || empty( $session['headers']['location'] ) ) {
		return $fail( gdrive_error_message( $session ) );
	}

	$upload_url = $session['headers']['location'];
	$handle     = fopen( $path, 'rb' );

	if ( false === $handle ) {
		return $fail( 'Fișierul local nu poate fi citit.' );
	}

	$offset = 0;

	try {
		while ( $offset < $size || 0 === $size ) {
			$chunk = 0 === $size ? '' : (string) fread( $handle, GDRIVE_CHUNK_SIZE );
			$end   = $offset + strlen( $chunk ) - 1;
			$range = 0 === $size ? 'bytes */0' : 'bytes ' . $offset . '-' . $end . '/' . $size;

			// Access tokens live 1 hour: refresh between chunks of very large uploads.
			$token    = gdrive_access_token( $error );
			$response = gdrive_http(
				'PUT',
				$upload_url,
				[
					'Authorization: Bearer ' . $token,
					'Content-Length: ' . strlen( $chunk ),
					'Content-Range: ' . $range,
				],
				$chunk,
				300
			);

			if ( 308 === $response['status'] ) {
				// Resume Incomplete: continue from the byte Google confirmed.
				$confirmed = isset( $response['headers']['range'] ) && preg_match( '/bytes=0-(\d+)/', $response['headers']['range'], $match ) ? (int) $match[1] + 1 : $offset + strlen( $chunk );

				if ( $confirmed !== $offset + strlen( $chunk ) ) {
					fseek( $handle, $confirmed );
				}

				$offset = $confirmed;
				continue;
			}

			if ( 200 === $response['status'] || 201 === $response['status'] ) {
				return [
					'id'       => (string) ( $response['json']['id'] ?? '' ),
					'web_link' => (string) ( $response['json']['webViewLink'] ?? '' ),
					'error'    => '',
				];
			}

			return $fail( gdrive_error_message( $response ) );
		}
	} finally {
		fclose( $handle );
	}

	return $fail( 'Încărcarea s-a oprit înainte de final.' );
}

/**
 * Backup archives in the destination folder, newest first.
 *
 * @param string $folder_id Folder ID.
 * @return array|null Files (id, name, size, createdTime, webViewLink) or null on error.
 */
function gdrive_list_backup_files( $folder_id ) {
	$files = [];
	$page  = '';

	do {
		$query    = [
			'q'        => "'" . $folder_id . "' in parents and trashed = false",
			'orderBy'  => 'createdTime desc',
			'fields'   => 'nextPageToken,files(id,name,size,createdTime,webViewLink)',
			'pageSize' => 1000,
		];
		$query    = '' !== $page ? array_merge( $query, [ 'pageToken' => $page ] ) : $query;
		$response = gdrive_api( 'GET', '/files?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) );

		if ( 200 !== $response['status'] ) {
			return null;
		}

		$files = array_merge( $files, $response['json']['files'] ?? [] );
		$page  = (string) ( $response['json']['nextPageToken'] ?? '' );
	} while ( '' !== $page );

	return $files;
}

/**
 * Deletes a Drive file (404 counts as already deleted).
 *
 * @param string $file_id File ID.
 * @return bool
 */
function gdrive_delete_file( $file_id ) {
	$response = gdrive_api( 'DELETE', '/files/' . rawurlencode( $file_id ) );

	return in_array( $response['status'], [ 200, 204, 404 ], true );
}

/**
 * Keeps the newest N database and N files archives in the folder and deletes the rest
 * (also marks them in backup_logs). Nothing happens with retention "Nelimitat" (0).
 *
 * @return int Deleted files.
 */
function gdrive_apply_retention() {
	$keep      = (int) get_backup_setting( 'gdrive_retention_count' );
	$folder_id = get_backup_setting( 'gdrive_folder_id' );

	if ( 0 >= $keep || '' === $folder_id ) {
		return 0;
	}

	$files = gdrive_list_backup_files( $folder_id );

	if ( null === $files ) {
		return 0;
	}

	$seen    = [
		'db'    => 0,
		'files' => 0,
	];
	$deleted = 0;

	foreach ( $files as $file ) {
		$type = backup_type_from_name( (string) $file['name'] );

		// Only archives made by this backup manager are rotated.
		if ( '' === $type ) {
			continue;
		}

		++$seen[ $type ];

		if ( $seen[ $type ] > $keep && gdrive_delete_file( (string) $file['id'] ) ) {
			db_query( 'UPDATE backup_logs SET gdrive_deleted_at = NOW() WHERE gdrive_file_id = ?', [ (string) $file['id'] ] );
			++$deleted;
		}
	}

	return $deleted;
}

/*
 * -----------------------------------------------------------------------------
 * Local archives (storage/backups)
 * -----------------------------------------------------------------------------
 */

/**
 * Absolute path of the local backup folder (created and protected on first use).
 *
 * @return string
 * @throws RuntimeException When the folder is not writable.
 */
function backup_dir() {
	$dir = REPUVIO_ROOT . '/' . BACKUP_DIR;

	if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		throw new RuntimeException( 'Folderul storage/backups nu poate fi creat. Verifică permisiunile pentru storage/.' );
	}

	if ( ! is_file( $dir . '/.htaccess' ) ) {
		file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n" );
		file_put_contents( $dir . '/index.html', '' );
	}

	if ( ! is_writable( $dir ) ) {
		throw new RuntimeException( 'Folderul storage/backups nu poate fi scris de PHP.' );
	}

	return $dir;
}

/**
 * Archive file name: {site}-{db|files}-{Y-m-d_His}-{random}.{ext}.
 *
 * @param string $type db|files.
 * @return string
 */
function backup_file_name( $type ) {
	$site = sanitize_slug( (string) parse_url( APP_URL, PHP_URL_HOST ), 40 );
	$site = '' !== $site ? $site : 'site';

	return $site . '-' . $type . '-' . date( 'Y-m-d_His' ) . '-' . bin2hex( random_bytes( 3 ) ) . ( 'db' === $type ? '.sql.gz' : '.zip' );
}

/**
 * Archive type from a file name ("" when it is not one of ours).
 *
 * @param string $name File name.
 * @return string db|files|''
 */
function backup_type_from_name( $name ) {
	if ( preg_match( '/-(db)-\d{4}-\d{2}-\d{2}_\d{6}-[a-f0-9]{6}\.sql\.gz$/', $name ) ) {
		return 'db';
	}

	if ( preg_match( '/-(files)-\d{4}-\d{2}-\d{2}_\d{6}-[a-f0-9]{6}\.zip$/', $name ) ) {
		return 'files';
	}

	return '';
}

/**
 * Deletes local archives: with "keep local" only the newest archive of each type stays,
 * otherwise every archive that was uploaded is removed (failed uploads are kept 7 days).
 *
 * @param array $uploaded File names uploaded successfully in this run.
 * @return void
 */
function prune_local_backups( array $uploaded = [] ) {
	$dir   = backup_dir();
	$files = glob( $dir . '/*.{gz,zip}', GLOB_BRACE ) ?: [];
	$keep  = '1' === get_backup_setting( 'gdrive_keep_local' );

	usort(
		$files,
		function ( $a, $b ) {
			return filemtime( $b ) <=> filemtime( $a );
		}
	);

	$newest = [];

	foreach ( $files as $file ) {
		$name = basename( $file );
		$type = backup_type_from_name( $name );

		if ( '' === $type ) {
			continue;
		}

		if ( $keep && ! isset( $newest[ $type ] ) ) {
			$newest[ $type ] = true;
			continue;
		}

		if ( $keep || in_array( $name, $uploaded, true ) || filemtime( $file ) < time() - 7 * 86400 ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}
}

/**
 * Human readable size (KB / MB / GB, Romanian decimals).
 *
 * @param int $bytes Bytes.
 * @return string
 */
function format_bytes( $bytes ) {
	$bytes = (float) $bytes;

	if ( 1073741824 <= $bytes ) {
		return number_format( $bytes / 1073741824, 2, ',', '.' ) . ' GB';
	}

	if ( 1048576 <= $bytes ) {
		return number_format( $bytes / 1048576, 1, ',', '.' ) . ' MB';
	}

	return number_format( max( 1, $bytes / 1024 ), 0, ',', '.' ) . ' KB';
}

/*
 * -----------------------------------------------------------------------------
 * Generators: streaming SQL dump + ZIP archive
 * -----------------------------------------------------------------------------
 */

/**
 * Streams a full MySQL dump into a gzip file (no exec(), constant memory).
 *
 * @param string $path Target .sql.gz path.
 * @return array{tables:int,rows:int}
 * @throws RuntimeException On write errors.
 */
function dump_database_to_gzip( $path ) {
	$gz = gzopen( $path, 'wb6' );

	if ( false === $gz ) {
		throw new RuntimeException( 'Fișierul de backup nu poate fi creat.' );
	}

	$pdo    = db();
	$tables = db_all( "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'" );
	$stats  = [
		'tables' => 0,
		'rows'   => 0,
	];

	try {
		gzwrite(
			$gz,
			"-- RepuVio database backup\n"
			. '-- Generated: ' . date( 'Y-m-d H:i:s' ) . ' (' . date_default_timezone_get() . ")\n"
			. '-- Application: ' . app_name() . ' ' . REPUVIO_VERSION . ' · schema ' . (string) db_value( "SELECT setting_value FROM settings WHERE setting_key = 'schema_version'" ) . "\n"
			. '-- Database: ' . DB_NAME . "\n\n"
			. "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET time_zone='+00:00';\n\n"
		);

		foreach ( $tables as $table_row ) {
			$table  = (string) array_values( $table_row )[0];
			$quoted = '`' . str_replace( '`', '``', $table ) . '`';
			$create = db_row( 'SHOW CREATE TABLE ' . $quoted );
			$keys   = array_column( db_all( "SHOW KEYS FROM {$quoted} WHERE Key_name = 'PRIMARY'" ), 'Column_name' );
			$order  = empty( $keys ) ? '' : ' ORDER BY ' . implode( ', ', array_map( fn( $key ) => '`' . str_replace( '`', '``', $key ) . '`', $keys ) );

			gzwrite( $gz, "--\n-- Table {$quoted}\n--\n\nDROP TABLE IF EXISTS {$quoted};\n" . $create['Create Table'] . ";\n\n" );

			for ( $offset = 0; ; $offset += BACKUP_DB_BATCH ) {
				$rows = $pdo->query( 'SELECT * FROM ' . $quoted . $order . ' LIMIT ' . (int) $offset . ', ' . BACKUP_DB_BATCH )->fetchAll( PDO::FETCH_ASSOC );

				if ( empty( $rows ) ) {
					break;
				}

				$columns = '(`' . implode( '`, `', array_map( fn( $column ) => str_replace( '`', '``', $column ), array_keys( $rows[0] ) ) ) . '`)';
				$values  = [];

				foreach ( $rows as $row ) {
					$values[] = '(' . implode( ', ', array_map( fn( $value ) => null === $value ? 'NULL' : $pdo->quote( (string) $value ), $row ) ) . ')';
				}

				if ( false === gzwrite( $gz, 'INSERT INTO ' . $quoted . ' ' . $columns . " VALUES\n" . implode( ",\n", $values ) . ";\n" ) ) {
					throw new RuntimeException( 'Scrierea backup-ului a eșuat (spațiu pe disc?).' );
				}

				$stats['rows'] += count( $rows );

				if ( BACKUP_DB_BATCH > count( $rows ) ) {
					break;
				}
			}

			gzwrite( $gz, "\n" );
			++$stats['tables'];
		}

		gzwrite( $gz, "SET FOREIGN_KEY_CHECKS=1;\n-- End of backup\n" );
	} finally {
		gzclose( $gz );
	}

	return $stats;
}

/**
 * Whether a relative path is excluded from the files archive.
 *
 * @param string $relative Path relative to the app root (forward slashes).
 * @param bool   $is_dir   Directory.
 * @return bool
 */
function backup_path_excluded( $relative, $is_dir ) {
	$relative = ltrim( str_replace( '\\', '/', $relative ), '/' );

	foreach ( [ BACKUP_DIR, '.git', 'node_modules', '.claude', '.idea', '.vscode' ] as $excluded ) {
		if ( $relative === $excluded || 0 === strpos( $relative, $excluded . '/' ) ) {
			return true;
		}
	}

	return ! $is_dir && (bool) preg_match( '/\.(zip|gz|tgz|log|tmp|bak|swp)$/i', $relative );
}

/**
 * Files to archive for a scope (relative path => absolute path).
 *
 * @param string $scope uploads_and_config|full_app.
 * @return array
 */
function collect_backup_files( $scope ) {
	$roots = 'full_app' === $scope ? [ '' ] : [ 'uploads', 'config' ];
	$files = [];

	foreach ( $roots as $root ) {
		$base = rtrim( REPUVIO_ROOT . '/' . $root, '/' );

		if ( ! is_dir( $base ) ) {
			continue;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
				function ( $item ) {
					$relative = substr( str_replace( '\\', '/', $item->getPathname() ), strlen( str_replace( '\\', '/', REPUVIO_ROOT ) ) + 1 );

					return ! $item->isLink() && ! backup_path_excluded( $relative, $item->isDir() );
				}
			)
		);

		foreach ( $iterator as $item ) {
			if ( $item->isFile() && $item->isReadable() ) {
				$relative           = substr( str_replace( '\\', '/', $item->getPathname() ), strlen( str_replace( '\\', '/', REPUVIO_ROOT ) ) + 1 );
				$files[ $relative ] = $item->getPathname();
			}
		}
	}

	ksort( $files );

	return $files;
}

/**
 * Builds the files archive: ZipArchive when available, otherwise a streaming pure-PHP writer.
 *
 * @param string $path  Target .zip path.
 * @param string $scope uploads_and_config|full_app.
 * @return array{files:int,bytes:int}
 * @throws RuntimeException On errors.
 */
function build_files_backup_zip( $path, $scope ) {
	$files = collect_backup_files( $scope );
	$bytes = 0;

	if ( empty( $files ) ) {
		throw new RuntimeException( 'Nu există fișiere de salvat pentru opțiunea aleasă.' );
	}

	if ( class_exists( 'ZipArchive' ) ) {
		$zip = new ZipArchive();

		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'Arhiva ZIP nu poate fi creată.' );
		}

		foreach ( $files as $relative => $absolute ) {
			$zip->addFile( $absolute, $relative );
			$bytes += (int) filesize( $absolute );
		}

		if ( ! $zip->close() ) {
			throw new RuntimeException( 'Arhiva ZIP nu a putut fi finalizată (spațiu pe disc?).' );
		}
	} else {
		$bytes = stream_zip_files( $path, $files );
	}

	return [
		'files' => count( $files ),
		'bytes' => $bytes,
	];
}

/**
 * Pure-PHP streaming ZIP writer (fallback when ZipArchive is missing). Files up to 16 MB are
 * deflated in memory one at a time; bigger files are stored uncompressed and streamed in chunks.
 * Archives are limited to 4 GB (no ZIP64), plenty for uploads + code.
 *
 * @param string $path  Target path.
 * @param array  $files relative => absolute.
 * @return int Uncompressed bytes.
 * @throws RuntimeException On errors.
 */
function stream_zip_files( $path, array $files ) {
	$out = fopen( $path, 'wb' );

	if ( false === $out ) {
		throw new RuntimeException( 'Arhiva ZIP nu poate fi creată.' );
	}

	$central = '';
	$count   = 0;
	$offset  = 0;
	$total   = 0;

	try {
		foreach ( $files as $relative => $absolute ) {
			$size     = (int) filesize( $absolute );
			$mtime    = getdate( (int) filemtime( $absolute ) );
			$dos_time = ( $mtime['hours'] << 11 ) | ( $mtime['minutes'] << 5 ) | intdiv( $mtime['seconds'], 2 );
			$dos_date = ( ( max( 1980, $mtime['year'] ) - 1980 ) << 9 ) | ( $mtime['mon'] << 5 ) | $mtime['mday'];
			$name     = (string) $relative;

			if ( 16777216 >= $size ) {
				$contents   = (string) file_get_contents( $absolute );
				$crc        = crc32( $contents );
				$data       = (string) gzdeflate( $contents, 6 );
				$method     = 8;
				$compressed = strlen( $data );
				unset( $contents );
			} else {
				$crc        = (int) hexdec( hash_file( 'crc32b', $absolute ) );
				$data       = null;
				$method     = 0;
				$compressed = $size;
			}

			$header = pack( 'VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, $dos_time, $dos_date, $crc, $compressed, $size, strlen( $name ), 0 ) . $name;

			fwrite( $out, $header );

			if ( null !== $data ) {
				fwrite( $out, $data );
			} else {
				$in = fopen( $absolute, 'rb' );

				while ( ! feof( $in ) ) {
					fwrite( $out, (string) fread( $in, 1048576 ) );
				}

				fclose( $in );
			}

			$central .= pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $method, $dos_time, $dos_date, $crc, $compressed, $size, strlen( $name ), 0, 0, 0, 0, 32, $offset ) . $name;
			$offset  += strlen( $header ) + $compressed;
			$total   += $size;
			++$count;

			if ( 4294967295 <= $offset ) {
				throw new RuntimeException( 'Arhiva depășește 4 GB. Alege „Doar /uploads/ + /config/” sau instalează extensia PHP zip.' );
			}
		}

		fwrite( $out, $central . pack( 'VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen( $central ), $offset, 0 ) );
	} finally {
		fclose( $out );
	}

	return $total;
}

/*
 * -----------------------------------------------------------------------------
 * Runner, scheduler, logs
 * -----------------------------------------------------------------------------
 */

/**
 * Inserts a backup_logs row.
 *
 * @param string $type        db|files|full.
 * @param string $trigger     cron|manual.
 * @param string $destination gdrive|download.
 * @return int Log ID.
 */
function backup_log_start( $type, $trigger, $destination = 'gdrive' ) {
	db_query(
		"INSERT INTO backup_logs (backup_type, trigger_type, destination, status) VALUES (?, ?, ?, 'running')",
		[ $type, $trigger, $destination ]
	);

	return db_insert_id();
}

/**
 * Updates a backup_logs row.
 *
 * @param int   $log_id Log ID.
 * @param array $fields Column => value.
 * @return void
 */
function backup_log_update( $log_id, array $fields ) {
	$allowed = [ 'file_name', 'file_size_bytes', 'gdrive_file_id', 'gdrive_web_link', 'status', 'duration_ms', 'error_message' ];
	$fields  = array_intersect_key( $fields, array_flip( $allowed ) );

	if ( empty( $fields ) ) {
		return;
	}

	db_query(
		'UPDATE backup_logs SET ' . implode( ', ', array_map( fn( $column ) => $column . ' = ?', array_keys( $fields ) ) ) . ' WHERE id = ?',
		array_merge( array_values( $fields ), [ (int) $log_id ] )
	);
}

/**
 * Runs a backup: generates the archives, uploads them to Google Drive, applies retention,
 * prunes local files, logs everything and emails the admin on failure (cron) or success.
 *
 * @param string $type    full|db|files.
 * @param string $trigger cron|manual.
 * @return array{success:bool,error:string,results:array}
 */
function run_backup( $type, $trigger = 'manual' ) {
	$parts   = 'full' === $type ? [ 'db', 'files' ] : [ $type ];
	$results = [];
	$errors  = [];
	$labels  = get_backup_labels();

	if ( 1 !== (int) db_value( "SELECT GET_LOCK('repuvio_backup', 0)" ) ) {
		return [
			'success' => false,
			'error'   => 'Un backup rulează deja. Încearcă din nou peste câteva minute.',
			'results' => [],
		];
	}

	@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	ignore_user_abort( true );

	try {
		$folder = gdrive_is_connected() ? gdrive_ensure_folder() : [
			'id'    => '',
			'error' => 'Contul Google Drive nu este conectat.',
		];

		foreach ( $parts as $part ) {
			$log_id = backup_log_start( $part, $trigger );
			$start  = microtime( true );
			$path   = '';

			try {
				if ( '' !== $folder['error'] ) {
					throw new RuntimeException( $folder['error'] );
				}

				$name = backup_file_name( $part );
				$path = backup_dir() . '/' . $name;

				if ( 'db' === $part ) {
					dump_database_to_gzip( $path );
				} else {
					build_files_backup_zip( $path, get_backup_setting( 'gdrive_files_scope' ) );
				}

				$size = (int) filesize( $path );

				backup_log_update(
					$log_id,
					[
						'file_name'       => $name,
						'file_size_bytes' => $size,
					]
				);

				$upload = gdrive_upload_file( $path, $name, 'db' === $part ? 'application/gzip' : 'application/zip', $folder['id'] );

				if ( '' !== $upload['error'] ) {
					throw new RuntimeException( $upload['error'] );
				}

				backup_log_update(
					$log_id,
					[
						'gdrive_file_id'  => $upload['id'],
						'gdrive_web_link' => $upload['web_link'],
						'status'          => 'success',
						'duration_ms'     => (int) round( ( microtime( true ) - $start ) * 1000 ),
					]
				);

				$results[] = [
					'type'     => $part,
					'name'     => $name,
					'size'     => $size,
					'web_link' => $upload['web_link'],
					'success'  => true,
				];
			} catch ( Throwable $e ) {
				backup_log_update(
					$log_id,
					[
						'status'        => 'failed',
						'error_message' => mb_substr( $e->getMessage(), 0, 2000 ),
						'duration_ms'   => (int) round( ( microtime( true ) - $start ) * 1000 ),
					]
				);

				$errors[]  = $labels[ $part ] . ': ' . $e->getMessage();
				$results[] = [
					'type'    => $part,
					'name'    => '' !== $path ? basename( $path ) : '',
					'success' => false,
					'error'   => $e->getMessage(),
				];
			}
		}

		$uploaded = array_column( array_filter( $results, fn( $result ) => $result['success'] ), 'name' );

		if ( ! empty( $uploaded ) ) {
			gdrive_apply_retention();
		}

		try {
			prune_local_backups( $uploaded );
		} catch ( Throwable $e ) {
			write_log( 'php-error.log', 'Backup local prune failed: ' . $e->getMessage() );
		}
	} finally {
		db_value( "SELECT RELEASE_LOCK('repuvio_backup')" );
	}

	$success = empty( $errors );

	update_setting( 'gdrive_last_backup_at', now_db() );
	update_setting( 'gdrive_last_backup_status', $success ? 'success' : 'failed' );
	send_backup_notification( $type, $trigger, $success, $results, implode( "\n", $errors ) );

	return [
		'success' => $success,
		'error'   => implode( ' · ', $errors ),
		'results' => $results,
	];
}

/**
 * Emails the platform admins: `admin_backup_failed` on failure, `admin_backup_success` on success
 * (switched off by default). Both can be edited / toggled in Email & Șabloane.
 *
 * @param string $type    full|db|files.
 * @param string $trigger cron|manual.
 * @param bool   $success Result.
 * @param array  $results run_backup() results.
 * @param string $error   Error text.
 * @return void
 */
function send_backup_notification( $type, $trigger, $success, array $results, $error ) {
	$recipients = get_admin_notification_recipients();
	$labels     = get_backup_labels();

	if ( empty( $recipients ) ) {
		return;
	}

	$common = [
		'backup_label'  => $labels[ $type ] ?? $type,
		'trigger_label' => $labels[ $trigger ] ?? $trigger,
		'started_at'    => format_datetime( now_db() ),
		'folder_name'   => get_backup_setting( 'gdrive_folder_name' ),
		'folder_url'    => gdrive_folder_url(),
		'backups_url'   => app_url( 'admin/backups.php' ),
	];

	if ( ! $success ) {
		send_templated_email( 'admin_backup_failed', $recipients, array_merge( $common, [ 'error_message' => $error ] ) );

		return;
	}

	$rows  = '';
	$total = 0;

	foreach ( $results as $result ) {
		$total += (int) ( $result['size'] ?? 0 );
		$rows  .= '<tr><td style="border-top:1px solid #E2E8F0;">' . esc_html( $labels[ $result['type'] ] ?? $result['type'] ) . '</td>'
			. '<td style="border-top:1px solid #E2E8F0;font-size:12px;">' . esc_html( $result['name'] ) . '</td>'
			. '<td align="right" style="border-top:1px solid #E2E8F0;">' . esc_html( format_bytes( (int) ( $result['size'] ?? 0 ) ) ) . '</td></tr>';
	}

	send_templated_email(
		'admin_backup_success',
		$recipients,
		array_merge(
			$common,
			[
				'files_html' => '<table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="border:1px solid #E2E8F0;border-radius:10px;font-size:13px;border-collapse:separate;">' . $rows . '</table>',
				'total_size' => format_bytes( $total ),
			]
		)
	);
}

/**
 * Next scheduled run after a moment, for the configured frequency and hour.
 *
 * @param int $from Unix timestamp.
 * @return string DB datetime.
 */
function compute_next_backup_at( $from = 0 ) {
	$from      = 0 < $from ? $from : time();
	$hour      = max( 0, min( 23, (int) get_backup_setting( 'gdrive_backup_hour' ) ) );
	$frequency = get_backup_setting( 'gdrive_frequency' );

	for ( $day = 0; 62 >= $day; $day++ ) {
		$candidate = mktime( $hour, 0, 0, (int) date( 'n', $from ), (int) date( 'j', $from ) + $day, (int) date( 'Y', $from ) );

		if ( $candidate <= $from ) {
			continue;
		}

		$weekday = (int) date( 'N', $candidate );

		if ( 'daily' === $frequency
			|| ( 'twice_weekly' === $frequency && in_array( $weekday, [ 1, 4 ], true ) )
			|| ( 'weekly' === $frequency && 7 === $weekday )
			|| ( 'monthly' === $frequency && 1 === (int) date( 'j', $candidate ) ) ) {
			return date( 'Y-m-d H:i:s', $candidate );
		}
	}

	return date( 'Y-m-d H:i:s', $from + 86400 );
}

/**
 * Cron entry: runs the scheduled backup when it is due.
 *
 * @return array|null run_backup() result, or null when nothing ran.
 */
function maybe_run_scheduled_backup() {
	if ( '1' !== get_backup_setting( 'gdrive_backup_enabled' ) ) {
		return null;
	}

	$next = get_backup_setting( 'gdrive_next_backup_at' );

	if ( '' === $next ) {
		update_setting( 'gdrive_next_backup_at', compute_next_backup_at() );

		return null;
	}

	if ( time() < strtotime( $next ) ) {
		return null;
	}

	// Schedule the following run first, so an overlapping cron never starts the same backup twice.
	update_setting( 'gdrive_next_backup_at', compute_next_backup_at( time() + 60 ) );

	$db    = '1' === get_backup_setting( 'gdrive_backup_db' );
	$files = '1' === get_backup_setting( 'gdrive_backup_files' );

	if ( ! $db && ! $files ) {
		return null;
	}

	return run_backup( $db && $files ? 'full' : ( $db ? 'db' : 'files' ), 'cron' );
}

/**
 * Streams a fresh .sql.gz dump to the browser (no Google Drive needed) and logs it.
 *
 * @return void Exits after sending the file.
 */
function stream_database_backup_download() {
	@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

	$log_id = backup_log_start( 'db', 'manual', 'download' );
	$start  = microtime( true );
	$name   = backup_file_name( 'db' );
	$path   = backup_dir() . '/' . $name;

	try {
		dump_database_to_gzip( $path );
	} catch ( Throwable $e ) {
		backup_log_update(
			$log_id,
			[
				'status'        => 'failed',
				'error_message' => $e->getMessage(),
			]
		);
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		flash( 'danger', 'Exportul bazei de date a eșuat: ' . $e->getMessage() );
		redirect( app_url( 'admin/backups.php' ) );
	}

	$size = (int) filesize( $path );

	backup_log_update(
		$log_id,
		[
			'file_name'       => $name,
			'file_size_bytes' => $size,
			'status'          => 'success',
			'duration_ms'     => (int) round( ( microtime( true ) - $start ) * 1000 ),
		]
	);
	audit_log( 'admin_backup_download', $name );

	while ( ob_get_level() ) {
		ob_end_clean();
	}

	header( 'Content-Type: application/gzip' );
	header( 'Content-Disposition: attachment; filename="' . $name . '"' );
	header( 'Content-Length: ' . $size );
	send_no_cache_headers();
	readfile( $path );
	@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	exit;
}

/**
 * Deletes a log row with its Drive file and local copy.
 *
 * @param int $log_id Log ID.
 * @return bool
 */
function delete_backup_log( $log_id ) {
	$row = db_row( 'SELECT * FROM backup_logs WHERE id = ?', [ (int) $log_id ] );

	if ( ! $row ) {
		return false;
	}

	if ( '' !== (string) $row['gdrive_file_id'] && null === $row['gdrive_deleted_at'] && gdrive_is_connected() ) {
		gdrive_delete_file( (string) $row['gdrive_file_id'] );
	}

	if ( '' !== (string) $row['file_name'] && '' !== backup_type_from_name( (string) $row['file_name'] ) ) {
		$local = REPUVIO_ROOT . '/' . BACKUP_DIR . '/' . basename( (string) $row['file_name'] );

		if ( is_file( $local ) ) {
			@unlink( $local ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	db_query( 'DELETE FROM backup_logs WHERE id = ?', [ (int) $log_id ] );

	return true;
}
