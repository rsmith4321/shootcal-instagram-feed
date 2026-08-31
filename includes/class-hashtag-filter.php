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
	 * Normalize a comma- or whitespace-separated hashtag list.
	 *
	 * @return string[]|null Unique normalized tags, or null when any entry is invalid.
	 */
	public static function normalize_list( string $list ): ?array {
		$list = trim( $list );
		if ( '' === $list ) {
			return array();
		}

		$tags = array();
		foreach ( preg_split( '/[,\s]+/u', $list ) ?: array() as $entry ) {
			if ( '' === $entry || '#' === $entry ) {
				continue;
			}
			$tag = self::normalize( $entry );
			if ( '' === $tag ) {
				return null;
			}
			$tags[] = $tag;
		}

		return array_values( array_unique( $tags ) );
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

		return self::filter_list( $items, '' === $hashtag ? array() : array( $hashtag ) );
	}

	/**
	 * Keep items carrying any include tag (all items when the list is empty),
	 * then drop items carrying any exclude tag.
	 *
	 * @param array<int, array<string, mixed>> $items   Cached feed items.
	 * @param string[]                         $include Normalized tags to require.
	 * @param string[]                         $exclude Normalized tags to reject.
	 * @return array<int, array<string, mixed>>
	 */
	public static function filter_list( array $items, array $include, array $exclude = array() ): array {
		if ( array() === $include && array() === $exclude ) {
			return array_values( array_filter( $items, 'is_array' ) );
		}

		return array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $include, $exclude ): bool {
					if ( ! is_array( $item ) ) {
						return false;
					}

					$hashtags = isset( $item['hashtags'] ) && is_array( $item['hashtags'] )
						? $item['hashtags']
						: self::extract( isset( $item['caption'] ) && is_string( $item['caption'] ) ? $item['caption'] : '' );

					if ( array() !== array_intersect( $exclude, $hashtags ) ) {
						return false;
					}

					return array() === $include || array() !== array_intersect( $include, $hashtags );
				}
			)
		);
	}
}
