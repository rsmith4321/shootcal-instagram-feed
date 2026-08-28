<?php
/**
 * Last-known-good feed cache and normalization.
 *
 * @package ShootCalInstagramFeed
 */

declare( strict_types=1 );

namespace ShootCalInstagramFeed;

defined( 'ABSPATH' ) || exit;

class Feed_Store {

	public function __construct( private Api_Client $client ) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function cache(): array {
		$cache = get_option( CACHE_KEY, array() );

		return is_array( $cache ) ? $cache : array();
	}

	/**
	 * Return cached media only when it belongs to the currently configured account.
	 *
	 * This prevents a failed account switch from exposing the previous account's
	 * last-known-good feed.
	 *
	 * @return array<string, mixed>
	 */
	public static function cache_for_account( string $account_id ): array {
		$account_id = trim( $account_id );
		if ( '' === $account_id || 1 !== preg_match( '/^\d+$/', $account_id ) ) {
			return array();
		}

		$cache             = self::cache();
		$cached_account_id = $cache['account']['id'] ?? '';
		$cached_account_id = is_scalar( $cached_account_id ) ? (string) $cached_account_id : '';

		return $account_id === $cached_account_id ? $cache : array();
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$status = get_option( STATUS_KEY, array() );

		return is_array( $status ) ? $status : array();
	}

	/**
	 * Fetch and save the account's media. The old cache is never removed on failure.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function refresh() {
		if ( ! $this->acquire_lock() ) {
			return new \WP_Error(
				'shootcal_instagram_refresh_busy',
				__( 'An Instagram refresh is already running. Try again shortly.', 'shootcal-instagram-feed' )
			);
		}

		try {
			$token = Config::access_token();
			if ( is_wp_error( $token ) ) {
				$this->record_failure( $token );
				return $token;
			}

			$options    = Config::get();
			$account_id = isset( $options['instagram_account_id'] ) ? preg_replace( '/\D+/', '', (string) $options['instagram_account_id'] ) : '';
			$account_id = is_string( $account_id ) ? $account_id : '';
			if ( '' === $account_id ) {
				$error = new \WP_Error(
					'shootcal_instagram_no_account_id',
					__( 'No Instagram business account ID is configured.', 'shootcal-instagram-feed' )
				);
				$this->record_failure( $error );
				return $error;
			}

			$account = $this->client->account( $account_id, $token );
			if ( is_wp_error( $account ) ) {
				$this->record_failure( $account );
				return $account;
			}

			$media = $this->client->media( $account_id, $token, (int) $options['scan_limit'] );
			if ( is_wp_error( $media ) ) {
				$this->record_failure( $media );
				return $media;
			}

			$items = array();
			foreach ( $media as $raw_item ) {
				$item = self::normalize_item( $raw_item );
				if ( null !== $item ) {
					$items[] = $item;
				}
			}

			if ( ! empty( $media ) && empty( $items ) ) {
				$error = new \WP_Error(
					'shootcal_instagram_unusable_media',
					__( 'Instagram returned media, but none of it contained a usable image or video thumbnail. The previous cache was preserved.', 'shootcal-instagram-feed' )
				);
				$this->record_failure( $error );
				return $error;
			}

			$username            = isset( $account['username'] ) && is_string( $account['username'] ) ? sanitize_text_field( $account['username'] ) : '';
			$verified_account_id = $account['id'] ?? $account_id;
			$cache               = array(
				'schema'     => 1,
				'fetched_at' => time(),
				'account'    => array(
					'id'       => is_scalar( $verified_account_id ) ? sanitize_text_field( (string) $verified_account_id ) : '',
					'username' => $username,
				),
				'items'      => $items,
			);

			update_option( CACHE_KEY, $cache, false );
			update_option(
				STATUS_KEY,
				array(
					'last_attempt' => time(),
					'last_success' => time(),
					'last_error'   => '',
					'item_count'   => count( $items ),
					'username'     => $username,
				),
				false
			);

			return $cache;
		} finally {
			delete_option( LOCK_KEY );
		}
	}

	/**
	 * @param array<string, mixed> $raw Raw Graph API media object.
	 * @return array<string, mixed>|null
	 */
	public static function normalize_item( array $raw ): ?array {
		$id            = isset( $raw['id'] ) && is_scalar( $raw['id'] ) ? sanitize_text_field( (string) $raw['id'] ) : '';
		$caption       = isset( $raw['caption'] ) && is_string( $raw['caption'] ) ? sanitize_textarea_field( $raw['caption'] ) : '';
		$media_type    = isset( $raw['media_type'] ) && is_string( $raw['media_type'] ) ? strtoupper( sanitize_key( $raw['media_type'] ) ) : 'IMAGE';
		$product_type  = isset( $raw['media_product_type'] ) && is_string( $raw['media_product_type'] ) ? strtoupper( sanitize_key( $raw['media_product_type'] ) ) : '';
		$permalink     = isset( $raw['permalink'] ) && is_string( $raw['permalink'] ) ? esc_url_raw( $raw['permalink'], array( 'https' ) ) : '';
		$timestamp     = isset( $raw['timestamp'] ) && is_string( $raw['timestamp'] ) ? sanitize_text_field( $raw['timestamp'] ) : '';
		$image_url     = '';
		$is_video      = in_array( $media_type, array( 'VIDEO', 'REELS' ), true ) || 'REELS' === $product_type;

		if ( $is_video && ! empty( $raw['thumbnail_url'] ) && is_string( $raw['thumbnail_url'] ) ) {
			$image_url = $raw['thumbnail_url'];
		} elseif ( ! $is_video && ! empty( $raw['media_url'] ) && is_string( $raw['media_url'] ) ) {
			$image_url = $raw['media_url'];
		}

		if ( '' === $image_url && 'CAROUSEL_ALBUM' === $media_type && ! empty( $raw['children']['data'] ) && is_array( $raw['children']['data'] ) ) {
			$first_child = reset( $raw['children']['data'] );
			if ( is_array( $first_child ) ) {
				$child_media_type = isset( $first_child['media_type'] ) && is_string( $first_child['media_type'] )
					? strtoupper( sanitize_key( $first_child['media_type'] ) )
					: 'IMAGE';
				$child_is_video   = in_array( $child_media_type, array( 'VIDEO', 'REELS' ), true );

				if ( ! empty( $first_child['thumbnail_url'] ) && is_string( $first_child['thumbnail_url'] ) ) {
					$image_url = $first_child['thumbnail_url'];
				} elseif ( ! $child_is_video && ! empty( $first_child['media_url'] ) && is_string( $first_child['media_url'] ) ) {
					$image_url = $first_child['media_url'];
				}
			}
		}

		$image_url = esc_url_raw( $image_url, array( 'https' ) );
		if ( '' === $id || '' === $image_url || '' === $permalink ) {
			return null;
		}

		return array(
			'id'         => $id,
			'caption'    => $caption,
			'hashtags'   => Hashtag_Filter::extract( $caption ),
			'media_type'   => $media_type,
			'product_type' => $product_type,
			'image_url'    => $image_url,
			'permalink'    => $permalink,
			'timestamp'    => $timestamp,
		);
	}

	private function acquire_lock(): bool {
		$existing = (int) get_option( LOCK_KEY, 0 );
		if ( $existing > 0 && ( time() - $existing ) < 10 * MINUTE_IN_SECONDS ) {
			return false;
		}

		if ( $existing > 0 ) {
			delete_option( LOCK_KEY );
		}

		return add_option( LOCK_KEY, time(), '', false );
	}

	private function record_failure( \WP_Error $error ): void {
		$status                 = self::status();
		$status['last_attempt'] = time();
		$status['last_error']   = sanitize_text_field( $error->get_error_message() );
		update_option( STATUS_KEY, $status, false );
	}
}
