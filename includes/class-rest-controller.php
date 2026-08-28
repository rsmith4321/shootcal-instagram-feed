<?php
/**
 * Public cache-only feed endpoint for dynamic page rendering.
 *
 * @package ShootCalInstagramFeed
 */

declare( strict_types=1 );

namespace ShootCalInstagramFeed;

use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

class Rest_Controller {

	public const ROUTE = 'shootcal-instagram-feed/v1/feed';

	private const REST_NAMESPACE = 'shootcal-instagram-feed/v1';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	public function register_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/feed',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_feed' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'hashtag'      => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ): bool {
							$value = trim( (string) $value );
							return '' === $value || '' !== Hashtag_Filter::normalize( $value );
						},
					),
					'limit'        => array(
						'default'           => 9,
						'sanitize_callback' => 'absint',
					),
					'columns'      => array(
						'default'           => 3,
						'sanitize_callback' => 'absint',
					),
					'mobile_limit' => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'follow'       => array(
						'default'           => 'false',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'class'        => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	public function get_feed( WP_REST_Request $request ): WP_REST_Response {
		$attributes = array(
			'hashtag'      => (string) $request->get_param( 'hashtag' ),
			'limit'        => max( 1, min( 30, (int) $request->get_param( 'limit' ) ) ),
			'columns'      => max( 1, min( 6, (int) $request->get_param( 'columns' ) ) ),
			'mobile_limit' => max( 0, min( 30, (int) $request->get_param( 'mobile_limit' ) ) ),
			'follow'       => (string) $request->get_param( 'follow' ),
			'class'        => (string) $request->get_param( 'class' ),
			'dynamic'      => 'false',
		);

		$options    = Config::get();
		$cache      = Feed_Store::cache_for_account( (string) $options['instagram_account_id'] );
		$fetched_at = isset( $cache['fetched_at'] ) ? (int) $cache['fetched_at'] : 0;
		$response   = new WP_REST_Response(
			array(
				'html'       => ( new Shortcode() )->render( $attributes ),
				'fetched_at' => $fetched_at,
			)
		);

		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );

		return $response;
	}
}
