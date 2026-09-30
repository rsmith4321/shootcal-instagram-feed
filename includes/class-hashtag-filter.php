<?php
/** Exact caption hashtag parsing and filtering. @package ShootCalInstagramFeed */
declare( strict_types=1 );
namespace ShootCalInstagramFeed;
defined( 'ABSPATH' ) || exit;
require_once dirname( __DIR__ ) . '/shared/instagram-feed-core/FeedRules.php';
use ShootCal\Instagram\V1\FeedRules;

class Hashtag_Filter {
    public static function normalize( string $hashtag ): string { return FeedRules::normalize( $hashtag ) ?? ''; }
    public static function extract( string $caption ): array { return FeedRules::extract( $caption ); }
    public static function normalize_list( string $list ): ?array { return FeedRules::normalizeList( $list ); }
    public static function filter( array $items, string $hashtag ): array {
        $tag = FeedRules::normalize( $hashtag );
        return null === $tag ? array() : self::filter_list( $items, '' === $tag ? array() : array( $tag ) );
    }
    public static function filter_list( array $items, array $include, array $exclude = array() ): array {
        return FeedRules::select( $items, $include, $exclude, max( 1, count( $items ) ) );
    }
}
