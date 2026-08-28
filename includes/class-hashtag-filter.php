<?php
/**
 * Exact caption hashtag parsing and filtering.
 *
 * @package ShootCalInstagramFeed
 */

declare( strict_types=1 );

namespace ShootCalInstagramFeed;

defined( 'ABSPATH' ) || exit;

class Hashtag_Filter {

	/**
	 * Normalize one hashtag without its leading hash.
	 */
	public static function normalize( string $hashtag ): string {
		$hashtag = trim( $hashtag );
		if ( str_starts_with( $hashtag, '#' ) ) {
			$hashtag = substr( $hashtag, 1 );
		}

		if ( '' === $hashtag || 1 !== preg_match( '/^[\p{L}\p{N}\p{M}_]+$/u', $hashtag ) ) {
			return '';
		}

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $hashtag, 'UTF-8' ) : strtolower( $hashtag );
	}

	/**
	 * Extract unique, normalized hashtags from a caption.
	 *
	 * @return string[]
	 */
	public static function extract( string $caption ): array {
		$matches = array();
		$found   = preg_match_all(
			'/(?<![\p{L}\p{N}\p{M}_])#([\p{L}\p{N}\p{M}_]+)/u',
			$caption,
			$matches
		);

		if ( false === $found || 0 === $found || empty( $matches[1] ) ) {
			return array();
		}

		$hashtags = array_map( array( self::class, 'normalize' ), $matches[1] );
		$hashtags = array_filter( $hashtags, static fn( string $tag ): bool => '' !== $tag );

		return array_values( array_unique( $hashtags ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $items Cached feed items.
	 * @return array<int, array<string, mixed>>
	 */
	public static function filter( array $items, string $hashtag ): array {
		$raw_hashtag = trim( $hashtag );
		$hashtag     = self::normalize( $raw_hashtag );
		if ( '' !== $raw_hashtag && '' === $hashtag ) {
			return array();
		}

		if ( '' === $hashtag ) {
			return array_values( $items );
		}

		return array_values(
			array_filter(
				$items,
					static function ( $item ) use ( $hashtag ): bool {
						if ( ! is_array( $item ) ) {
							return false;
						}

					$hashtags = isset( $item['hashtags'] ) && is_array( $item['hashtags'] )
						? $item['hashtags']
						: self::extract( isset( $item['caption'] ) && is_string( $item['caption'] ) ? $item['caption'] : '' );

					return in_array( $hashtag, $hashtags, true );
				}
			)
		);
	}
}
