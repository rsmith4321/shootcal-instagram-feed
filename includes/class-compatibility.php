<?php
/**
 * Self-registers with CSS/lazy-load optimizer plugins so they leave the feed alone.
 *
 * Unused-CSS removers strip feed.css because the dynamic feed's markup is
 * injected after their crawl, and page-level lazy loaders must not rewrite
 * images whose load timing this plugin already manages.
 *
 * @package ShootCalInstagramFeed
 */

declare( strict_types=1 );

namespace ShootCalInstagramFeed;

defined( 'ABSPATH' ) || exit;

class Compatibility {

	private const STYLESHEET_PATH = '/shootcal-instagram-feed/';

	private const SELECTOR_PREFIXES = array(
		'.shootcal-instagram-feed',
		'.shootcal-instagram-feed-shell',
		'.shootcal-instagram-feed-loader',
	);

	public function register(): void {
		// Perfmatters: Remove Unused CSS (filter names verified against its source).
		add_filter( 'perfmatters_rucss_excluded_stylesheets', array( self::class, 'exclude_stylesheet' ) );
		add_filter( 'perfmatters_rucss_excluded_selectors', array( self::class, 'exclude_selectors' ) );

		// WP Rocket: Remove Unused CSS selector safelist.
		add_filter( 'rocket_rucss_safelist', array( self::class, 'exclude_selectors' ) );
	}

	/**
	 * @param mixed $stylesheets Excluded stylesheet URL fragments.
	 * @return mixed
	 */
	public static function exclude_stylesheet( $stylesheets ) {
		if ( ! is_array( $stylesheets ) ) {
			return $stylesheets;
		}
		if ( ! in_array( self::STYLESHEET_PATH, $stylesheets, true ) ) {
			$stylesheets[] = self::STYLESHEET_PATH;
		}

		return $stylesheets;
	}

	/**
	 * @param mixed $selectors Safelisted CSS selectors.
	 * @return mixed
	 */
	public static function exclude_selectors( $selectors ) {
		if ( ! is_array( $selectors ) ) {
			return $selectors;
		}
		foreach ( self::SELECTOR_PREFIXES as $selector ) {
			if ( ! in_array( $selector, $selectors, true ) ) {
				$selectors[] = $selector;
			}
		}

		return $selectors;
	}
}
