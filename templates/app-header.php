<?php
/**
 * Authenticated layout header (sidebar, top bar, banners, flash messages).
 *
 * Expects: $page_title (string). Optional: $page_breadcrumbs (array of [label, url]),
 * $active_nav (string), $page_styles (array of URLs).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

$header_user      = current_user();
$page_title       = $page_title ?? app_name();
$page_breadcrumbs = $page_breadcrumbs ?? [];
$active_nav       = $active_nav ?? '';
$page_styles      = $page_styles ?? [];
$is_owner         = 'user' === $header_user['role'];
$is_panel_user    = in_array( $header_user['role'], [ 'user', 'operator' ], true );
$header_companies = $is_panel_user ? get_accessible_companies( $header_user ) : [];
$current_company  = $is_panel_user ? get_current_company_id() : 0;
$notifications    = $is_panel_user ? get_feedback_notifications( get_account_owner_id( $header_user ) ) : [
	'count' => 0,
	'items' => [],
];
$subscription_ok  = is_subscription_active( $header_user );
?>
<!doctype html>
<html lang="ro">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<meta name="author" content="Stefan Iftimie — Ahoi Digital SRL">
	<title><?php echo esc_html( $page_title . ' · ' . app_name() ); ?></title>
	<link rel="icon" href="<?php echo esc_url( asset_url( 'img/favicon.svg' ) ); ?>" type="image/svg+xml">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
	<link href="<?php echo esc_url( asset_url( 'css/app.css' ) ); ?>" rel="stylesheet">
	<?php foreach ( $page_styles as $style_url ) : ?>
		<link href="<?php echo esc_url( $style_url ); ?>" rel="stylesheet">
	<?php endforeach; ?>
</head>
<body class="rm-app">

<?php if ( is_impersonating() ) : ?>
	<div class="rm-impersonation-bar">
		<div><i class="bi bi-incognito"></i> Ești autentificat ca <strong><?php echo esc_html( $header_user['name'] ); ?></strong> (<?php echo esc_html( $header_user['email'] ); ?>).</div>
		<form method="post" action="<?php echo esc_url( app_url( 'auth/stop-impersonation.php' ) ); ?>">
			<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<button type="submit" class="btn btn-sm btn-light"><i class="bi bi-arrow-return-left"></i> Înapoi la Super Admin</button>
		</form>
	</div>
<?php endif; ?>

<div class="rm-shell">
	<aside class="rm-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="rmSidebar" aria-label="Meniu">
		<?php
		render_template(
			'sidebar',
			[
				'sidebar_user' => $header_user,
				'active_nav'   => $active_nav,
			]
		);
		?>
	</aside>

	<div class="rm-main">
		<header class="rm-topbar">
			<button class="btn btn-light d-lg-none rm-icon-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#rmSidebar" aria-controls="rmSidebar" aria-label="Deschide meniul">
				<i class="bi bi-list"></i>
			</button>

			<div class="rm-topbar-title min-w-0">
				<?php if ( ! empty( $page_breadcrumbs ) ) : ?>
					<nav aria-label="breadcrumb" class="d-none d-sm-block">
						<ol class="breadcrumb mb-0 small">
							<?php foreach ( $page_breadcrumbs as $crumb ) : ?>
								<?php if ( ! empty( $crumb[1] ) ) : ?>
									<li class="breadcrumb-item"><a href="<?php echo esc_url( $crumb[1] ); ?>"><?php echo esc_html( $crumb[0] ); ?></a></li>
								<?php else : ?>
									<li class="breadcrumb-item active" aria-current="page"><?php echo esc_html( $crumb[0] ); ?></li>
								<?php endif; ?>
							<?php endforeach; ?>
						</ol>
					</nav>
				<?php endif; ?>
				<h1 class="rm-page-title text-truncate"><?php echo esc_html( $page_title ); ?></h1>
			</div>

			<?php if ( is_dev_environment() ) : ?>
				<span class="rm-dev-badge" title="Mod DEV: limitele anti-abuz de pe /r/{slug} sunt dezactivate">DEV</span>
			<?php endif; ?>

			<?php if ( is_showcase_user( $header_user ) ) : ?>
				<span class="rm-demo-badge" title="Cont demo pentru prezentări: datele se resetează automat în fiecare zi, iar emailurile nu pleacă la clienți."><i class="bi bi-easel"></i> DEMO</span>
			<?php endif; ?>

			<div class="rm-topbar-actions">
				<?php if ( $is_panel_user && 1 < count( $header_companies ) ) : ?>
					<form method="post" action="<?php echo esc_url( app_url( 'dashboard/switch-company.php' ) ); ?>" class="d-none d-md-block">
						<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<input type="hidden" name="return" value="<?php echo esc_attr( current_request_url() ); ?>">
						<select name="company_id" class="form-select form-select-sm rm-company-switcher" data-autosubmit aria-label="Companie selectată">
							<option value="0">Toate companiile</option>
							<?php foreach ( $header_companies as $header_company ) : ?>
								<option value="<?php echo esc_attr( $header_company['id'] ); ?>"<?php echo (int) $header_company['id'] === $current_company ? ' selected' : ''; ?>><?php echo esc_html( $header_company['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</form>
				<?php endif; ?>

				<?php if ( $is_panel_user ) : ?>
					<div class="dropdown">
						<button class="btn btn-light rm-icon-btn position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notificări">
							<i class="bi bi-bell"></i>
							<?php if ( 0 < $notifications['count'] ) : ?>
								<span class="rm-dot"><?php echo esc_html( min( 99, $notifications['count'] ) ); ?></span>
							<?php endif; ?>
						</button>
						<div class="dropdown-menu dropdown-menu-end rm-dropdown p-0">
							<div class="px-3 py-2 border-bottom fw-semibold small">Feedback nou</div>
							<?php if ( empty( $notifications['items'] ) ) : ?>
								<div class="px-3 py-3 small text-muted">Nu ai feedback nou. 🎉</div>
							<?php else : ?>
								<?php foreach ( $notifications['items'] as $notification ) : ?>
									<a class="dropdown-item py-2 small" href="<?php echo esc_url( app_url( 'dashboard/review-view.php', [ 'id' => (int) $notification['id'] ] ) ); ?>">
										<div class="d-flex justify-content-between gap-2">
											<span class="fw-medium text-truncate"><?php echo esc_html( $notification['company_name'] ); ?></span>
											<span class="text-danger"><?php echo esc_html( (int) $notification['rating'] ); ?>★</span>
										</div>
										<div class="text-muted"><?php echo esc_html( 1 === (int) $notification['is_locked'] ? 'Blocat — abonament inactiv / cotă atinsă' : time_ago( $notification['created_at'] ) ); ?></div>
									</a>
								<?php endforeach; ?>
							<?php endif; ?>
							<a class="dropdown-item text-center small py-2 border-top" href="<?php echo esc_url( app_url( 'dashboard/reviews.php', [ 'status' => 'new' ] ) ); ?>">Vezi toate</a>
						</div>
					</div>
				<?php endif; ?>

				<div class="dropdown">
					<button class="btn btn-light rm-user-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
						<span class="rm-avatar rm-avatar-sm"><?php echo esc_html( mb_strtoupper( mb_substr( $header_user['name'], 0, 1 ) ) ); ?></span>
						<span class="d-none d-md-inline text-truncate"><?php echo esc_html( $header_user['name'] ); ?></span>
						<i class="bi bi-chevron-down small"></i>
					</button>
					<ul class="dropdown-menu dropdown-menu-end rm-dropdown">
						<li><span class="dropdown-item-text small text-muted"><?php echo esc_html( $header_user['email'] ); ?><br><?php echo esc_html( get_role_label( $header_user['role'] ) ); ?></span></li>
						<li><hr class="dropdown-divider"></li>
						<li><a class="dropdown-item" href="<?php echo esc_url( app_url( 'dashboard/profile.php' ) ); ?>"><i class="bi bi-person me-2"></i>Profil</a></li>
						<?php if ( $is_owner ) : ?>
							<li><a class="dropdown-item" href="<?php echo esc_url( app_url( 'dashboard/billing.php' ) ); ?>"><i class="bi bi-credit-card me-2"></i>Abonament</a></li>
						<?php endif; ?>
						<li><hr class="dropdown-divider"></li>
						<li>
							<form method="post" action="<?php echo esc_url( app_url( 'auth/logout.php' ) ); ?>">
								<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Deconectare</button>
							</form>
						</li>
					</ul>
				</div>
			</div>
		</header>

		<main class="rm-content">
			<?php if ( $is_panel_user && ! $subscription_ok ) : ?>
				<div class="alert rm-alert-danger d-flex flex-wrap align-items-center gap-2 justify-content-between">
					<div><i class="bi bi-exclamation-octagon me-1"></i> <strong><?php echo esc_html( $is_owner ? 'Abonamentul tău nu este activ.' : 'Abonamentul firmei nu este activ.' ); ?></strong> Clienții mulțumiți ajung în continuare pe Google, dar feedback-ul negativ nou este blocat.</div>
					<?php if ( $is_owner ) : ?>
						<a class="btn btn-sm btn-dark" href="<?php echo esc_url( app_url( 'dashboard/billing.php' ) ); ?>">Reînnoiește</a>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php foreach ( get_flash_messages() as $flash_message ) : ?>
				<div class="alert alert-<?php echo esc_attr( $flash_message['type'] ); ?> alert-dismissible fade show" role="alert">
					<?php echo esc_html( $flash_message['message'] ); ?>
					<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Închide"></button>
				</div>
			<?php endforeach; ?>
