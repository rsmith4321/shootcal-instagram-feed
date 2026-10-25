<?php
/** Background-only, disposable responsive feed thumbnails. Original media is untouched. */
declare( strict_types=1 );
namespace ShootCalInstagramFeed;
defined( 'ABSPATH' ) || exit;

class Image_Cache {
	public const HOOK = 'shootcal_instagram_feed_images';
	public const OPTION = 'shootcal_instagram_feed_images';
	private const LOCK = 'shootcal_instagram_feed_images_lock';
	private const LIMIT = 8 * 1024 * 1024;
	private static ?array $manifest = null;

	public function register(): void {
		add_action( 'init', array( self::class, 'schedule' ) );
		add_action( 'update_option_' . CACHE_KEY, array( self::class, 'schedule' ) );
		add_action( 'delete_option_' . CACHE_KEY, array( self::class, 'schedule' ) );
		add_action( 'update_option_' . OPTION_KEY, array( self::class, 'schedule' ) );
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/** Scheduling is cheap; no visitor request downloads or resizes an image. */
	public static function schedule(): void {
		$options = Config::get();
		$cache = Feed_Store::cache_for_account( (string) ( $options['instagram_account_id'] ?? '' ) );
		$manifest = self::manifest();
		if ( empty( $cache['items'] ) && empty( $manifest['items'] ) && empty( $manifest['retired'] ) ) { return; }
		if ( ! empty( $cache['items'] ) && ( $manifest['checked_feed'] ?? null ) === self::feed_signature( $cache ) ) { return; }
		if ( ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_single_event( time() + 30, self::HOOK ); }
	}

	private static function feed_signature( array $cache ): string {
		return hash( 'sha256', (string) ( $cache['account']['id'] ?? '' ) . ':' . (string) ( $cache['fetched_at'] ?? 0 ) );
	}

	private static function manifest(): array {
		if ( null === self::$manifest ) {
			$value = get_option( self::OPTION, array() );
			self::$manifest = is_array( $value ) ? $value : array();
		}
		return self::$manifest;
	}

	private static function key( string $account, array $item ): string {
		return hash( 'sha256', $account . ':' . (string) ( $item['id'] ?? '' ) );
	}

	/** Return completed owned variants only. Missing images wait for the background job. */
	public static function attributes( string $account, array $item, int $columns ): array {
		$key = self::key( $account, $item );
		$entry = self::manifest()['items'][ $key ] ?? array();
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || ! is_array( $entry ) || ! is_array( $entry['variants'] ?? null ) || ! $entry['variants'] ) { return array(); }
		$variants = array();
		foreach ( $entry['variants'] as $variant ) {
			$name = $variant['file'] ?? '';
			if ( ! is_string( $name ) || ! preg_match( '/^' . preg_quote( $key, '/' ) . '-(?:320|640|1280)\.(?:jpg|jpeg|webp|avif)$/D', $name )
				|| ! is_int( $variant['width'] ?? null ) || $variant['width'] <= 0
				|| ! is_int( $variant['height'] ?? null ) || $variant['height'] <= 0
				|| ! is_file( $uploads['basedir'] . '/shootcal-social-feed/' . $name ) ) { return array(); }
			$variant['url'] = $uploads['baseurl'] . '/shootcal-social-feed/' . $name;
			$variants[] = $variant;
		}
		if ( ! $variants ) { return array(); }
		$default = $variants[ min( 1, count( $variants ) - 1 ) ];
		$columns = max( 1, min( 6, $columns ) );
		return array(
			'src' => $default['url'], 'width' => $default['width'], 'height' => $default['height'],
			'srcset' => implode( ', ', array_map( static fn( array $v ): string => $v['url'] . ' ' . $v['width'] . 'w', $variants ) ),
			'sizes' => 'auto, (max-width: 640px) ' . ceil( 100 / min( 2, $columns ) ) . 'vw, (max-width: 1024px) ' . ceil( 100 / min( 3, $columns ) ) . 'vw, ' . ceil( 100 / $columns ) . 'vw',
		);
	}

	/** A bounded cron batch. Unavailable images retry on the next feed refresh. */
	public function run(): void {
		$lock = (int) get_option( self::LOCK, 0 );
		if ( $lock && time() - $lock < 5 * MINUTE_IN_SECONDS ) { return; }
		if ( $lock ) { delete_option( self::LOCK ); }
		if ( ! add_option( self::LOCK, time(), '', false ) ) { return; }
		try {
			$options = Config::get();
			$account = (string) ( $options['instagram_account_id'] ?? '' );
			$cache = Feed_Store::cache_for_account( $account );
			if ( empty( $cache['items'] ) ) {
				// Disconnect and empty feeds retire references without touching source
				// media. Finish the existing grace period even with no future refresh.
				$manifest = self::manifest();
				$manifest['items'] = array();
				unset( $manifest['checked_feed'] );
				update_option( self::OPTION, $manifest, false ); self::$manifest = $manifest;
				if ( $this->prune( array() ) && ! wp_next_scheduled( self::HOOK ) ) {
					wp_schedule_single_event( time() + DAY_IN_SECONDS, self::HOOK );
				}
				return;
			}
			$signature = self::feed_signature( $cache );
			$manifest = self::manifest();
			$manifest['items'] = is_array( $manifest['items'] ?? null ) ? $manifest['items'] : array();
			$remaining = false; $processed = 0; $active = array();
			foreach ( $cache['items'] as $item ) {
				$key = self::key( $account, $item ); $active[ $key ] = true;
				$entry = $manifest['items'][ $key ] ?? array();
				if ( self::attributes( $account, $item, 1 ) || ( $entry['attempted_feed'] ?? '' ) === $signature ) { continue; }
				if ( $processed >= 4 ) { $remaining = true; continue; }
				++$processed;
				$variants = $this->build( $key, (string) ( $item['image_url'] ?? '' ) );
				$manifest['items'][ $key ] = array( 'variants' => $variants, 'attempted_feed' => $signature );
			}
			// Keep only active account/media entries; source media and attachments are never changed.
			$manifest['items'] = array_intersect_key( $manifest['items'], $active );
			if ( ! $remaining ) { $manifest['checked_feed'] = $signature; }
			update_option( self::OPTION, $manifest, false ); self::$manifest = $manifest;
			if ( $remaining && ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_single_event( time() + 30, self::HOOK ); }
			$this->prune( $manifest['items'] );
		} finally { delete_option( self::LOCK ); }
	}

	/** Only public Instagram/Facebook image CDN hosts are fetched, never arbitrary URLs. */
	public static function allowed_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && 'https' === ( $parts['scheme'] ?? '' )
			&& empty( $parts['user'] ) && empty( $parts['pass'] ) && empty( $parts['port'] )
			&& (bool) preg_match( '/(?:^|\.)(?:cdninstagram\.com|fbcdn\.net)$/iD', (string) ( $parts['host'] ?? '' ) );
	}

	private function build( string $key, string $url ): array {
		if ( ! self::allowed_url( $url ) ) { return array(); }
		$uploads = wp_upload_dir();
		$directory = $uploads['basedir'] . '/shootcal-social-feed';
		if ( ! empty( $uploads['error'] ) || ! wp_mkdir_p( $directory ) ) { return array(); }
		$temp = wp_tempnam( 'shootcal-feed-image' );
		if ( ! $temp ) { return array(); }
		try {
			for ( $redirect = 0; $redirect < 4; ++$redirect ) {
				if ( ! self::allowed_url( $url ) ) { return array(); }
				$response = wp_safe_remote_get( $url, array( 'timeout' => 5, 'redirection' => 0, 'stream' => true, 'filename' => $temp, 'limit_response_size' => self::LIMIT ) );
				if ( is_wp_error( $response ) ) { return array(); }
				$status = wp_remote_retrieve_response_code( $response );
				if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
					$url = (string) wp_remote_retrieve_header( $response, 'location' ); continue;
				}
				if ( 200 !== $status || ! filesize( $temp ) || filesize( $temp ) >= self::LIMIT ) { return array(); }
				$size = wp_getimagesize( $temp );
				if ( ! $size || $size[0] * $size[1] > 40000000 || ! in_array( $size['mime'] ?? '', array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) { return array(); }
				$variants = array(); $widths = array();
				foreach ( array( 320, 640, 1280 ) as $edge ) {
					$editor = wp_get_image_editor( $temp );
					if ( is_wp_error( $editor ) ) { return array(); }
					$editor->set_quality( 82 );
					if ( ( $size[0] > $edge || $size[1] > $edge ) && is_wp_error( $editor->resize( $edge, $edge, false ) ) ) { return array(); }
					$dimensions = $editor->get_size();
					if ( isset( $widths[ $dimensions['width'] ] ) ) { continue; }
					$name = $key . '-' . $edge . '.jpg';
					$saved = $editor->save( $directory . '/' . $name, 'image/jpeg' );
					if ( is_wp_error( $saved ) ) { return array(); }
					$variants[] = array( 'file' => basename( $saved['path'] ), 'width' => $saved['width'], 'height' => $saved['height'] );
					$widths[ $saved['width'] ] = true;
				}
				return $variants;
			}
			return array();
		} finally { wp_delete_file( $temp ); }
	}

	/** Retire only our unreferenced derivatives, 14 days after first observed retirement. */
	private function prune( array $items ): bool {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) { return false; }
		$manifest = self::manifest();
		$retired = is_array( $manifest['retired'] ?? null ) ? $manifest['retired'] : array();
		$pending = false; $keep = array(); $present = array(); $now = time();
		foreach ( $items as $entry ) { foreach ( $entry['variants'] ?? array() as $v ) { $keep[ $v['file'] ] = true; } }
		foreach ( glob( $uploads['basedir'] . '/shootcal-social-feed/*' ) ?: array() as $path ) {
			$name = basename( $path );
			if ( ! is_file( $path ) || ! preg_match( '/^[a-f0-9]{64}-(320|640|1280)\.(?:jpg|jpeg|webp|avif)$/D', $name ) ) { continue; }
			$present[ $name ] = true;
			if ( isset( $keep[ $name ] ) ) { unset( $retired[ $name ] ); continue; }
			// File age says nothing about when a cached page stopped referencing it.
			if ( ! is_int( $retired[ $name ] ?? null ) || $retired[ $name ] <= 0 || $retired[ $name ] > $now ) { $retired[ $name ] = $now; }
			if ( $retired[ $name ] <= $now - 14 * DAY_IN_SECONDS ) { wp_delete_file( $path ); }
			if ( is_file( $path ) ) { $pending = true; }
			else { unset( $retired[ $name ] ); }
		}
		// State is bounded by actual owned files, never arbitrary/stale option keys.
		$retired = array_intersect_key( $retired, $present );
		$next = $manifest;
		if ( $retired ) { $next['retired'] = $retired; }
		else { unset( $next['retired'] ); }
		if ( $next !== $manifest ) { update_option( self::OPTION, $next, false ); self::$manifest = $next; }
		return $pending;
	}
}
