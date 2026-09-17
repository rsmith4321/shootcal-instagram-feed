<?php
/**
 * Importer integration checks for the isolated Shootcal Plugin Dev Local site.
 * Run with `wp eval-file tests/smash-import.php` while this candidate is active.
 * All fixture tables, options, activation changes, and shortcode callbacks are restored.
 */
declare( strict_types=1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! str_contains( ABSPATH, '/Local Sites/shootcal-plugin-dev/app/public/' ) ) {
	exit( 1 );
}

use ShootCalInstagramFeed\Config;
use ShootCalInstagramFeed\Feeds;
use ShootCalInstagramFeed\Smash_Import;
use ShootCalInstagramFeed\Smash_Import_Admin;
use const ShootCalInstagramFeed\CACHE_KEY;
use const ShootCalInstagramFeed\FEEDS_KEY;
use const ShootCalInstagramFeed\OPTION_KEY;
use const ShootCalInstagramFeed\STATUS_KEY;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

global $wpdb, $shortcode_tags;
$assertions         = 0;
$initial_user       = get_current_user_id();
$initial_shortcode  = $shortcode_tags;
$initial_post       = $_POST;
$initial_method     = $_SERVER['REQUEST_METHOD'] ?? null;
$owned_tables       = array();
$owned_files        = array();
$owned_posts        = array();
$http_calls         = 0;
$assert = static function ( bool $condition, string $message ) use ( &$assertions ): void {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$protected_where = "option_name LIKE 'shootcal_instagram_%' OR option_name LIKE 'sbi_%' OR option_name IN ('active_plugins','cron','sidebars_widgets')";
$initial_rows = $wpdb->get_results( "SELECT option_id,option_name,option_value,autoload FROM {$wpdb->options} WHERE {$protected_where} ORDER BY option_name", ARRAY_A );
$deny_http = static function () use ( &$http_calls ) {
	++$http_calls;
	return new WP_Error( 'fixture_network_denied', 'Importer tests cannot make provider requests.' );
};
$die_filter = static function () {
	return static function ( $message, $title = '', $args = array() ): void {
		throw new RuntimeException( 'fixture_wp_die:' . wp_strip_all_tags( (string) $message ), (int) ( $args['response'] ?? 500 ) );
	};
};
$expect_die = static function ( callable $callback, int $response ) use ( $assert ): void {
	try {
		$callback();
	} catch ( RuntimeException $error ) {
		$assert( str_starts_with( $error->getMessage(), 'fixture_wp_die:' ) && $response === $error->getCode(), 'Admin request did not stop at its expected authorization boundary.' );
		return;
	}
	throw new RuntimeException( 'The rejected admin request unexpectedly returned.' );
};
add_filter( 'pre_http_request', $deny_http, PHP_INT_MAX );
add_filter( 'wp_die_handler', $die_filter );

try {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	$assert( ! empty( $admins ), 'The isolated test site has no administrator.' );
	$admin = new Smash_Import_Admin();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	wp_set_current_user( (int) $admins[0]->ID );
	$expect_die( array( $admin, 'handle_import' ), 405 );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	wp_set_current_user( 0 );
	$expect_die( array( $admin, 'handle_import' ), 403 );
	wp_set_current_user( (int) $admins[0]->ID );
	$_POST = array( '_wpnonce' => 'invalid-fixture-nonce' );
	$expect_die( array( $admin, 'handle_import' ), 403 );
	$deny_activation = static function ( array $capabilities ): array {
		$capabilities['activate_plugins'] = false;
		return $capabilities;
	};
	add_filter( 'user_has_cap', $deny_activation, PHP_INT_MAX );
	try {
		$_POST = array( '_wpnonce' => wp_create_nonce( 'shootcal_instagram_smash_switch' ) );
		$expect_die( array( $admin, 'handle_switch' ), 403 );
		$_POST = array( '_wpnonce' => wp_create_nonce( 'shootcal_instagram_smash_rollback' ) );
		$expect_die( array( $admin, 'handle_rollback' ), 403 );
	} finally {
		remove_filter( 'user_has_cap', $deny_activation, PHP_INT_MAX );
	}

	$source_table = $wpdb->prefix . 'sbi_sources';
	$feed_table = $wpdb->prefix . 'sbi_feeds';
	foreach ( array( $source_table, $feed_table ) as $table ) {
		$assert( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'Refusing to replace an existing Smash Balloon table.' );
	}
	$assert( false !== $wpdb->query( "CREATE TABLE `{$source_table}` (id bigint unsigned NOT NULL PRIMARY KEY, account_id varchar(191) NOT NULL, username varchar(191) NOT NULL, access_token text) ENGINE=InnoDB" ), 'Source fixture table creation failed.' );
	$owned_tables[] = $source_table;
	$assert( false !== $wpdb->query( "CREATE TABLE `{$feed_table}` (id bigint unsigned NOT NULL PRIMARY KEY, feed_name varchar(191) NOT NULL, settings longtext NOT NULL) ENGINE=InnoDB" ), 'Feed fixture table creation failed.' );
	$owned_tables[] = $feed_table;
	$account_id = '17841400000000001';
	$assert( ! is_wp_error( Config::store_connection( 'fixture-import-token', $account_id ) ), 'Could not set a local fixture connection.' );
	update_option( CACHE_KEY, array(
		'schema' => 1, 'fetched_at' => time(), 'account' => array( 'id' => $account_id, 'username' => 'shootcal_fixture' ),
		'items' => array(
			array( 'id' => 'fixture-one', 'caption' => 'One #wedding', 'media_type' => 'IMAGE', 'image_url' => 'https://example.test/one.jpg', 'permalink' => 'https://www.instagram.com/p/fixtureone/' ),
			array( 'id' => 'fixture-two', 'caption' => 'Two #family', 'media_type' => 'IMAGE', 'image_url' => 'https://example.test/two.jpg', 'permalink' => 'https://www.instagram.com/p/fixturetwo/' ),
		),
	), false );
	update_option( STATUS_KEY, array( 'last_success' => time() ), false );
	$existing = array( 'name' => 'Existing curated feed', 'hashtag' => '', 'exclude' => '', 'limit' => 5, 'columns' => 5, 'mobile_limit' => 4, 'follow' => true, 'dynamic' => true );
	update_option( FEEDS_KEY, array( 6 => $existing, 12 => array_merge( $existing, array( 'name' => 'An unrelated existing ID 12' ) ) ), false );
	delete_option( Smash_Import::OPTION );
	delete_option( 'shootcal_instagram_feed_smash_import_lock' );
	$wpdb->insert( $source_table, array( 'id' => 1, 'account_id' => $account_id, 'username' => 'shootcal_fixture', 'access_token' => 'source-token-must-never-be-copied' ) );
	$wpdb->insert( $source_table, array( 'id' => 2, 'account_id' => 'different-provider-id', 'username' => 'shootcal_fixture', 'access_token' => 'second-source-token-must-not-be-copied' ) );
	$base_settings = array( 'id' => array( $account_id ), 'type' => 'user', 'layout' => 'grid', 'num' => 5, 'cols' => 5, 'nummobile' => 4, 'showfollow' => true, 'includewords' => '#wedding', 'excludewords' => '' );
	$definitions = array(
		12 => $base_settings,
		13 => array_merge( $base_settings, array( 'includewords' => '21 Main, #21main' ) ),
		14 => array_merge( $base_settings, array( 'id' => array( 'foreign-account' ) ) ),
		15 => array_merge( $base_settings, array( 'id' => array( $account_id, 'foreign-account' ) ) ),
		16 => array_merge( $base_settings, array( 'layout' => 'carousel' ) ),
		17 => array_merge( $base_settings, array( 'moderationlist' => array( 'block_list' => array( 'hidden-post' ) ) ) ),
		18 => '{invalid json',
		19 => array_merge( $base_settings, array( 'id' => array( 'different-provider-id' ) ) ),
		20 => array_merge( $base_settings, array( 'moderationlist' => '{invalid json' ) ),
		21 => array_merge( $base_settings, array( 'id' => array( $account_id, array( 'malformed' ) ) ) ),
	);
	foreach ( $definitions as $id => $settings ) {
		$wpdb->insert( $feed_table, array( 'id' => $id, 'feed_name' => 'Fixture ' . $id, 'settings' => is_array( $settings ) ? wp_json_encode( $settings ) : $settings ) );
	}
	$source_before = $wpdb->get_results( "SELECT * FROM `{$source_table}` ORDER BY id", ARRAY_A );
	$definitions_before = $wpdb->get_results( "SELECT * FROM `{$feed_table}` ORDER BY id", ARRAY_A );
	$catalog = Smash_Import::catalog();
	$assert( count( $catalog ) === 10 && $catalog[12]['can_import'] && $catalog[19]['can_import'], 'Supported same-account or verified same-username feeds were not recognized.' );
	foreach ( array( 13, 14, 15, 16, 17, 18, 20, 21 ) as $id ) {
		$assert( ! $catalog[$id]['can_import'], 'An unsupported source was silently converted.' );
	}
	$assert( ! str_contains( wp_json_encode( $catalog ), 'source-token' ), 'Catalog exposed source credentials.' );
	$renderer = new Smash_Import();
	remove_shortcode( 'instagram-feed' );
	$renderer->register_compatibility();
	$assert( ! shortcode_exists( 'instagram-feed' ), 'Importer registered a legacy alias before an explicit switch.' );
	$before_feeds = get_option( FEEDS_KEY );
	$before_state = get_option( Smash_Import::OPTION, false );
	$fingerprint = Smash_Import::fingerprint( $catalog );
	$assert( is_wp_error( Smash_Import::import( array( 12 => 'new' ), $fingerprint, false ) ), 'Import ignored missing acknowledgement.' );
	$assert( is_wp_error( Smash_Import::import( array( 12 => 'new' ), str_repeat( '0', 64 ), true ) ), 'Import accepted a stale fingerprint.' );
	$assert( is_wp_error( Smash_Import::import( array( 13 => 'new' ), $fingerprint, true ) ), 'Import automatically converted a caption phrase filter.' );
	$assert( get_option( FEEDS_KEY ) === $before_feeds && get_option( Smash_Import::OPTION, false ) === $before_state, 'Rejected import changed saved state.' );

	// Force the second persistent write to fail after a new preset is inserted.
	$deny_state_write = static function () { return false; };
	add_filter( 'pre_update_option_' . Smash_Import::OPTION, $deny_state_write );
	add_filter( 'pre_add_option_' . Smash_Import::OPTION, $deny_state_write );
	try {
		$assert( is_wp_error( Smash_Import::import( array( 12 => 'new' ), $fingerprint, true ) ), 'Import did not report a mapping write failure.' );
		$assert( get_option( FEEDS_KEY ) === $before_feeds && get_option( Smash_Import::OPTION, false ) === $before_state, 'Mapping failure left an orphaned preset or partial import state.' );
	} finally {
		remove_filter( 'pre_update_option_' . Smash_Import::OPTION, $deny_state_write );
		remove_filter( 'pre_add_option_' . Smash_Import::OPTION, $deny_state_write );
	}
	$choices = array_fill_keys( array_keys( $catalog ), '6' );
	$choices[12] = 'new';
	$assert( true === Smash_Import::import( $choices, Smash_Import::fingerprint( Smash_Import::catalog() ), true ), 'Valid import failed.' );
	$state = Smash_Import::state();
	$new_id = (int) $state['mappings'][12];
	$assert( 13 === $new_id && 6 === $state['mappings'][13], 'Legacy feed IDs collided with ShootCal IDs or manual mapping was ignored.' );
	$assert( Feeds::get( 6 ) === $existing && Feeds::get( 12 ) === $before_feeds[12], 'Import overwrote an existing feed.' );
	$assert( Feeds::get( $new_id )['hashtag'] === 'wedding' && ! $state['enabled'], 'Imported hashtag or staged state is incorrect.' );
	$edited = Feeds::get( $new_id );
	$edited['name'] = 'Edited after import';
	Feeds::save( $new_id, $edited );
	$count_before = count( Feeds::all() );
	$assert( true === Smash_Import::import( array( 12 => 'new' ), Smash_Import::fingerprint( Smash_Import::catalog() ), true ), 'Repeat import failed.' );
	$assert( count( Feeds::all() ) === $count_before && Feeds::get( $new_id )['name'] === $edited['name'], 'Repeat import duplicated a preset or overwrote user edits.' );
	$renderer->register_compatibility();
	$assert( ! shortcode_exists( 'instagram-feed' ), 'Saving mappings enabled legacy shortcode takeover.' );

	$plugin = 'instagram-feed/instagram-feed.php';
	$fixture_file = WP_PLUGIN_DIR . '/' . $plugin;
	$assert( ! file_exists( dirname( $fixture_file ) ), 'Refusing to replace a real Smash Balloon installation.' );
	mkdir( dirname( $fixture_file ) );
	file_put_contents( $fixture_file, "<?php\n/* Plugin Name: Importer fixture Smash Balloon\nVersion: 1.0 */\nadd_shortcode('instagram-feed', static function () { return 'fixture-smash-renderer'; });\n" );
	$owned_files[] = $fixture_file;
	wp_cache_delete( 'plugins', 'plugins' );
	$assert( ! is_wp_error( activate_plugin( $plugin, '', false, true ) ), 'Could not activate fixture plugin.' );
	$assert( Smash_Import::active_plugins() === array( $plugin ), 'Active Smash Balloon plugin was not recognized.' );
	$renderer->register_compatibility();
	$assert( do_shortcode( '[instagram-feed feed=12]' ) === 'fixture-smash-renderer', 'Importer stole the shortcode from active Smash Balloon.' );
	$assert( empty( Smash_Import::switch_blockers() ), 'The valid previewed import has unexpected switch blockers: ' . implode( ' ', Smash_Import::switch_blockers() ) );
	$fixture_post = wp_insert_post( array( 'post_title' => 'Temporary importer regression fixture', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<!-- wp:sbi/sbi-feed-block {"feedId":"999"} /-->' ), true );
	$assert( ! is_wp_error( $fixture_post ) && $fixture_post > 0, 'Could not create the isolated content fixture.' );
	$owned_posts[] = $fixture_post;
	$assert( ! empty( Smash_Import::switch_blockers() ), 'An unmapped legacy sbi block did not block the switch.' );
	wp_update_post( array( 'ID' => $fixture_post, 'post_content' => '<!-- wp:sbi/sbi-feed-block {"feedId":"12"} /-->' ) );
	$assert( empty( Smash_Import::switch_blockers() ), 'A mapped supported legacy block could not switch.' );
	wp_update_post( array( 'ID' => $fixture_post, 'post_content' => '' ) );
	update_post_meta( $fixture_post, '_elementor_data', wp_slash( wp_json_encode( array( array( 'widgetType' => 'sb-instagram-feed', 'settings' => array() ) ) ) ) );
	$assert( ! empty( Smash_Import::switch_blockers() ), 'An unsupported Elementor Instagram widget did not block the switch.' );
	delete_post_meta( $fixture_post, '_elementor_data' );
	$before_sidebars = get_option( 'sidebars_widgets', false );
	update_option( 'sidebars_widgets', array( 'fixture-sidebar' => array( 'instagram-feed-widget-2' ) ) );
	$assert( ! empty( Smash_Import::switch_blockers() ), 'An active native Smash Balloon widget did not block the switch.' );
	update_option( 'sidebars_widgets', array( 'wp_inactive_widgets' => array( 'instagram-feed-widget-2' ) ) );
	$assert( empty( Smash_Import::switch_blockers() ), 'An inactive native widget unexpectedly blocked the switch.' );
	false === $before_sidebars ? delete_option( 'sidebars_widgets' ) : update_option( 'sidebars_widgets', $before_sidebars );
	$break_scan = static function ( string $query ) use ( $wpdb ): string {
		return str_starts_with( $query, "SELECT post_content FROM {$wpdb->posts} WHERE" ) ? 'SELECT * FROM scif_intentionally_missing_fixture_table' : $query;
	};
	$was_suppressed = $wpdb->suppress_errors( true );
	add_filter( 'query', $break_scan );
	try {
		$assert( ! empty( Smash_Import::switch_blockers() ) && is_wp_error( Smash_Import::switch( true ) ) && is_plugin_active( $plugin ), 'A failed SQL usage scan allowed deactivation.' );
	} finally {
		remove_filter( 'query', $break_scan );
		$wpdb->suppress_errors( $was_suppressed );
	}

	$smash_callback = $shortcode_tags['instagram-feed'];
	add_shortcode( 'instagram-feed', static function () { return 'unrelated-owner'; } );
	$assert( ! empty( Smash_Import::switch_blockers() ) && is_wp_error( Smash_Import::switch( true ) ) && is_plugin_active( $plugin ), 'Switch replaced a legacy shortcode owned by an unrelated plugin.' );
	$shortcode_tags['instagram-feed'] = $smash_callback;
	$assert( is_wp_error( Smash_Import::switch( true, str_repeat( '0', 64 ) ) ) && is_plugin_active( $plugin ), 'Switch accepted a stale review fingerprint.' );
	$wpdb->update( $feed_table, array( 'settings' => wp_json_encode( array_merge( $base_settings, array( 'num' => 6 ) ) ) ), array( 'id' => 12 ) );
	$assert( ! empty( Smash_Import::switch_blockers() ), 'Switch did not detect a Smash Balloon definition changed after import.' );
	$wpdb->update( $feed_table, array( 'settings' => wp_json_encode( $base_settings ) ), array( 'id' => 12 ) );

	$assert( is_wp_error( Smash_Import::switch( false ) ) && is_plugin_active( $plugin ) && ! Smash_Import::state()['enabled'], 'Switch disabled another plugin without confirmation.' );
	$before_switch = get_option( Smash_Import::OPTION );
	$deny_switch_state = static function ( $new_value, $old_value ) { return $old_value; };
	add_filter( 'pre_update_option_' . Smash_Import::OPTION, $deny_switch_state, 10, 2 );
	try {
		$assert( is_wp_error( Smash_Import::switch( true ) ) && is_plugin_active( $plugin ) && get_option( Smash_Import::OPTION ) === $before_switch, 'Failed recovery-state write still deactivated Smash Balloon.' );
	} finally {
		remove_filter( 'pre_update_option_' . Smash_Import::OPTION, $deny_switch_state );
	}
	$assert( true === Smash_Import::switch( true ) && ! is_plugin_active( $plugin ), 'Explicit switch did not deactivate the fixture plugin.' );
	$assert( Smash_Import::state()['enabled'] && Smash_Import::state()['deactivated'] === array( $plugin ), 'Switch did not preserve its rollback record.' );
	remove_shortcode( 'instagram-feed' ); // Simulate the next request after deactivation.
	$renderer->register_compatibility();
	$rendered = do_shortcode( '[instagram-feed feed=12 num=1 cols=2 showfollow=false]' );
	$assert( str_contains( $rendered, 'shootcal-instagram-feed' ) && str_contains( $rendered, 'fixtureone' ) && ! str_contains( $rendered, 'fixturetwo' ), 'Legacy ID and supported overrides did not render the mapped preset.' );
	$assert( is_wp_error( Smash_Import::translate_attributes( array( 'feed' => 999 ) ) ), 'An unmapped ID silently fell back to another feed.' );
	$assert( is_wp_error( Smash_Import::translate_attributes( array( 'feed' => 12, 'includewords' => 'changed words' ) ) ), 'Unsupported inline options were silently ignored.' );
	$assert( is_wp_error( Smash_Import::import( array( 12 => '6' ), Smash_Import::fingerprint( Smash_Import::catalog() ), true ) ), 'Mappings changed while compatibility was enabled.' );
	$assert( true === Smash_Import::rollback() && is_plugin_active( $plugin ) && ! Smash_Import::state()['enabled'], 'Undo did not restore the plugin this importer disabled.' );
	$assert( Feeds::get( $new_id )['name'] === $edited['name'] && Smash_Import::state()['mappings'][12] === $new_id, 'Undo deleted imported feeds or mappings.' );
	deactivate_plugins( $plugin, true, false );
	remove_shortcode( 'instagram-feed' );
	$assert( true === Smash_Import::switch( false ) && empty( Smash_Import::state()['deactivated'] ), 'Inactive-plugin switch invented a deactivation record.' );
	$assert( true === Smash_Import::rollback() && ! is_plugin_active( $plugin ), 'Undo activated a plugin that the importer had not disabled.' );
	$assert( $source_before === $wpdb->get_results( "SELECT * FROM `{$source_table}` ORDER BY id", ARRAY_A ) && $definitions_before === $wpdb->get_results( "SELECT * FROM `{$feed_table}` ORDER BY id", ARRAY_A ), 'Import changed the original Smash Balloon data.' );

	$assert( ! is_wp_error( Config::store_connection( 'fixture-second-token', '17841400000000999' ) ), 'Could not change the isolated account fixture.' );
	$assert( is_wp_error( Smash_Import::translate_attributes( array( 'feed' => 12 ) ) ), 'Old mapping crossed the account boundary.' );
	$assert( true === Smash_Import::import( array( 12 => '6' ), Smash_Import::fingerprint( Smash_Import::catalog() ), true ), 'Explicit partial remap to a different account failed.' );
	$assert( Smash_Import::state()['mappings'] === array( 12 => 6 ) && ! isset( Smash_Import::state()['fingerprints'][13] ), 'Partial import reauthorized unrelated mappings from the prior account.' );
	$assert( is_wp_error( Smash_Import::translate_attributes( array( 'feed' => 13 ) ) ), 'A stale account mapping remained usable after partial import.' );
	$assert( 0 === $http_calls, 'Importer attempted an outbound provider request.' );
	echo 'smash-import: ok (' . $assertions . " assertions; auth, catalog, ID mapping, rollback, coexistence, write failure, no provider calls)\n";

} finally {
	remove_filter( 'pre_http_request', $deny_http, PHP_INT_MAX );
	remove_filter( 'wp_die_handler', $die_filter );
	foreach ( $owned_posts as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	foreach ( array_reverse( $owned_tables ) as $table ) {
		$wpdb->query( "DROP TABLE `{$table}`" );
	}
	foreach ( array_reverse( $owned_files ) as $file ) {
		unlink( $file );
		if ( is_dir( dirname( $file ) ) && 2 === count( scandir( dirname( $file ) ) ) ) {
			rmdir( dirname( $file ) );
		}
	}
	$now = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE {$protected_where}" );
	foreach ( $now as $key ) {
		$wpdb->delete( $wpdb->options, array( 'option_name' => $key ) );
		wp_cache_delete( $key, 'options' );
	}
	foreach ( $initial_rows as $row ) {
		$wpdb->insert( $wpdb->options, $row );
		wp_cache_delete( $row['option_name'], 'options' );
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	$restored_rows = $wpdb->get_results( "SELECT option_id,option_name,option_value,autoload FROM {$wpdb->options} WHERE {$protected_where} ORDER BY option_name", ARRAY_A );
	if ( $restored_rows !== $initial_rows ) {
		throw new RuntimeException( 'Importer fixture cleanup did not restore exact protected option rows.' );
	}
	$shortcode_tags = $initial_shortcode;
	$_POST = $initial_post;
	if ( null === $initial_method ) {
		unset( $_SERVER['REQUEST_METHOD'] );
	} else {
		$_SERVER['REQUEST_METHOD'] = $initial_method;
	}
	wp_set_current_user( $initial_user );
}
