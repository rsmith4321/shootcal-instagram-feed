<?php
/**
 * WordPress integration regression test. Run with `wp eval-file` while the
 * plugin is active. This file is excluded from release packages.
 */

declare( strict_types=1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

require_once __DIR__ . '/restore-options.php';

use ShootCalInstagramFeed\Api_Client;
use ShootCalInstagramFeed\Config;
use ShootCalInstagramFeed\Feed_Store;
use ShootCalInstagramFeed\Feeds;
use ShootCalInstagramFeed\Rest_Controller;
use ShootCalInstagramFeed\Settings;
use ShootCalInstagramFeed\Shortcode;
use const ShootCalInstagramFeed\CACHE_KEY;
use const ShootCalInstagramFeed\FEEDS_KEY;
use const ShootCalInstagramFeed\LOCK_KEY;
use const ShootCalInstagramFeed\OAUTH_KEY;
use const ShootCalInstagramFeed\OPTION_KEY;
use const ShootCalInstagramFeed\STATUS_KEY;
use const ShootCalInstagramFeed\VERSION;

if ( ! function_exists( 'get_plugin_data' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$plugin_data = get_plugin_data( dirname( __DIR__ ) . '/shootcal-instagram-feed.php', false, false );
if ( 'ShootCal Social Feed' !== ( $plugin_data['Name'] ?? '' ) ) {
	throw new RuntimeException( 'The plugin does not expose the selected ShootCal Social Feed name.' );
}

$original_options = get_option( OPTION_KEY, false );
$original_cache   = get_option( CACHE_KEY, false );
$original_status  = get_option( STATUS_KEY, false );
$original_lock    = get_option( LOCK_KEY, false );
$original_oauth   = get_option( OAUTH_KEY, false );
$original_feeds   = get_option( FEEDS_KEY, false );
$account_id       = '17841400000000001';
$expected_token   = 'integration-test-token';
$mode             = 'success';

$response = static function ( array $body ): array {
	return array(
		'headers'  => array(),
		'body'     => wp_json_encode( $body ),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
	);
};

$http_mock = static function ( $preempt, array $args, string $url ) use ( &$mode, $account_id, $expected_token, $response ) {
	if ( ! str_starts_with( $url, 'https://graph.facebook.com/' ) ) {
		return $preempt;
	}

	if ( 'Bearer ' . $expected_token !== ( $args['headers']['Authorization'] ?? '' ) ) {
		throw new RuntimeException( 'The Graph request did not use the expected bearer token.' );
	}

	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	if ( str_ends_with( $path, '/' . $account_id ) ) {
		return $response(
			array(
				'id'       => $account_id,
				'username' => 'shootcal_fixture',
			)
		);
	}

	if ( ! str_ends_with( $path, '/' . $account_id . '/media' ) ) {
		throw new RuntimeException( 'Unexpected Graph API path: ' . $path );
	}

	if ( 'outage' === $mode ) {
		return new WP_Error( 'mock_outage', 'Simulated Meta outage.' );
	}

	if ( 'unusable' === $mode ) {
		return $response(
			array(
				'data' => array(
					array(
						'id'                 => 'unusable-video',
						'caption'            => '#Wedding',
						'media_type'         => 'VIDEO',
						'media_product_type' => 'REELS',
						'media_url'          => 'https://example.test/video.mp4',
						'permalink'          => 'https://www.instagram.com/reel/unusable/',
						'timestamp'          => '2026-08-27T12:00:00+0000',
					),
				),
			)
		);
	}

	$query = array();
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
	if ( empty( $query['after'] ) ) {
		return $response(
			array(
				'data'   => array(
					array(
						'id'                 => 'image-one',
						'caption'            => 'One #Wedding',
						'media_type'         => 'IMAGE',
						'media_product_type' => 'FEED',
						'media_url'          => 'https://example.test/image-one.jpg',
						'permalink'          => 'https://www.instagram.com/p/image-one/',
						'timestamp'          => '2026-08-27T12:00:00+0000',
					),
					array(
						'id'                 => 'video-two',
						'caption'            => 'Two #WeddingPhotography',
						'media_type'         => 'VIDEO',
						'media_product_type' => 'REELS',
						'media_url'          => 'https://example.test/video-two.mp4',
						'thumbnail_url'      => 'https://example.test/video-two.jpg',
						'permalink'          => 'https://www.instagram.com/reel/video-two/',
						'timestamp'          => '2026-08-26T12:00:00+0000',
					),
				),
				'paging' => array(
					'cursors' => array( 'after' => 'cursor-two' ),
				),
			)
		);
	}

	return $response(
		array(
			'data' => array(
				array(
					'id'                 => 'carousel-three',
					'caption'            => 'Three #Wedding',
					'media_type'         => 'CAROUSEL_ALBUM',
					'media_product_type' => 'FEED',
					'permalink'          => 'https://www.instagram.com/p/carousel-three/',
					'timestamp'          => '2026-08-25T12:00:00+0000',
					'children'           => array(
						'data' => array(
							array(
								'id'         => 'carousel-child',
								'media_type' => 'IMAGE',
								'media_url'  => 'https://example.test/carousel-three.jpg',
							),
						),
					),
				),
			),
		)
	);
};

add_filter( 'pre_http_request', $http_mock, 10, 3 );

try {
	// Reproduce the normal wp-admin boundary where register_setting() has added
	// its sanitizer before the OAuth callback performs a trusted internal save.
	( new Settings( new Feed_Store( new Api_Client() ) ) )->register_settings();
	$stored = Config::store_connection( $expected_token, $account_id );
	if ( is_wp_error( $stored ) ) {
		throw new RuntimeException( $stored->get_error_message() );
	}
	$stored_options = Config::get();
	$decrypted      = Config::access_token();
	if ( is_wp_error( $decrypted ) || $expected_token !== $decrypted
		|| $account_id !== $stored_options['instagram_account_id'] ) {
		throw new RuntimeException( 'The OAuth connection was not stored as one encrypted account boundary.' );
	}
	$before_invalid = get_option( OPTION_KEY, array() );
	$invalid_store  = Config::store_connection( 'replacement-token-value', 'not-an-account' );
	if ( ! is_wp_error( $invalid_store ) || $before_invalid !== get_option( OPTION_KEY, array() ) ) {
		throw new RuntimeException( 'An invalid broker account changed the saved connection.' );
	}
	Config::clear_token();
	$cleared_options = Config::get();
	if ( '' !== $cleared_options['access_token']
		|| '' !== $cleared_options['instagram_account_id']
		|| 0 !== $cleared_options['token_updated_at'] ) {
		throw new RuntimeException( 'Disconnect did not clear the encrypted token and selected account boundary.' );
	}
	$stored = Config::store_connection( $expected_token, $account_id );
	if ( is_wp_error( $stored ) ) {
		throw new RuntimeException( $stored->get_error_message() );
	}
	$stored_options = Config::get();

	$stored_options['default_hashtag'] = '';
	$stored_options['display_limit']   = 9;
	$stored_options['columns']         = 3;
	$stored_options['scan_limit']      = 3;
	update_option( OPTION_KEY, $stored_options, false );
	delete_option( LOCK_KEY );

	$store  = new Feed_Store( new Api_Client() );
	$result = $store->refresh();
	if ( is_wp_error( $result ) || 3 !== count( $result['items'] ?? array() ) ) {
		throw new RuntimeException( 'Pagination or media normalization failed.' );
	}
	if ( 'https://example.test/video-two.jpg' !== ( $result['items'][1]['image_url'] ?? '' ) ) {
		throw new RuntimeException( 'Video thumbnail normalization failed.' );
	}
	if ( 'https://example.test/carousel-three.jpg' !== ( $result['items'][2]['image_url'] ?? '' ) ) {
		throw new RuntimeException( 'Carousel cover normalization failed.' );
	}

	$good_cache = Feed_Store::cache();
	$rendered   = ( new Shortcode() )->render(
		array(
			'hashtag'      => 'wedding',
			'limit'        => 2,
			'columns'      => 2,
			'mobile_limit' => 1,
			'follow'       => 'true',
		)
	);
	if ( 2 !== substr_count( $rendered, '<a class="shootcal-instagram-feed__item' ) ) {
		throw new RuntimeException( 'The shortcode did not render the requested exact-hashtag items.' );
	}
	if ( 1 !== substr_count( $rendered, 'shootcal-instagram-feed__item--mobile-hidden' ) ) {
		throw new RuntimeException( 'The shortcode did not apply the mobile display limit.' );
	}
	if ( ! str_contains( $rendered, 'https://www.instagram.com/shootcal_fixture/' ) || ! str_contains( $rendered, 'Follow on Instagram' ) ) {
		throw new RuntimeException( 'The shortcode did not render the requested account follow link.' );
	}

	$dynamic = ( new Shortcode() )->render(
		array(
			'hashtag'      => 'wedding',
			'limit'        => 2,
			'columns'      => 2,
			'mobile_limit' => 1,
			'follow'       => 'true',
			'class'        => 'fixture-class',
			'dynamic'      => 'true',
		)
	);
	if ( ! str_contains( $dynamic, 'shootcal-instagram-feed-loader' ) || ! str_contains( $dynamic, '/shootcal-instagram-feed/v1/feed' ) ) {
		throw new RuntimeException( 'The dynamic shortcode did not render its cache-only loader.' );
	}
	if ( ! str_contains( $dynamic, 'data-stylesheet=' ) || ! str_contains( $dynamic, '/' . dirname( plugin_basename( \ShootCalInstagramFeed\PLUGIN_FILE ) ) . '/assets/feed.css?ver=' . VERSION ) ) {
		throw new RuntimeException( 'The dynamic shortcode did not expose its versioned runtime stylesheet.' );
	}
	if ( ! str_contains( $dynamic, 'data-class="fixture-class"' ) || ! str_contains( $dynamic, 'shootcal-instagram-feed fixture-class' ) ) {
		throw new RuntimeException( 'The dynamic shortcode did not preserve its custom class.' );
	}

	$invalid_dynamic = ( new Shortcode() )->render(
		array(
			'hashtag' => 'not-valid!',
			'dynamic' => 'true',
		)
	);
	if ( str_contains( $invalid_dynamic, 'shootcal-instagram-feed-loader' ) || str_contains( $invalid_dynamic, 'shootcal-instagram-feed__item' ) ) {
		throw new RuntimeException( 'An invalid dynamic hashtag exposed an unfiltered feed.' );
	}

	$force_dynamic = static function ( array $output ): array {
		$output['dynamic'] = 'true';
		return $output;
	};
	add_filter( 'shortcode_atts_' . Shortcode::TAG, $force_dynamic );
	$filtered_dynamic = ( new Shortcode() )->render( array( 'hashtag' => 'wedding' ) );
	remove_filter( 'shortcode_atts_' . Shortcode::TAG, $force_dynamic );
	if ( 1 !== substr_count( $filtered_dynamic, 'shootcal-instagram-feed-loader' ) ) {
		throw new RuntimeException( 'A shortcode attributes filter caused recursive dynamic fallback rendering.' );
	}

	$request = new WP_REST_Request( 'GET', '/' . Rest_Controller::ROUTE );
	$request->set_param( 'hashtag', 'wedding' );
	$request->set_param( 'limit', 2 );
	$request->set_param( 'columns', 2 );
	$request->set_param( 'mobile_limit', 1 );
	$request->set_param( 'follow', 'true' );
	$request->set_param( 'class', 'fixture-class' );
	$response_data = ( new Rest_Controller() )->get_feed( $request );
	$payload       = $response_data->get_data();
	$headers       = $response_data->get_headers();
	if ( 200 !== $response_data->get_status() || 2 !== substr_count( (string) ( $payload['html'] ?? '' ), '<a class="shootcal-instagram-feed__item' ) ) {
		throw new RuntimeException( 'The cache-only REST response did not render the requested feed.' );
	}
	if ( str_contains( (string) ( $payload['html'] ?? '' ), 'shootcal-instagram-feed-loader' ) ) {
		throw new RuntimeException( 'The REST response recursively rendered a dynamic loader.' );
	}
	if ( ! str_contains( (string) ( $payload['html'] ?? '' ), 'shootcal-instagram-feed fixture-class' ) ) {
		throw new RuntimeException( 'The REST response did not preserve its custom class.' );
	}
	if ( 'no-store' !== ( $headers['Cache-Control'] ?? '' ) ) {
		throw new RuntimeException( 'The REST response did not prevent stale account HTML caching.' );
	}
	delete_option( FEEDS_KEY );
	if ( ! is_wp_error( Feeds::save( 0, array( 'name' => '', 'hashtag' => 'wedding' ) ) ) ) {
		throw new RuntimeException( 'A nameless feed preset was accepted.' );
	}
	if ( ! is_wp_error( Feeds::save( 0, array( 'name' => 'Bad', 'hashtag' => 'not-valid!' ) ) ) ) {
		throw new RuntimeException( 'An invalid preset hashtag list was accepted.' );
	}
	$preset_id = Feeds::save(
		0,
		array(
			'name'    => 'Weddings',
			'hashtag' => '#Wedding, #WeddingPhotography',
			'exclude' => '',
			'limit'   => 5,
			'columns' => 5,
			'follow'  => true,
			'dynamic' => true,
		)
	);
	if ( is_wp_error( $preset_id ) || 1 !== $preset_id ) {
		throw new RuntimeException( 'Saving a valid feed preset failed.' );
	}
	$preset = Feeds::get( $preset_id );
	if ( null === $preset || 'wedding, weddingphotography' !== $preset['hashtag'] ) {
		throw new RuntimeException( 'The stored preset did not normalize its hashtag list.' );
	}

	$preset_rendered = ( new Shortcode() )->render( array( 'feed' => (string) $preset_id, 'dynamic' => 'false' ) );
	if ( 3 !== substr_count( $preset_rendered, '<a class="shootcal-instagram-feed__item' ) ) {
		throw new RuntimeException( 'The preset shortcode did not render its any-of hashtag matches.' );
	}
	$override_rendered = ( new Shortcode() )->render( array( 'feed' => (string) $preset_id, 'limit' => 1, 'dynamic' => 'false' ) );
	if ( 1 !== substr_count( $override_rendered, '<a class="shootcal-instagram-feed__item' ) ) {
		throw new RuntimeException( 'An explicit shortcode attribute did not override the preset.' );
	}

	$excluded_id = Feeds::save( 0, array( 'name' => 'No photography tag', 'hashtag' => 'wedding, weddingphotography', 'exclude' => 'weddingphotography', 'limit' => 9 ) );
	if ( is_wp_error( $excluded_id ) ) {
		throw new RuntimeException( 'Saving an exclude-list preset failed.' );
	}
	$excluded_rendered = ( new Shortcode() )->render( array( 'feed' => (string) $excluded_id, 'dynamic' => 'false' ) );
	if ( 2 !== substr_count( $excluded_rendered, '<a class="shootcal-instagram-feed__item' ) ) {
		throw new RuntimeException( 'The preset exclude list did not drop matching posts.' );
	}

	$preset_dynamic = ( new Shortcode() )->render( array( 'feed' => (string) $preset_id ) );
	$loader_tag     = substr( $preset_dynamic, 0, (int) strpos( $preset_dynamic, '>' ) );
	if ( ! str_contains( $loader_tag, 'shootcal-instagram-feed-loader' ) || ! str_contains( $loader_tag, 'data-feed="1"' ) ) {
		throw new RuntimeException( 'The preset dynamic loader did not carry its feed id.' );
	}
	if ( str_contains( $loader_tag, 'data-hashtag=' ) ) {
		throw new RuntimeException( 'The preset dynamic loader baked resolved hashtags into cached markup.' );
	}

	$preset_request = new WP_REST_Request( 'GET', '/' . Rest_Controller::ROUTE );
	$preset_request->set_query_params( array( 'feed' => (string) $preset_id ) );
	$preset_payload = ( new Rest_Controller() )->get_feed( $preset_request )->get_data();
	if ( 3 !== substr_count( (string) ( $preset_payload['html'] ?? '' ), '<a class="shootcal-instagram-feed__item' ) ) {
		throw new RuntimeException( 'The REST route did not resolve a preset feed.' );
	}
	$preset_request = new WP_REST_Request( 'GET', '/' . Rest_Controller::ROUTE );
	$preset_request->set_query_params( array( 'feed' => (string) $preset_id, 'limit' => '1' ) );
	$preset_payload = ( new Rest_Controller() )->get_feed( $preset_request )->get_data();
	if ( 1 !== substr_count( (string) ( $preset_payload['html'] ?? '' ), '<a class="shootcal-instagram-feed__item' ) ) {
		throw new RuntimeException( 'A sent REST parameter did not override the preset.' );
	}

	if ( '' !== ( new Shortcode() )->render( array( 'feed' => '999', 'dynamic' => 'false' ) ) ) {
		throw new RuntimeException( 'A missing preset leaked output to visitors.' );
	}
	Feeds::delete( (int) $excluded_id );
	if ( null !== Feeds::get( (int) $excluded_id ) ) {
		throw new RuntimeException( 'Deleting a feed preset failed.' );
	}

	$mode       = 'outage';
	$result     = $store->refresh();
	if ( ! is_wp_error( $result ) || $good_cache !== Feed_Store::cache() ) {
		throw new RuntimeException( 'A transport failure did not preserve the last-known-good cache.' );
	}

	$mode   = 'unusable';
	$result = $store->refresh();
	if ( ! is_wp_error( $result ) || $good_cache !== Feed_Store::cache() ) {
		throw new RuntimeException( 'An unusable media response did not preserve the last-known-good cache.' );
	}

	$video_without_thumbnail = Feed_Store::normalize_item(
		array(
			'id'                 => 'video-without-thumbnail',
			'media_type'         => 'VIDEO',
			'media_product_type' => 'REELS',
			'media_url'          => 'https://example.test/video.mp4',
			'permalink'          => 'https://www.instagram.com/reel/no-thumbnail/',
		)
	);
	if ( null !== $video_without_thumbnail ) {
		throw new RuntimeException( 'A video without a thumbnail was accepted as an image.' );
	}

	$carousel_video_without_thumbnail = Feed_Store::normalize_item(
		array(
			'id'         => 'carousel-video-without-thumbnail',
			'media_type' => 'CAROUSEL_ALBUM',
			'permalink'  => 'https://www.instagram.com/p/carousel-video-no-thumbnail/',
			'children'   => array(
				'data' => array(
					array(
						'id'         => 'carousel-video-child',
						'media_type' => 'VIDEO',
						'media_url'  => 'https://example.test/carousel-video.mp4',
					),
				),
			),
		)
	);
	if ( null !== $carousel_video_without_thumbnail ) {
		throw new RuntimeException( 'A carousel video child without a thumbnail was accepted as an image.' );
	}

	$carousel_video_with_thumbnail = Feed_Store::normalize_item(
		array(
			'id'         => 'carousel-video-with-thumbnail',
			'media_type' => 'CAROUSEL_ALBUM',
			'permalink'  => 'https://www.instagram.com/p/carousel-video-thumbnail/',
			'children'   => array(
				'data' => array(
					array(
						'id'            => 'carousel-video-child-with-thumbnail',
						'media_type'    => 'VIDEO',
						'media_url'     => 'https://example.test/carousel-video.mp4',
						'thumbnail_url' => 'https://example.test/carousel-video.jpg',
					),
				),
			),
		)
	);
	if ( 'https://example.test/carousel-video.jpg' !== ( $carousel_video_with_thumbnail['image_url'] ?? '' ) ) {
		throw new RuntimeException( 'A carousel video child thumbnail was not normalized.' );
	}

	$options                         = get_option( OPTION_KEY, array() );
	$options['instagram_account_id'] = '17841400000000999';
	update_option( OPTION_KEY, $options, false );
	if ( array() !== Feed_Store::cache_for_account( $options['instagram_account_id'] ) ) {
		throw new RuntimeException( 'Cached media crossed an Instagram account boundary.' );
	}
	if ( str_contains( ( new Shortcode() )->render(), 'shootcal-instagram-feed__item' ) ) {
		throw new RuntimeException( 'The shortcode rendered media from a previously configured account.' );
	}

	echo "wordpress-integration: ok\n";
} finally {
	remove_filter( 'pre_http_request', $http_mock, 10 );

	shootcal_instagram_restore_test_options( array(
		OPTION_KEY => $original_options,
		CACHE_KEY => $original_cache,
		STATUS_KEY => $original_status,
		LOCK_KEY => $original_lock,
		OAUTH_KEY => $original_oauth,
		FEEDS_KEY => $original_feeds,
	) );
}
