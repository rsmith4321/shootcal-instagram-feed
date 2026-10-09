<?php
/**
 * Remove ShootCal Social Feed data when the plugin is deleted.
 *
 * @package ShootCalInstagramFeed
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'shootcal_instagram_feed_refresh' );
wp_clear_scheduled_hook( 'shootcal_instagram_feed_images' );

foreach (
	array(
		'shootcal_instagram_feed_options',
		'shootcal_instagram_feed_feeds',
		'shootcal_instagram_feed_cache',
		'shootcal_instagram_feed_status',
		'shootcal_instagram_feed_images',
		'shootcal_instagram_feed_images_lock',
		'shootcal_instagram_feed_refresh_lock',
		'shootcal_instagram_feed_oauth',
		'shootcal_instagram_feed_smash_import',
		'shootcal_instagram_feed_smash_import_lock',
	) as $shootcal_instagram_option
) {
	delete_option( $shootcal_instagram_option );
}

unset( $shootcal_instagram_option );
