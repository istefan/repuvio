<?php
/**
 * "Widget & Semnătură Email": website trust badge snippets (floating script or inline HTML)
 * and ready-to-paste HTML email signatures linking to the public funnel.
 *
 * Also builds the downloadable, pre-configured WordPress plugin ZIP (pure-PHP ZIP writer).
 *
 * Links use ?src=widget and ?src=email_sig so results are tracked per channel.
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

/**
 * Widget positions.
 *
 * @return array
 */
function get_widget_positions() {
	return [
		'bottom-right' => 'Plutitor dreapta-jos',
		'bottom-left'  => 'Plutitor stânga-jos',
		'inline'       => 'Insignă în pagină (inline)',
	];
}

/**
 * Widget themes.
 *
 * @return array
 */
function get_widget_themes() {
	return [
		'light' => 'Light Monochrome',
		'dark'  => 'Dark Slate',
	];
}

/**
 * Theme colors shared by the inline badge and signatures.
 *
 * @param string $theme light|dark.
 * @return array{bg:string,text:string,muted:string,border:string}
 */
function get_widget_palette( $theme ) {
	if ( 'dark' === $theme ) {
		return [
			'bg'     => '#0F172A',
			'text'   => '#FFFFFF',
			'muted'  => '#CBD5E1',
			'border' => '#1E293B',
		];
	}

	return [
		'bg'     => '#FFFFFF',
		'text'   => '#0F172A',
		'muted'  => '#64748B',
		'border' => '#E2E8F0',
	];
}

/**
 * Embed code for the website trust badge.
 *
 * Floating positions load assets/js/badge.js (no cookies, no tracking, no API calls);
 * the inline badge is plain HTML with inline styles so it works on any website builder.
 *
 * @param array  $company  Company row.
 * @param string $position bottom-right|bottom-left|inline.
 * @param string $theme    light|dark.
 * @return string
 */
function build_widget_snippet( array $company, $position, $theme ) {
	$position = array_key_exists( $position, get_widget_positions() ) ? $position : 'bottom-right';
	$theme    = array_key_exists( $theme, get_widget_themes() ) ? $theme : 'light';
	$url      = get_company_public_url( $company, 'widget' );

	if ( 'inline' === $position ) {
		return '<!-- ' . app_name() . ' - Insignă de încredere -->' . "\n" . build_inline_badge_html( $company, $theme ) . "\n";
	}

	return '<!-- ' . app_name() . ' - Insignă de încredere -->' . "\n"
		. '<script src="' . esc_attr( app_url( 'assets/js/badge.js' ) ) . '"'
		. ' data-rm-badge'
		. ' data-url="' . esc_attr( $url ) . '"'
		. ' data-name="' . esc_attr( $company['name'] ) . '"'
		. ' data-position="' . esc_attr( $position ) . '"'
		. ' data-theme="' . esc_attr( $theme ) . '"'
		. ' async></script>' . "\n";
}

/**
 * Inline trust badge HTML (inline styles only).
 *
 * @param array  $company Company row.
 * @param string $theme   light|dark.
 * @return string
 */
function build_inline_badge_html( array $company, $theme ) {
	$colors = get_widget_palette( $theme );
	$url    = get_company_public_url( $company, 'widget' );

	return '<a href="' . esc_attr( $url ) . '" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:10px;padding:10px 16px;border-radius:14px;border:1px solid ' . esc_attr( $colors['border'] ) . ';background:' . esc_attr( $colors['bg'] ) . ';color:' . esc_attr( $colors['text'] ) . ';font-family:Inter,Segoe UI,Arial,sans-serif;text-decoration:none;box-shadow:0 4px 14px rgba(15,23,42,.08);line-height:1.2;">'
		. '<span style="color:#F59E0B;font-size:16px;letter-spacing:1px;">★★★★★</span>'
		. '<span style="display:inline-block;text-align:left;"><strong style="display:block;font-size:14px;">Evaluează-ne</strong>'
		. '<span style="display:block;font-size:12px;color:' . esc_attr( $colors['muted'] ) . ';">' . esc_html( $company['name'] ) . '</span></span>'
		. '</a>';
}

/**
 * Email signature variants.
 *
 * @return array
 */
function get_signature_variants() {
	return [
		'minimal' => 'Link text minimal',
		'stars'   => 'Casetă cu stele și logo',
		'banner'  => 'Banner compact cu buton',
	];
}

/**
 * Builds an email signature block (table layout + inline styles for Outlook / Gmail).
 *
 * @param array  $company Company row.
 * @param string $variant minimal|stars|banner.
 * @return string
 */
function build_email_signature( array $company, $variant ) {
	$url      = get_company_public_url( $company, 'email_sig' );
	$name     = $company['name'];
	$logo_url = upload_url( $company['logo_path'] );
	$font     = 'font-family:Arial,Helvetica,sans-serif;';

	if ( 'minimal' === $variant ) {
		return '<p style="margin:12px 0 0;' . $font . 'font-size:13px;color:#334155;">'
			. '<span style="color:#F59E0B;">★★★★★</span> Ți-a plăcut colaborarea cu ' . esc_html( $name ) . '? '
			. '<a href="' . esc_attr( $url ) . '" target="_blank" style="color:#2563EB;font-weight:bold;text-decoration:underline;">Lasă-ne o recenzie în 10 secunde</a>'
			. '</p>';
	}

	if ( 'banner' === $variant ) {
		return '<table cellpadding="0" cellspacing="0" border="0" style="margin-top:12px;border-collapse:collapse;' . $font . '">'
			. '<tr><td style="background:#0F172A;border-radius:10px;padding:10px 14px;">'
			. '<table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;"><tr>'
			. '<td style="color:#F59E0B;font-size:15px;padding-right:10px;' . $font . '">★★★★★</td>'
			. '<td style="color:#FFFFFF;font-size:13px;padding-right:12px;' . $font . '">Cum a fost experiența cu <strong>' . esc_html( $name ) . '</strong>?</td>'
			. '<td><a href="' . esc_attr( $url ) . '" target="_blank" style="display:inline-block;background:#FFFFFF;color:#0F172A;font-size:12px;font-weight:bold;text-decoration:none;padding:7px 12px;border-radius:7px;' . $font . '">Evaluează-ne &rarr;</a></td>'
			. '</tr></table>'
			. '</td></tr></table>';
	}

	$logo_cell = '' !== $logo_url
		? '<td style="padding-right:12px;vertical-align:middle;"><img src="' . esc_attr( $logo_url ) . '" width="48" height="48" alt="' . esc_attr( $name ) . '" style="display:block;width:48px;height:48px;border-radius:10px;border:1px solid #E2E8F0;object-fit:cover;"></td>'
		: '';

	return '<table cellpadding="0" cellspacing="0" border="0" style="margin-top:12px;border-collapse:collapse;' . $font . '">'
		. '<tr><td style="border:1px solid #E2E8F0;border-radius:12px;padding:12px 14px;background:#F8FAFC;">'
		. '<table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;"><tr>'
		. $logo_cell
		. '<td style="vertical-align:middle;' . $font . '">'
		. '<div style="font-size:14px;font-weight:bold;color:#0F172A;">' . esc_html( $name ) . '</div>'
		. '<div style="font-size:16px;color:#F59E0B;line-height:1.3;">★★★★★</div>'
		. '<a href="' . esc_attr( $url ) . '" target="_blank" style="font-size:12px;color:#2563EB;font-weight:bold;text-decoration:none;">Spune-ne părerea ta &rarr;</a>'
		. '</td></tr></table>'
		. '</td></tr></table>';
}

/**
 * Step-by-step signature tutorials per email client (Romanian).
 *
 * @return array client key => [ label, steps[] ]
 */
function get_signature_tutorials() {
	return [
		'outlook'     => [
			'Microsoft Outlook',
			[
				'Apasă „Copiază Semnătura Formatată (Vizual)” sub varianta aleasă.',
				'Outlook pentru Windows: Fișier → Opțiuni → E-mail → Semnături. Outlook nou / Outlook.com: Setări (rotița) → Conturi → Semnături.',
				'Creează o semnătură nouă sau editează-o pe cea existentă.',
				'Lipește cu Ctrl+V în caseta de editare, sub numele și datele tale.',
				'Setează semnătura ca implicită pentru mesaje noi și răspunsuri, apoi apasă „OK” / „Salvare”.',
			],
		],
		'gmail'       => [
			'Gmail',
			[
				'Apasă „Copiază Semnătura Formatată (Vizual)”.',
				'În Gmail: rotița din dreapta-sus → „Vezi toate setările” → fila „General”.',
				'La secțiunea „Semnătură” apasă „Creează una nouă” (sau alege semnătura existentă).',
				'Lipește cu Ctrl+V (Cmd+V pe Mac) în caseta semnăturii.',
				'Alege semnătura la „Valori prestabilite pentru semnături”, apoi derulează jos și apasă „Salvează modificările”.',
			],
		],
		'apple_mail'  => [
			'Apple Mail',
			[
				'Deschide pagina în Safari și apasă „Copiază Semnătura Formatată (Vizual)”.',
				'În Mail: Mail → Setări (Preferințe) → Semnături.',
				'Selectează contul și apasă „+” pentru o semnătură nouă.',
				'Debifează „Folosește întotdeauna fontul implicit al mesajului”, apoi lipește cu Cmd+V.',
				'Închide fereastra; semnătura se salvează automat. Alege-o din meniul „Semnătură” al contului.',
			],
		],
		'thunderbird' => [
			'Thunderbird',
			[
				'Apasă „Copiază Codul HTML”.',
				'În Thunderbird: meniul ☰ → Setări cont → selectează adresa de email.',
				'Bifează „Folosește HTML (de ex. <b>aldin</b>)” sub „Text semnătură”.',
				'Lipește codul HTML copiat în caseta „Text semnătură”.',
				'Închide fila de setări; semnătura apare automat în mesajele noi.',
			],
		],
	];
}

/**
 * Builds a ZIP archive in memory (deflate, UTF-8 names). Pure PHP: no ext-zip needed on shared hosting.
 *
 * @param array $files Archive path => file contents.
 * @return string Binary ZIP data.
 */
function build_zip_archive( array $files ) {
	$data      = '';
	$central   = '';
	$count     = 0;
	$timestamp = getdate();
	$dos_time  = ( $timestamp['hours'] << 11 ) | ( $timestamp['minutes'] << 5 ) | intdiv( $timestamp['seconds'], 2 );
	$dos_date  = ( ( $timestamp['year'] - 1980 ) << 9 ) | ( $timestamp['mon'] << 5 ) | $timestamp['mday'];

	foreach ( $files as $name => $contents ) {
		$name       = str_replace( '\\', '/', (string) $name );
		$contents   = (string) $contents;
		$compressed = gzdeflate( $contents, 9 );
		$crc        = crc32( $contents );
		$offset     = strlen( $data );

		$data .= pack( 'VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 8, $dos_time, $dos_date, $crc, strlen( $compressed ), strlen( $contents ), strlen( $name ), 0 )
			. $name
			. $compressed;

		$central .= pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 8, $dos_time, $dos_date, $crc, strlen( $compressed ), strlen( $contents ), strlen( $name ), 0, 0, 0, 0, 32, $offset )
			. $name;

		++$count;
	}

	return $data . $central . pack( 'VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen( $central ), strlen( $data ), 0 );
}

/**
 * WordPress plugin ZIP pre-configured for a company: template from includes/wp-plugin/, the current
 * assets/js/badge.js and a generated defaults.php (review URL with ?src=widget + company name).
 *
 * @param array $company Company row (ownership checked by the caller).
 * @return string Binary ZIP data.
 */
function build_wordpress_plugin_zip( array $company ) {
	$slug     = 'repuvio-trust-badge';
	$template = REPUVIO_ROOT . '/includes/wp-plugin/' . $slug;
	// Brand from Settings → app name, stripped of characters that could break the plugin header.
	$brand    = trim( preg_replace( '/\s+/', ' ', preg_replace( '/[\r\n*\/]+/', ' ', app_name() ) ) );
	$brand    = '' !== $brand ? $brand : 'RepuVio';
	$defaults = [
		'url'   => get_company_public_url( $company, 'widget' ),
		'name'  => (string) $company['name'],
		'brand' => $brand,
	];
	$main     = (string) file_get_contents( $template . '/' . $slug . '.php' );
	$main     = preg_replace( '/^ \* Plugin Name:.*$/m', ' * Plugin Name:       ' . $brand . ' Trust Badge', $main, 1 );

	$files = [
		$slug . '/' . $slug . '.php' => $main,
		$slug . '/uninstall.php'     => file_get_contents( $template . '/uninstall.php' ),
		$slug . '/readme.txt'        => str_replace( '{{brand}}', $brand, (string) file_get_contents( $template . '/readme.txt' ) ),
		$slug . '/assets/badge.js'   => file_get_contents( REPUVIO_ROOT . '/assets/js/badge.js' ),
		$slug . '/defaults.php'      => "<?php\n/**\n * Generated by " . str_replace( '*/', '', app_name() ) . ' on ' . date( 'Y-m-d H:i' ) . " for this company.\n *\n * @package RepuVioTrustBadge\n */\n\ndefined( 'ABSPATH' ) || exit;\n\nreturn " . var_export( $defaults, true ) . ";\n",
		$slug . '/index.php'         => "<?php\n// Silence is golden.\n",
	];

	return build_zip_archive( $files );
}
