<?php
/**
 * Secure image upload pipeline (finfo + getimagesize validation, GD re-encode, EXIF strip).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

const UPLOAD_MAX_BYTES  = 5242880; // 5 MB.
const UPLOAD_MAX_PIXELS = 6000;
const UPLOAD_DIR        = 'uploads/companies';

/**
 * Validates, re-encodes and stores an uploaded image.
 *
 * @param array  $file    Entry from $_FILES.
 * @param string $profile 'logo' (max 600px, keeps transparency) or 'cover' (max 1920px).
 * @return array{path:string,error:string} Relative path on success, error message otherwise.
 */
function process_image_upload( array $file, $profile ) {
	$result = [
		'path'  => '',
		'error' => '',
	];

	if ( ! isset( $file['error'] ) || is_array( $file['error'] ) ) {
		$result['error'] = 'Încărcare invalidă.';

		return $result;
	}

	if ( UPLOAD_ERR_NO_FILE === $file['error'] ) {
		return $result;
	}

	if ( UPLOAD_ERR_OK !== $file['error'] ) {
		$result['error'] = in_array( $file['error'], [ UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ], true ) ? 'Fișierul este prea mare (max. 5 MB).' : 'Încărcarea a eșuat. Încearcă din nou.';

		return $result;
	}

	if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
		$result['error'] = 'Încărcare invalidă.';

		return $result;
	}

	if ( UPLOAD_MAX_BYTES < (int) $file['size'] || UPLOAD_MAX_BYTES < filesize( $file['tmp_name'] ) ) {
		$result['error'] = 'Fișierul este prea mare (max. 5 MB).';

		return $result;
	}

	$allowed = [
		'image/jpeg' => IMAGETYPE_JPEG,
		'image/png'  => IMAGETYPE_PNG,
		'image/webp' => IMAGETYPE_WEBP,
	];

	$finfo = new finfo( FILEINFO_MIME_TYPE );
	$mime  = (string) $finfo->file( $file['tmp_name'] );

	if ( ! isset( $allowed[ $mime ] ) ) {
		// SVG files are converted to PNG in the browser (assets/js/app.js); a raw SVG reaching the server is refused.
		$result['error'] = 'image/svg+xml' === $mime ? 'Fișierul SVG nu a putut fi convertit automat. Salvează logo-ul ca PNG și încearcă din nou.' : 'Format neacceptat. Folosește JPG, PNG, WEBP sau SVG.';

		return $result;
	}

	$info = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

	if ( false === $info || $allowed[ $mime ] !== $info[2] ) {
		$result['error'] = 'Fișierul nu este o imagine validă.';

		return $result;
	}

	list( $width, $height ) = $info;

	if ( 1 > $width || 1 > $height || UPLOAD_MAX_PIXELS < $width || UPLOAD_MAX_PIXELS < $height ) {
		$result['error'] = 'Dimensiuni invalide (maxim 6000 × 6000 px).';

		return $result;
	}

	switch ( $info[2] ) {
		case IMAGETYPE_JPEG:
			$source = @imagecreatefromjpeg( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			break;
		case IMAGETYPE_PNG:
			$source = @imagecreatefrompng( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			break;
		default:
			$source = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $file['tmp_name'] ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
			break;
	}

	if ( false === $source ) {
		$result['error'] = 'Imaginea nu a putut fi procesată.';

		return $result;
	}

	$max_side = 'logo' === $profile ? 600 : 1920;
	$scale    = min( 1, $max_side / max( $width, $height ) );
	$new_w    = max( 1, (int) round( $width * $scale ) );
	$new_h    = max( 1, (int) round( $height * $scale ) );

	// Re-draw on a fresh canvas: drops EXIF/ICC/metadata and any embedded payload.
	$canvas = imagecreatetruecolor( $new_w, $new_h );
	imagealphablending( $canvas, false );
	imagesavealpha( $canvas, true );
	imagefill( $canvas, 0, 0, imagecolorallocatealpha( $canvas, 0, 0, 0, 127 ) );
	imagecopyresampled( $canvas, $source, 0, 0, 0, 0, $new_w, $new_h, $width, $height );

	$directory = REPUVIO_ROOT . '/' . UPLOAD_DIR;

	if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) ) {
		$result['error'] = 'Directorul de încărcare nu poate fi creat.';

		return $result;
	}

	$name = bin2hex( random_bytes( 16 ) );

	if ( function_exists( 'imagewebp' ) ) {
		$filename = $name . '.webp';
		$saved    = imagewebp( $canvas, $directory . '/' . $filename, 85 );
	} else {
		// Fallback for hosts whose GD lacks WebP support.
		$filename = $name . '.png';
		$saved    = imagepng( $canvas, $directory . '/' . $filename, 6 );
	}


	if ( ! $saved ) {
		$result['error'] = 'Imaginea nu a putut fi salvată.';

		return $result;
	}

	@chmod( $directory . '/' . $filename, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

	$result['path'] = UPLOAD_DIR . '/' . $filename;

	return $result;
}

/**
 * Deletes an uploaded file (only files matching the generated naming pattern).
 *
 * @param string|null $relative_path Relative path.
 * @return void
 */
function delete_upload( $relative_path ) {
	if ( empty( $relative_path ) || ! preg_match( '#^uploads/companies/[a-f0-9]{32}\.(webp|png|jpg)$#', (string) $relative_path ) ) {
		return;
	}

	$file = REPUVIO_ROOT . '/' . $relative_path;

	if ( is_file( $file ) ) {
		@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
