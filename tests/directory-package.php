<?php
/**
 * Public-directory folder compatibility. Run with wp eval-file while the
 * packaged shootcal-social-feed plugin and the existing Calendar are active.
 */
declare( strict_types=1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

use ShootCalInstagramFeed\Admin_Menu;
use ShootCalInstagramFeed\Compatibility;
use const ShootCalInstagramFeed\PLUGIN_FILE;

if ( 'shootcal-social-feed/shootcal-instagram-feed.php' !== plugin_basename( PLUGIN_FILE ) ) {
	throw new RuntimeException( 'The test must run from the public-directory package.' );
}
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );

$exclusions = Compatibility::exclude_stylesheet( array( '/existing/' ) );
if ( array( '/existing/', '/shootcal-social-feed/', '/shootcal-instagram-feed/' ) !== $exclusions
	|| $exclusions !== Compatibility::exclude_stylesheet( $exclusions ) ) {
	throw new RuntimeException( 'Optimizer exclusions do not support both folder names without duplicates.' );
}

ob_start();
( new Admin_Menu() )->render_page();
$html = ob_get_clean();
if ( ! preg_match( '~<section[^>]*>\s*<h2>Social Feed</h2>(.*?)</section>~s', $html, $matches )
	|| ! str_contains( $matches[1], '>Active' )
	|| ! str_contains( $matches[1], 'admin.php?page=shootcal-instagram-feed' ) ) {
	throw new RuntimeException( 'The Apps overview lost the public plugin or legacy settings address.' );
}

$found_priority = null;
foreach ( $GLOBALS['wp_filter']['admin_menu']->callbacks as $priority => $callbacks ) {
	foreach ( $callbacks as $callback ) {
		$function = $callback['function'];
		if ( is_array( $function ) && $function[0] instanceof Admin_Menu && 'add_menu' === $function[1] ) {
			$found_priority = $priority;
		}
	}
}
if ( 8 !== $found_priority ) {
	throw new RuntimeException( 'The new folder-aware overview does not precede older shared-menu providers.' );
}
echo "directory-package: ok (public folder, existing settings link, shared-menu priority, and both optimizer paths)\n";
