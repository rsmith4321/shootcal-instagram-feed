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
				'hashtag' => (string) $options['default_hashtag'],
				'limit'   => (int) $options['display_limit'],
				'columns' => (int) $options['columns'],
				'class'   => '',
			),
			is_array( $attributes ) ? $attributes : array(),
			self::TAG
		);

		$raw_hashtag    = trim( (string) $attributes['hashtag'] );
		$hashtag        = Hashtag_Filter::normalize( $raw_hashtag );
		$valid_hashtag  = '' === $raw_hashtag || '' !== $hashtag;
		$limit          = max( 1, min( 30, (int) $attributes['limit'] ) );
		$columns        = max( 1, min( 6, (int) $attributes['columns'] ) );
		$classes        = array( 'shootcal-instagram-feed' );
		foreach ( preg_split( '/\s+/', (string) $attributes['class'] ) ?: array() as $class_name ) {
			$class_name = sanitize_html_class( $class_name );
			if ( '' !== $class_name ) {
				$classes[] = $class_name;
			}
		}

		$cache = Feed_Store::cache_for_account( (string) $options['instagram_account_id'] );
		$items = isset( $cache['items'] ) && is_array( $cache['items'] ) ? $cache['items'] : array();
		$items = $valid_hashtag ? array_slice( Hashtag_Filter::filter( $items, $hashtag ), 0, $limit ) : array();

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

		ob_start();
		?>
		<div
			class="<?php echo esc_attr( implode( ' ', array_unique( $classes ) ) ); ?>"
			style="--scif-columns:<?php echo esc_attr( (string) $columns ); ?>;--scif-columns-tablet:<?php echo esc_attr( (string) $tablet ); ?>;--scif-columns-mobile:<?php echo esc_attr( (string) $mobile ); ?>"
			<?php echo '' !== $hashtag ? 'data-hashtag="' . esc_attr( $hashtag ) . '"' : ''; ?>
		>
			<?php foreach ( $items as $item ) : ?>
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
				?>
				<a class="shootcal-instagram-feed__item" href="<?php echo esc_url( $permalink ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( $label ); ?>">
					<img class="shootcal-instagram-feed__image" src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $alt ); ?>" loading="lazy" decoding="async" referrerpolicy="no-referrer" />
					<?php if ( 'VIDEO' === $media_type || 'REELS' === $product_type ) : ?>
						<span class="shootcal-instagram-feed__type" aria-hidden="true"><?php esc_html_e( 'Video', 'shootcal-instagram-feed' ); ?></span>
					<?php elseif ( 'CAROUSEL_ALBUM' === $media_type ) : ?>
						<span class="shootcal-instagram-feed__type" aria-hidden="true"><?php esc_html_e( 'Carousel', 'shootcal-instagram-feed' ); ?></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}
