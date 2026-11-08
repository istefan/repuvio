<?php
/**
 * Removes the plugin settings when the plugin is deleted from WordPress.
 *
 * @package RepuVioTrustBadge
 * @author  Stefan Iftimie <https://www.ahoi.ro/>
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'repuvio_badge_options' );
