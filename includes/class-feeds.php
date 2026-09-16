<?php
/**
 * Saved feed presets: named hashtag filters that render via [shootcal_instagram_feed feed="N"].
 *
 * @package ShootCalInstagramFeed
 */

declare( strict_types=1 );

namespace ShootCalInstagramFeed;

defined( 'ABSPATH' ) || exit;

class Feeds {

	private const MAX_FEEDS = 50;

	/**
	 * @return array<int, array<string, mixed>> Feed presets keyed by id.
	 */
	public static function all(): array {
		$stored = get_option( FEEDS_KEY, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$feeds = array();
		foreach ( $stored as $id => $feed ) {
			$id = absint( $id );
			if ( $id < 1 || ! is_array( $feed ) ) {
				continue;
			}
			$normalized = self::sanitize( $feed );
			if ( null !== $normalized ) {
				$feeds[ $id ] = $normalized;
			}
		}

		return $feeds;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		$feeds = self::all();

		return $feeds[ $id ] ?? null;
	}

	/**
	 * Validate and store one feed preset. A zero id creates a new feed.
	 *
	 * @param array<string, mixed> $feed Raw submitted feed fields.
	 * @return int|\WP_Error The saved feed id.
	 */
	public static function save( int $id, array $feed ) {
		$normalized = self::sanitize( $feed );
		if ( null === $normalized ) {
			return new \WP_Error(
				'shootcal_instagram_feed_invalid',
				__( 'The feed needs a name, and hashtags may contain only letters, numbers, or underscores, separated by commas.', 'shootcal-social-feed' )
			);
		}

		$feeds = self::all();
		if ( 0 === $id ) {
			if ( count( $feeds ) >= self::MAX_FEEDS ) {
				return new \WP_Error(
					'shootcal_instagram_feed_limit',
					__( 'Delete an unused feed before creating another.', 'shootcal-social-feed' )
				);
			}
			$id = empty( $feeds ) ? 1 : max( array_keys( $feeds ) ) + 1;
		} elseif ( ! isset( $feeds[ $id ] ) ) {
			return new \WP_Error(
				'shootcal_instagram_feed_missing',
				__( 'That feed no longer exists.', 'shootcal-social-feed' )
			);
		}

		$feeds[ $id ] = $normalized;
		update_option( FEEDS_KEY, $feeds, false );

		$stored = get_option( FEEDS_KEY, array() );
		if ( ! is_array( $stored ) || ! isset( $stored[ $id ] ) || $stored[ $id ] !== $normalized ) {
			return new \WP_Error(
				'shootcal_instagram_feed_store_failed',
				__( 'WordPress could not save the feed.', 'shootcal-social-feed' )
			);
		}

		return $id;
	}

	public static function delete( int $id ): void {
		$feeds = self::all();
		if ( isset( $feeds[ $id ] ) ) {
			unset( $feeds[ $id ] );
			update_option( FEEDS_KEY, $feeds, false );
		}
	}

	/**
	 * Shortcode attributes equivalent to a stored preset.
	 *
	 * @param array<string, mixed> $feed Normalized feed.
	 * @return array<string, string|int>
	 */
	public static function to_shortcode_attributes( array $feed ): array {
		return array(
			'hashtag'      => (string) $feed['hashtag'],
			'exclude'      => (string) $feed['exclude'],
			'limit'        => (int) $feed['limit'],
			'columns'      => (int) $feed['columns'],
			'mobile_limit' => (int) $feed['mobile_limit'],
			'follow'       => ! empty( $feed['follow'] ) ? 'true' : 'false',
			'dynamic'      => ! empty( $feed['dynamic'] ) ? 'true' : 'false',
		);
	}

	/**
	 * @param array<string, mixed> $feed Raw feed fields.
	 * @return array<string, mixed>|null Normalized feed, or null when invalid.
	 */
	private static function sanitize( array $feed ): ?array {
		$name = isset( $feed['name'] ) && is_scalar( $feed['name'] ) ? sanitize_text_field( (string) $feed['name'] ) : '';
		if ( '' === $name || strlen( $name ) > 80 ) {
			return null;
		}

		$include = Hashtag_Filter::normalize_list( isset( $feed['hashtag'] ) && is_scalar( $feed['hashtag'] ) ? (string) $feed['hashtag'] : '' );
		$exclude = Hashtag_Filter::normalize_list( isset( $feed['exclude'] ) && is_scalar( $feed['exclude'] ) ? (string) $feed['exclude'] : '' );
		if ( null === $include || null === $exclude ) {
			return null;
		}

		$limit = isset( $feed['limit'] ) ? (int) $feed['limit'] : 9;

		return array(
			'name'         => $name,
			'hashtag'      => implode( ', ', $include ),
			'exclude'      => implode( ', ', $exclude ),
			'limit'        => max( 1, min( 30, $limit ) ),
			'columns'      => max( 1, min( 6, isset( $feed['columns'] ) ? (int) $feed['columns'] : 3 ) ),
			'mobile_limit' => max( 0, min( 30, isset( $feed['mobile_limit'] ) ? (int) $feed['mobile_limit'] : 0 ) ),
			'follow'       => ! empty( $feed['follow'] ),
			'dynamic'      => ! empty( $feed['dynamic'] ),
		);
	}
}
