<?php
/**
 * Cached feed shortcode renderer.
 *
 * @package ShootCalInstagramFeed
 */

declare( strict_types=1 );

namespace ShootCalInstagramFeed;

defined( 'ABSPATH' ) || exit;

class Shortcode {

	public const TAG = 'shootcal_instagram_feed';

	private bool $rendering_fallback = false;

	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * @param array<string, mixed>|string $attributes Shortcode attributes.
	 */
	public function render( $attributes = array() ): string {
		$options    = Config::get();
		$raw        = is_array( $attributes ) ? $attributes : array();
		$author_raw = $raw;
		$feed_id    = isset( $raw['feed'] ) ? absint( $raw['feed'] ) : 0;
		if ( $feed_id > 0 ) {
			$preset = Feeds::get( $feed_id );
			if ( null === $preset ) {
				if ( ! current_user_can( 'manage_options' ) ) {
					return '';
				}
				Assets::enqueue();
				/* translators: %d: requested feed id. */
				return '<p class="shootcal-instagram-feed__empty">' . esc_html( sprintf( __( 'ShootCal Social Feed %d does not exist. Recreate it or update this shortcode.', 'shootcal-instagram-feed' ), $feed_id ) ) . '</p>';
			}
			// Explicit shortcode attributes still override the saved preset.
			$raw = array_merge( Feeds::to_shortcode_attributes( $preset ), $raw );
		}
		$attributes = shortcode_atts(
			array(
				'feed'         => 0,
				'hashtag'      => (string) $options['default_hashtag'],
				'exclude'      => '',
				'limit'        => (int) $options['display_limit'],
				'columns'      => (int) $options['columns'],
				'mobile_limit' => 0,
				'follow'       => 'false',
				'dynamic'      => 'false',
				'class'        => '',
			),
			$raw,
			self::TAG
		);

		$include_tags   = Hashtag_Filter::normalize_list( (string) $attributes['hashtag'] );
		$exclude_tags   = Hashtag_Filter::normalize_list( (string) $attributes['exclude'] );
		$valid_hashtag  = null !== $include_tags && null !== $exclude_tags;
		$include_tags   = $include_tags ?? array();
		$exclude_tags   = $exclude_tags ?? array();
		$hashtag        = implode( ',', $include_tags );
		$exclude        = implode( ',', $exclude_tags );
		$limit          = max( 1, min( 30, (int) $attributes['limit'] ) );
		$columns        = max( 1, min( 6, (int) $attributes['columns'] ) );
		$mobile_limit   = max( 0, min( $limit, (int) $attributes['mobile_limit'] ) );
		$show_follow    = self::attribute_is_true( $attributes['follow'] );
		$dynamic        = ! $this->rendering_fallback && self::attribute_is_true( $attributes['dynamic'] );
		$custom_classes = array();
		$classes        = array( 'shootcal-instagram-feed' );
		foreach ( preg_split( '/\s+/', (string) $attributes['class'] ) ?: array() as $class_name ) {
			$class_name = sanitize_html_class( $class_name );
			if ( '' !== $class_name ) {
				$custom_classes[] = $class_name;
				$classes[] = $class_name;
			}
		}

		$cache = Feed_Store::cache_for_account( (string) $options['instagram_account_id'] );
		$items = isset( $cache['items'] ) && is_array( $cache['items'] ) ? $cache['items'] : array();
		$items = $valid_hashtag ? array_slice( Hashtag_Filter::filter_list( $items, $include_tags, $exclude_tags ), 0, $limit ) : array();

		if ( $dynamic && $valid_hashtag ) {
			Assets::enqueue_dynamic();

			$fallback_attributes            = $attributes;
			$fallback_attributes['dynamic'] = 'false';
			$this->rendering_fallback        = true;
			try {
				$fallback = $this->render( $fallback_attributes );
			} finally {
				$this->rendering_fallback = false;
			}

			if ( $feed_id > 0 ) {
				// A preset feed resolves live at request time, so a cached page
				// picks up later admin edits. Only author-typed overrides ride along.
				$loader_data = array( 'feed' => (string) $feed_id );
				foreach ( array( 'hashtag', 'exclude', 'limit', 'columns', 'mobile-limit' => 'mobile_limit', 'follow' ) as $data_key => $attribute_key ) {
					$data_key = is_string( $data_key ) ? $data_key : $attribute_key;
					if ( isset( $author_raw[ $attribute_key ] ) && is_scalar( $author_raw[ $attribute_key ] ) ) {
						$loader_data[ $data_key ] = (string) $author_raw[ $attribute_key ];
					}
				}
			} else {
				$loader_data = array(
					'hashtag'      => $hashtag,
					'exclude'      => $exclude,
					'limit'        => (string) $limit,
					'columns'      => (string) $columns,
					'mobile-limit' => (string) $mobile_limit,
					'follow'       => $show_follow ? 'true' : 'false',
				);
			}
			$loader_data['endpoint']   = rest_url( Rest_Controller::ROUTE );
			$loader_data['stylesheet'] = Assets::stylesheet_url();
			$loader_data['class']      = implode( ' ', array_unique( $custom_classes ) );

			$loader_html = '<div class="shootcal-instagram-feed-loader"';
			foreach ( $loader_data as $data_key => $data_value ) {
				if ( '' === $data_value ) {
					continue;
				}
				$data_value   = in_array( $data_key, array( 'endpoint', 'stylesheet' ), true ) ? esc_url( $data_value ) : esc_attr( $data_value );
				$loader_html .= ' data-' . $data_key . '="' . $data_value . '"';
			}

			return $loader_html . '>' . $fallback . '</div>';
		}

		if ( empty( $items ) ) {
			// Visitors never see plumbing text; only administrators get guidance.
			if ( ! current_user_can( 'manage_options' ) ) {
				return '';
			}

			Assets::enqueue();
			$message = ! $valid_hashtag
				? __( 'Instagram hashtags may contain only letters, numbers, or underscores, separated by commas.', 'shootcal-instagram-feed' )
				: ( '' !== $hashtag
					? sprintf(
						/* translators: %s: requested hashtags. */
						__( 'No cached Instagram posts matched %s.', 'shootcal-instagram-feed' ),
						'#' . str_replace( ',', ' #', $hashtag )
					)
					: __( 'No Instagram posts are cached yet.', 'shootcal-instagram-feed' ) );

			return '<p class="shootcal-instagram-feed__empty">' . esc_html( $message ) . '</p>';
		}

		Assets::enqueue();
		$tablet  = min( $columns, 3 );
		$mobile  = min( $columns, 2 );
		$account = isset( $cache['account']['username'] ) && is_string( $cache['account']['username'] ) ? $cache['account']['username'] : '';
		$profile = '' !== $account && 1 === preg_match( '/^[A-Za-z0-9._]+$/', $account )
			? 'https://www.instagram.com/' . $account . '/'
			: '';

		ob_start();
		?>
		<div class="shootcal-instagram-feed-shell">
			<div
				class="<?php echo esc_attr( implode( ' ', array_unique( $classes ) ) ); ?>"
				style="--scif-columns:<?php echo esc_attr( (string) $columns ); ?>;--scif-columns-tablet:<?php echo esc_attr( (string) $tablet ); ?>;--scif-columns-mobile:<?php echo esc_attr( (string) $mobile ); ?>"
				<?php echo '' !== $hashtag ? 'data-hashtag="' . esc_attr( $hashtag ) . '"' : ''; ?>
			>
			<?php foreach ( $items as $item_index => $item ) : ?>
				<?php
				if ( ! is_array( $item ) ) {
					continue;
				}
				$caption    = isset( $item['caption'] ) && is_string( $item['caption'] ) ? $item['caption'] : '';
				$media_type   = isset( $item['media_type'] ) && is_string( $item['media_type'] ) ? $item['media_type'] : 'IMAGE';
				$product_type = isset( $item['product_type'] ) && is_string( $item['product_type'] ) ? $item['product_type'] : '';
				$image_url  = isset( $item['image_url'] ) && is_string( $item['image_url'] ) ? $item['image_url'] : '';
				$permalink  = isset( $item['permalink'] ) && is_string( $item['permalink'] ) ? $item['permalink'] : '';
				$timestamp  = isset( $item['timestamp'] ) && is_string( $item['timestamp'] ) ? strtotime( $item['timestamp'] ) : false;
				$alt        = trim( wp_strip_all_tags( $caption ) );
				$alt        = '' !== $alt ? wp_trim_words( $alt, 18, '&hellip;' ) : __( 'Instagram post', 'shootcal-instagram-feed' );
				$label      = $timestamp
					? sprintf(
						/* translators: 1: account username. 2: post date. */
						__( 'View Instagram post by %1$s from %2$s', 'shootcal-instagram-feed' ),
						'' !== $account ? '@' . $account : __( 'this account', 'shootcal-instagram-feed' ),
						wp_date( get_option( 'date_format' ), $timestamp )
					)
					: __( 'View this post on Instagram', 'shootcal-instagram-feed' );
				$item_classes = array( 'shootcal-instagram-feed__item' );
				if ( 0 < $mobile_limit && (int) $item_index >= $mobile_limit ) {
					$item_classes[] = 'shootcal-instagram-feed__item--mobile-hidden';
				}
				?>
				<a class="<?php echo esc_attr( implode( ' ', $item_classes ) ); ?>" href="<?php echo esc_url( $permalink ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( $label ); ?>">
					<img class="shootcal-instagram-feed__image" src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $alt ); ?>" loading="lazy" decoding="async" referrerpolicy="no-referrer" />
					<?php if ( 'VIDEO' === $media_type || 'REELS' === $product_type ) : ?>
						<span class="shootcal-instagram-feed__type" aria-hidden="true"><?php esc_html_e( 'Video', 'shootcal-instagram-feed' ); ?></span>
					<?php elseif ( 'CAROUSEL_ALBUM' === $media_type ) : ?>
						<span class="shootcal-instagram-feed__type shootcal-instagram-feed__type--carousel" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="8.5" y="8.5" width="11" height="11" rx="2.5" stroke="currentColor" stroke-width="2"/><path d="M15.5 4.5H7A2.5 2.5 0 0 0 4.5 7v8.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
			</div>
			<?php if ( $show_follow && '' !== $profile ) : ?>
				<p class="shootcal-instagram-feed__follow">
					<a class="shootcal-instagram-feed__follow-link" href="<?php echo esc_url( $profile ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Follow on Instagram', 'shootcal-instagram-feed' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Interpret an opt-in shortcode boolean.
	 *
	 * @param mixed $value Shortcode attribute value.
	 */
	private static function attribute_is_true( $value ): bool {
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}
}
