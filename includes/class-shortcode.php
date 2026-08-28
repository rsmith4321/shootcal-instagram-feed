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
		$attributes = shortcode_atts(
			array(
				'hashtag'      => (string) $options['default_hashtag'],
				'limit'        => (int) $options['display_limit'],
				'columns'      => (int) $options['columns'],
				'mobile_limit' => 0,
				'follow'       => 'false',
				'dynamic'      => 'false',
				'class'        => '',
			),
			is_array( $attributes ) ? $attributes : array(),
			self::TAG
		);

		$raw_hashtag    = trim( (string) $attributes['hashtag'] );
		$hashtag        = Hashtag_Filter::normalize( $raw_hashtag );
		$valid_hashtag  = '' === $raw_hashtag || '' !== $hashtag;
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
		$items = $valid_hashtag ? array_slice( Hashtag_Filter::filter( $items, $hashtag ), 0, $limit ) : array();

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

			return sprintf(
				'<div class="shootcal-instagram-feed-loader" data-endpoint="%1$s" data-stylesheet="%2$s" data-hashtag="%3$s" data-limit="%4$d" data-columns="%5$d" data-mobile-limit="%6$d" data-follow="%7$s" data-class="%8$s">%9$s</div>',
				esc_url( rest_url( Rest_Controller::ROUTE ) ),
				esc_url( Assets::stylesheet_url() ),
				esc_attr( $hashtag ),
				$limit,
				$columns,
				$mobile_limit,
				$show_follow ? 'true' : 'false',
				esc_attr( implode( ' ', array_unique( $custom_classes ) ) ),
				$fallback
			);
		}

		if ( empty( $items ) ) {
			if ( empty( $cache ) && ! current_user_can( 'manage_options' ) ) {
				return '';
			}

			Assets::enqueue();
			$message = ! $valid_hashtag
				? __( 'The Instagram hashtag must contain only letters, numbers, or underscores.', 'shootcal-instagram-feed' )
				: ( '' !== $hashtag
					? sprintf(
						/* translators: %s: requested hashtag. */
						__( 'No cached Instagram posts matched #%s.', 'shootcal-instagram-feed' ),
						$hashtag
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
						<span class="shootcal-instagram-feed__type" aria-hidden="true"><?php esc_html_e( 'Carousel', 'shootcal-instagram-feed' ); ?></span>
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
