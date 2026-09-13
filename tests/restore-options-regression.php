<?php
/**
 * Read-only WordPress regression. Run with:
 * wp --skip-plugins --skip-themes eval-file tests/restore-options-regression.php
 * WordPress option filters keep every test write in memory; no stored token changes.
 */
declare( strict_types=1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/shootcal-instagram-feed.php';
require_once __DIR__ . '/restore-options.php';

use ShootCalInstagramFeed\Api_Client;
use ShootCalInstagramFeed\Config;
use ShootCalInstagramFeed\Feed_Store;
use ShootCalInstagramFeed\Settings;
use const ShootCalInstagramFeed\OPTION_KEY;
use const ShootCalInstagramFeed\CACHE_KEY;

$actual_before = get_option( OPTION_KEY, false );
$saved = Config::defaults();
$saved['access_token'] = 'original-encrypted-fixture';
$saved['token_updated_at'] = 123;
$virtual_options = array( OPTION_KEY => $saved, CACHE_KEY => array( 'items' => array( 'saved-item' ) ) );
$snapshots = $virtual_options;
$virtual_options[OPTION_KEY]['access_token'] = 'replacement-encrypted-fixture';
$virtual_options[OPTION_KEY]['token_updated_at'] = 456;
$virtual_options[CACHE_KEY] = array( 'items' => array( 'replacement-item' ) );
$write_attempts = 0;
$read = static function ( $pre, $option ) use ( &$virtual_options ) {
	return array_key_exists( $option, $virtual_options ) ? $virtual_options[$option] : $pre;
};
$write = static function ( $value, $option, $old_value ) use ( &$virtual_options, &$write_attempts ) {
	if ( array_key_exists( $option, $virtual_options ) ) {
		++$write_attempts;
		$virtual_options[$option] = $value;
		// Returning the old value makes update_option exit BEFORE its SQL write.
		return $old_value;
	}
	return $value;
};
add_filter( 'pre_option', $read, 10, 2 );
add_filter( 'pre_update_option', $write, 10, 3 );
$settings = new Settings( new Feed_Store( new Api_Client() ) );
$settings->register_settings();

try {
	update_option( OPTION_KEY, $snapshots[OPTION_KEY], false );
	if ( $virtual_options[OPTION_KEY]['access_token'] === $snapshots[OPTION_KEY]['access_token'] ) {
		throw new RuntimeException( 'The regression did not reproduce the old cleanup failure.' );
	}
	shootcal_instagram_restore_test_options( $snapshots );
	if ( $virtual_options !== $snapshots ) {
		throw new RuntimeException( 'The cleanup did not restore the exact option snapshots.' );
	}
	if ( false === has_filter( 'sanitize_option_' . OPTION_KEY, array( $settings, 'sanitize' ) ) ) {
		throw new RuntimeException( 'The cleanup removed the normal settings sanitizer.' );
	}
	$browser_input = $saved;
	$browser_input['access_token'] = 'unauthorized-browser-replacement';
	$sanitized = sanitize_option( OPTION_KEY, $browser_input );
	if ( $sanitized['access_token'] !== $saved['access_token'] || $write_attempts !== 3 ) {
		throw new RuntimeException( 'Settings protection or in-memory write interception changed.' );
	}
} finally {
	remove_filter( 'pre_option', $read, 10 );
	remove_filter( 'pre_update_option', $write, 10 );
	remove_filter( 'sanitize_option_' . OPTION_KEY, array( $settings, 'sanitize' ) );
}

if ( get_option( OPTION_KEY, false ) !== $actual_before ) {
	throw new RuntimeException( 'The configured local connection changed.' );
}
echo "PASS: cleanup restores exact snapshots, retains settings protection, and leaves the configured connection unchanged.\n";
