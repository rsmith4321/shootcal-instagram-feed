<?php
/**
 * Small Instagram Graph API client for one connected professional account.
 *
 * @package ShootCalInstagramFeed
 */

declare( strict_types=1 );

namespace ShootCalInstagramFeed;

defined( 'ABSPATH' ) || exit;

class Api_Client {

	private const API_HOST = 'graph.facebook.com';
	private const MAX_PAGES = 10;

	/**
	 * Fetch basic information about the token's Instagram account.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function account( string $account_id, string $token ) {
		return $this->get(
			$this->versioned_url( $account_id ),
			array(
				'fields' => 'id,username',
			),
			$token
		);
	}

	/**
	 * Fetch recent media, following cursor pagination to the configured cap.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function media( string $account_id, string $token, int $scan_limit ): array|\WP_Error {
		$scan_limit = max( 1, min( 100, $scan_limit ) );
		$items      = array();
		$after      = '';
		$seen       = array();

		for ( $page = 0; $page < self::MAX_PAGES && count( $items ) < $scan_limit; $page++ ) {
			$query = array(
				'fields' => 'id,caption,media_type,media_product_type,media_url,thumbnail_url,permalink,timestamp,children{id,media_type,media_url,thumbnail_url}',
				'limit'  => min( 50, $scan_limit - count( $items ) ),
			);

			if ( '' !== $after ) {
				$query['after'] = $after;
			}

			$response = $this->get( $this->versioned_url( $account_id . '/media' ), $query, $token );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
			foreach ( $data as $item ) {
				if ( is_array( $item ) ) {
					$items[] = $item;
				}
				if ( count( $items ) >= $scan_limit ) {
					break;
				}
			}

			$next_after = $response['paging']['cursors']['after'] ?? '';
			$next_after = is_string( $next_after ) ? $next_after : '';
			if ( '' === $next_after || isset( $seen[ $next_after ] ) || empty( $data ) ) {
				break;
			}

			$seen[ $next_after ] = true;
			$after               = $next_after;
		}

		return array_slice( $items, 0, $scan_limit );
	}

	private function versioned_url( string $path ): string {
		$version = (string) apply_filters( 'shootcal_instagram_graph_version', GRAPH_VERSION );
		$version = preg_match( '/^v\d+\.\d+$/', $version ) ? $version : GRAPH_VERSION;

		return 'https://' . self::API_HOST . '/' . $version . '/' . ltrim( $path, '/' );
	}

	/**
	 * @param array<string, int|string> $query Query parameters.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function get( string $url, array $query, string $token ) {
		return $this->request( add_query_arg( $query, $url ), $token );
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private function request( string $url, string $token ) {
		$headers = array(
			'Accept'     => 'application/json',
			'User-Agent' => 'ShootCal-Instagram-Feed/' . VERSION,
		);
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => 0,
				'headers'             => $headers,
				'limit_response_size' => 5 * MB_IN_BYTES,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'shootcal_instagram_transport_error',
				sprintf(
					/* translators: %s: sanitized WordPress HTTP error. */
					__( 'Instagram could not be reached: %s', 'shootcal-instagram-feed' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$json   = json_decode( $body, true );

		if ( ! is_array( $json ) ) {
			return new \WP_Error(
				'shootcal_instagram_invalid_json',
				sprintf(
					/* translators: %d: HTTP response status. */
					__( 'Instagram returned an unreadable response (HTTP %d).', 'shootcal-instagram-feed' ),
					$status
				)
			);
		}

		if ( $status < 200 || $status >= 300 || isset( $json['error'] ) ) {
			$error     = isset( $json['error'] ) && is_array( $json['error'] ) ? $json['error'] : array();
			$message   = isset( $error['message'] ) && is_string( $error['message'] ) ? sanitize_text_field( $error['message'] ) : __( 'Unknown Instagram API error.', 'shootcal-instagram-feed' );
			$meta_code = isset( $error['code'] ) ? (int) $error['code'] : 0;

			return new \WP_Error(
				'shootcal_instagram_api_error',
				sprintf(
					/* translators: 1: Instagram error text. 2: Meta error code. */
					__( '%1$s (Meta code %2$d)', 'shootcal-instagram-feed' ),
					$message,
					$meta_code
				),
				array(
					'http_status' => $status,
					'meta_code'   => $meta_code,
				)
			);
		}

		return $json;
	}
}
