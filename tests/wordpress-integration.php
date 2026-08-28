<?php
/**
 * WordPress integration regression test. Run with `wp eval-file` while the
 * plugin is active. This file is excluded from release packages.
 */

declare( strict_types=1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

use ShootCalInstagramFeed\Api_Client;
use ShootCalInstagramFeed\Feed_Store;
use ShootCalInstagramFeed\Shortcode;
use ShootCalInstagramFeed\Token_Cipher;
use const ShootCalInstagramFeed\CACHE_KEY;
use const ShootCalInstagramFeed\LOCK_KEY;
use const ShootCalInstagramFeed\OPTION_KEY;
use const ShootCalInstagramFeed\STATUS_KEY;

$original_options = get_option( OPTION_KEY, false );
$original_cache   = get_option( CACHE_KEY, false );
$original_status  = get_option( STATUS_KEY, false );
$original_lock    = get_option( LOCK_KEY, false );
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
	$encrypted = Token_Cipher::encrypt( $expected_token );
	if ( is_wp_error( $encrypted ) ) {
		throw new RuntimeException( $encrypted->get_error_message() );
	}

	update_option(
		OPTION_KEY,
		array(
			'access_token'         => $encrypted,
			'instagram_account_id' => $account_id,
			'token_updated_at'     => time(),
			'default_hashtag'      => '',
			'display_limit'        => 9,
			'columns'              => 3,
			'scan_limit'           => 3,
		),
		false
	);
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

	false === $original_options ? delete_option( OPTION_KEY ) : update_option( OPTION_KEY, $original_options, false );
	false === $original_cache ? delete_option( CACHE_KEY ) : update_option( CACHE_KEY, $original_cache, false );
	false === $original_status ? delete_option( STATUS_KEY ) : update_option( STATUS_KEY, $original_status, false );
	false === $original_lock ? delete_option( LOCK_KEY ) : update_option( LOCK_KEY, $original_lock, false );
}
