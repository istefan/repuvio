<?php
/**
 * Sidebar navigation (desktop fixed / mobile offcanvas).
 *
 * Expects: $sidebar_user (array), $active_nav (string).
 *
 * @package RepuVio
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 * @company Ahoi Digital SRL
 */

defined( 'REPUVIO' ) || exit;

if ( 'super_admin' === $sidebar_user['role'] ) {
	$nav_groups = [
		'Platformă' => [
			'admin'         => [ 'admin/index.php', 'bi-grid-1x2', 'Prezentare generală' ],
			'users'         => [ 'admin/users.php', 'bi-people', 'Utilizatori' ],
			'subscriptions' => [ 'admin/subscriptions.php', 'bi-card-checklist', 'Abonamente clienți', count_open_subscription_requests() ],
			'plans'         => [ 'admin/plans.php', 'bi-layers', 'Planuri tarifare' ],
			'payments'      => [ 'admin/payments.php', 'bi-receipt', 'Plăți' ],
			'roi'           => [ 'admin/roi-reports.php', 'bi-graph-up-arrow', 'Rapoarte ROI' ],
			'contact'       => [ 'admin/contact-messages.php', 'bi-chat-left-text', 'Mesaje contact', count_new_contact_messages() ],
			'emails'        => [ 'admin/emails.php', 'bi-envelope-paper', 'Email & Șabloane', (int) db_value( "SELECT COUNT(*) FROM email_logs WHERE status = 'failed' AND created_at >= NOW() - INTERVAL 7 DAY" ) ],
			'print_admin'   => [ 'admin/print-products.php', 'bi-box-seam', 'Printuri fizice' ],
		],
		'Sistem'    => [
			'backups'  => [ 'admin/backups.php', 'bi-cloud-arrow-up', 'Backup Google Drive', 'failed' === get_setting( 'gdrive_last_backup_status' ) ? 1 : 0 ],
			'settings' => [ 'admin/settings.php', 'bi-gear', 'Setări & Email' ],
			'profile'  => [ 'dashboard/profile.php', 'bi-person-circle', 'Profil' ],
			'help'     => [ 'dashboard/help.php', 'bi-question-circle', 'Ajutor & Ghid' ],
		],
	];
} elseif ( 'operator' === $sidebar_user['role'] ) {
	$nav_groups = [
		'Principal' => [
			'dashboard' => [ 'dashboard/index.php', 'bi-grid-1x2', 'Panou' ],
			'reviews'   => [ 'dashboard/reviews.php', 'bi-chat-square-text', 'Feedback & Recenzii' ],
		],
		'Cont'      => [
			'profile' => [ 'dashboard/profile.php', 'bi-person-circle', 'Profil' ],
			'help'    => [ 'dashboard/help.php', 'bi-question-circle', 'Ajutor & Ghid' ],
		],
	];
} else {
	$nav_groups = [
		'Principal' => [
			'dashboard'   => [ 'dashboard/index.php', 'bi-grid-1x2', 'Panou' ],
			'reviews'     => [ 'dashboard/reviews.php', 'bi-chat-square-text', 'Feedback & Recenzii' ],
			'team_report' => [ 'dashboard/team-report.php', 'bi-bar-chart-line', 'Rapoarte echipă' ],
		],
		'Gestiune'  => [
			'companies'  => [ 'dashboard/companies.php', 'bi-shop', 'Companii' ],
			'printables' => [ 'dashboard/printables.php', 'bi-qr-code', 'Print Studio' ],
			'promote'    => [ 'dashboard/promote.php', 'bi-window-stack', 'Widget & Email' ],
			'operators'  => [ 'dashboard/operators.php', 'bi-headset', 'Operatori' ],
		],
		'Cont'      => [
			'billing' => [ 'dashboard/billing.php', 'bi-credit-card', 'Abonament' ],
			'profile' => [ 'dashboard/profile.php', 'bi-person-circle', 'Profil' ],
			'help'    => [ 'dashboard/help.php', 'bi-question-circle', 'Ajutor & Ghid' ],
		],
	];

	if ( is_print_upsell_enabled() ) {
		$nav_groups['Gestiune']['print_order'] = [ 'dashboard/print-order.php', 'bi-box-seam', 'Comandă printuri' ];
	}
}

$home_url = get_home_url( $sidebar_user );

if ( 'super_admin' === $sidebar_user['role'] ) {
	$sidebar_subtitle = 'Super Admin';
} elseif ( 'operator' === $sidebar_user['role'] ) {
	$sidebar_subtitle = 'Operator';
} else {
	$sidebar_subtitle = get_user_limits( $sidebar_user )['plan_name'];
}
?>
<div class="offcanvas-header border-bottom d-lg-none">
	<span class="rm-brand"><span class="rm-brand-mark"><i class="bi bi-star-fill"></i></span> <?php echo esc_html( app_name() ); ?></span>
	<button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#rmSidebar" aria-label="Închide"></button>
</div>
<div class="offcanvas-body d-flex flex-column p-0 h-100">
	<a class="rm-brand d-none d-lg-flex" href="<?php echo esc_url( $home_url ); ?>">
		<span class="rm-brand-mark"><i class="bi bi-star-fill"></i></span>
		<span><?php echo esc_html( app_name() ); ?></span>
	</a>

	<nav class="rm-nav flex-grow-1" aria-label="Navigare principală">
		<?php foreach ( $nav_groups as $group_label => $items ) : ?>
			<div class="rm-nav-group"><?php echo esc_html( $group_label ); ?></div>
			<?php foreach ( $items as $key => $item ) : ?>
				<a class="rm-nav-link<?php echo $key === $active_nav ? ' active' : ''; ?>" href="<?php echo esc_url( app_url( $item[0] ) ); ?>"<?php echo $key === $active_nav ? ' aria-current="page"' : ''; ?>>
					<i class="bi <?php echo esc_attr( $item[1] ); ?>"></i>
					<span><?php echo esc_html( $item[2] ); ?></span>
					<?php if ( ! empty( $item[3] ) ) : ?>
						<span class="rm-nav-badge" title="De procesat"><?php echo esc_html( min( 99, (int) $item[3] ) ); ?></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		<?php endforeach; ?>
	</nav>

	<div class="rm-sidebar-user">
		<div class="rm-avatar"><?php echo esc_html( mb_strtoupper( mb_substr( $sidebar_user['name'], 0, 1 ) ) ); ?></div>
		<div class="flex-grow-1 min-w-0">
			<div class="fw-semibold text-truncate"><?php echo esc_html( $sidebar_user['name'] ); ?></div>
			<div class="small text-muted text-truncate"><?php echo esc_html( $sidebar_subtitle ); ?></div>
		</div>
		<form method="post" action="<?php echo esc_url( app_url( 'auth/logout.php' ) ); ?>">
			<?php echo csrf_field(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
			<button type="submit" class="btn btn-sm btn-light" title="Deconectare" aria-label="Deconectare"><i class="bi bi-box-arrow-right"></i></button>
		</form>
	</div>
</div>
