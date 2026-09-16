<?php
/**
 * Plugin settings and encrypted token storage.
 *
 * @package ShootCalInstagramFeed
 */

declare( strict_types=1 );

namespace ShootCalInstagramFeed;

defined( 'ABSPATH' ) || exit;

class Config {

	/**
	 * True only while the plugin is persisting an already validated internal
	 * update. This lets the Settings sanitizer distinguish trusted OAuth and
	 * disconnect writes from browser-submitted settings.
	 */
	private static bool $internal_write = false;

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'access_token'       => '',
			'instagram_account_id' => '',
			'token_updated_at'   => 0,
			'default_hashtag'    => '',
			'display_limit'      => 9,
			'columns'            => 3,
			'scan_limit'         => 60,
		);
	}

	/**
	 * Return normalized settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$value = get_option( OPTION_KEY, array() );
		$value = is_array( $value ) ? $value : array();

		return wp_parse_args( $value, self::defaults() );
	}

	public static function ensure_defaults(): void {
		if ( false === get_option( OPTION_KEY, false ) ) {
			add_option( OPTION_KEY, self::defaults(), '', false );
		}
	}

	public static function is_internal_write(): bool {
		return self::$internal_write;
	}

	public static function has_token(): bool {
		$options = self::get();

		return is_string( $options['access_token'] ) && '' !== $options['access_token'];
	}

	/**
	 * Decrypt the stored access token.
	 *
	 * @return string|\WP_Error
	 */
	public static function access_token() {
		$options = self::get();

		if ( ! is_string( $options['access_token'] ) || '' === $options['access_token'] ) {
			return new \WP_Error(
				'shootcal_instagram_no_token',
				__( 'No Instagram access token is configured.', 'shootcal-social-feed' )
			);
		}

		return Token_Cipher::decrypt( $options['access_token'] );
	}

	/**
	 * Encrypt and store a new token.
	 *
	 * @param string $token      Plain access token.
	 * @return true|\WP_Error
	 */
	public static function store_token( string $token ) {
		$token = preg_replace( '/\s+/', '', trim( $token ) );
		$token = is_string( $token ) ? $token : '';

		if ( '' === $token || strlen( $token ) > 4096 || 1 === preg_match( '/[\x00-\x20\x7F]/', $token ) ) {
			return new \WP_Error(
				'shootcal_instagram_bad_token',
				__( 'The access token was empty or invalid.', 'shootcal-social-feed' )
			);
		}

		$encrypted = Token_Cipher::encrypt( $token );
		if ( function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $token );
		}
		if ( is_wp_error( $encrypted ) ) {
			return $encrypted;
		}

		$options                     = self::get();
		$options['access_token']     = $encrypted;
		$options['token_updated_at'] = time();
		return self::persist( $options );
	}

	/**
	 * Atomically replace the locally encrypted token and its account boundary.
	 *
	 * @return true|\WP_Error
	 */
	public static function store_connection( string $token, string $account_id ) {
		if ( 1 !== preg_match( '/^[0-9]{1,191}$/', $account_id ) ) {
			return new \WP_Error(
				'shootcal_instagram_bad_account',
				__( 'ShootCal returned an invalid Instagram account.', 'shootcal-social-feed' )
			);
		}
		$token = preg_replace( '/\s+/', '', trim( $token ) );
		$token = is_string( $token ) ? $token : '';
		if ( '' === $token || strlen( $token ) > 4096 || 1 === preg_match( '/[\x00-\x20\x7F]/', $token ) ) {
			return new \WP_Error(
				'shootcal_instagram_bad_token',
				__( 'ShootCal returned an invalid Instagram access token.', 'shootcal-social-feed' )
			);
		}
		$encrypted = Token_Cipher::encrypt( $token );
		if ( function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $token );
		}
		if ( is_wp_error( $encrypted ) ) {
			return $encrypted;
		}
		$options                         = self::get();
		$options['access_token']         = $encrypted;
		$options['instagram_account_id'] = $account_id;
		$options['token_updated_at']     = time();
		return self::persist( $options );
	}

	public static function clear_token(): void {
		$options                         = self::get();
		$options['access_token']         = '';
		$options['instagram_account_id'] = '';
		$options['token_updated_at']     = 0;
		self::persist( $options );
	}

	/**
	 * Persist a trusted, validated options document and verify the exact value
	 * WordPress stored. update_option() may legitimately return false when the
	 * database already contains the requested value, so read-back is the
	 * authoritative success check.
	 *
	 * @param array<string, mixed> $options Complete normalized options.
	 * @return true|\WP_Error
	 */
	private static function persist( array $options ) {
		self::$internal_write = true;
		try {
			update_option( OPTION_KEY, $options, false );
		} finally {
			self::$internal_write = false;
		}

		$stored = get_option( OPTION_KEY, false );
		if ( ! is_array( $stored ) || $stored !== $options ) {
			return new \WP_Error(
				'shootcal_instagram_store_failed',
				__( 'WordPress could not save the Instagram connection.', 'shootcal-social-feed' )
			);
		}

		return true;
	}
}
