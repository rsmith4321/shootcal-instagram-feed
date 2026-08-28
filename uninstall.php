<?php
/**
 * Remove all plugin-owned data when WordPress uninstalls the plugin.
 *
 * @package ShootCalInstagramFeed
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'shootcal_instagram_feed_options' );
delete_option( 'shootcal_instagram_feed_cache' );
delete_option( 'shootcal_instagram_feed_status' );
delete_option( 'shootcal_instagram_feed_refresh_lock' );
delete_option( 'shootcal_instagram_feed_oauth' );
wp_clear_scheduled_hook( 'shootcal_instagram_feed_refresh' );
