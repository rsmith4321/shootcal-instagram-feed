<?php
/**
 * Remove ShootCal Instagram Feed data when the plugin is deleted.
 *
 * @package ShootCalInstagramFeed
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'shootcal_instagram_feed_refresh' );

foreach (
	array(
		'shootcal_instagram_feed_options',
		'shootcal_instagram_feed_cache',
		'shootcal_instagram_feed_status',
		'shootcal_instagram_feed_refresh_lock',
		'shootcal_instagram_feed_oauth',
	) as $shootcal_instagram_option
) {
	delete_option( $shootcal_instagram_option );
}

unset( $shootcal_instagram_option );
