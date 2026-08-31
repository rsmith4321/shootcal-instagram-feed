<?php
/**
 * Dependency-free uninstall cleanup regression test.
 */

declare( strict_types=1 );

define( 'WP_UNINSTALL_PLUGIN', true );

$deleted_options = array();
$cleared_hooks   = array();

function delete_option( string $name ): bool {
	global $deleted_options;
	$deleted_options[] = $name;
	return true;
}

function wp_clear_scheduled_hook( string $hook ): int {
	global $cleared_hooks;
	$cleared_hooks[] = $hook;
	return 1;
}

require dirname( __DIR__ ) . '/uninstall.php';

$expected_options = array(
	'shootcal_instagram_feed_options',
	'shootcal_instagram_feed_feeds',
	'shootcal_instagram_feed_cache',
	'shootcal_instagram_feed_status',
	'shootcal_instagram_feed_refresh_lock',
	'shootcal_instagram_feed_oauth',
);

if ( $expected_options !== $deleted_options ) {
	throw new RuntimeException( 'Uninstall did not delete the exact plugin option set.' );
}
if ( array( 'shootcal_instagram_feed_refresh' ) !== $cleared_hooks ) {
	throw new RuntimeException( 'Uninstall did not clear the refresh schedule.' );
}

echo "uninstall: ok\n";
