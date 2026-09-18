<?php
/**
 * Plugin Name:       ShootCal Social Feed
 * Description:       Display a lightweight, cached Instagram Business or Creator feed with exact caption hashtag filtering.
 * Version:           0.4.1
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            ShootCal
 * Author URI:        https://www.shootcal.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shootcal-social-feed
 *
 * @package ShootCalInstagramFeed
 */

declare( strict_types=1 );

namespace ShootCalInstagramFeed;

defined( 'ABSPATH' ) || exit;

const VERSION       = '0.4.1';
const SLUG          = 'shootcal-instagram-feed';
const OPTION_KEY    = 'shootcal_instagram_feed_options';
const CACHE_KEY     = 'shootcal_instagram_feed_cache';
const STATUS_KEY    = 'shootcal_instagram_feed_status';
const LOCK_KEY      = 'shootcal_instagram_feed_refresh_lock';
const OAUTH_KEY     = 'shootcal_instagram_feed_oauth';
const CRON_HOOK     = 'shootcal_instagram_feed_refresh';
const FEEDS_KEY     = 'shootcal_instagram_feed_feeds';
const GRAPH_VERSION = 'v26.0';

define( __NAMESPACE__ . '\\PLUGIN_FILE', __FILE__ );
define( __NAMESPACE__ . '\\PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\\PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimal namespace autoloader.
 *
 * Maps ShootCalInstagramFeed\Api_Client to includes/class-api-client.php.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		if ( strpos( $class_name, __NAMESPACE__ . '\\' ) !== 0 ) {
			return;
		}

		$relative = substr( $class_name, strlen( __NAMESPACE__ . '\\' ) );
		$file     = strtolower( str_replace( '_', '-', $relative ) );
		$path     = PLUGIN_DIR . 'includes/class-' . $file . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Register the plugin's WordPress integrations.
 */
function bootstrap(): void {
	$client = new Api_Client();
	$store  = new Feed_Store( $client );

	( new Assets() )->register();
	( new Admin_Menu() )->register();
	( new Compatibility() )->register();
	( new Shortcode() )->register();
	( new Smash_Import() )->register();
	( new Smash_Import_Admin() )->register();
	( new Rest_Controller() )->register();
	( new Scheduler( $store ) )->register();
	( new Settings( $store ) )->register();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );

register_activation_hook(
	__FILE__,
	static function (): void {
		Config::ensure_defaults();
		Scheduler::schedule();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		Scheduler::unschedule();
		delete_option( LOCK_KEY );
	}
);
