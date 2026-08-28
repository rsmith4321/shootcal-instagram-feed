<?php
/**
 * Encrypts the Instagram access token at rest with the WordPress auth salt.
 *
 * @package ShootCalInstagramFeed
 */

declare( strict_types=1 );

namespace ShootCalInstagramFeed;

defined( 'ABSPATH' ) || exit;

class Token_Cipher {

	private const PREFIX = 'scif1:';
	private const CIPHER = 'aes-256-gcm';

	/**
	 * @return string|\WP_Error
	 */
	public static function encrypt( string $plaintext ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return new \WP_Error(
				'shootcal_instagram_no_openssl',
				__( 'OpenSSL is required to store the Instagram token securely.', 'shootcal-instagram-feed' )
			);
		}

		try {
			$iv = random_bytes( 12 );
		} catch ( \Exception $exception ) {
			return new \WP_Error(
				'shootcal_instagram_random_failed',
				__( 'WordPress could not generate secure random data for the token.', 'shootcal-instagram-feed' )
			);
		}

		$tag        = '';
		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			self::key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $ciphertext || 16 !== strlen( $tag ) ) {
			return new \WP_Error(
				'shootcal_instagram_encrypt_failed',
				__( 'WordPress could not encrypt the Instagram token.', 'shootcal-instagram-feed' )
			);
		}

		return self::PREFIX . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * @return string|\WP_Error
	 */
	public static function decrypt( string $stored ) {
		if ( strpos( $stored, self::PREFIX ) !== 0 || ! function_exists( 'openssl_decrypt' ) ) {
			return new \WP_Error(
				'shootcal_instagram_decrypt_failed',
				__( 'The stored Instagram token could not be decrypted. Save a new token.', 'shootcal-instagram-feed' )
			);
		}

		$decoded = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $decoded || strlen( $decoded ) < 29 ) {
			return new \WP_Error(
				'shootcal_instagram_decrypt_failed',
				__( 'The stored Instagram token could not be decrypted. Save a new token.', 'shootcal-instagram-feed' )
			);
		}

		$iv         = substr( $decoded, 0, 12 );
		$tag        = substr( $decoded, 12, 16 );
		$ciphertext = substr( $decoded, 28 );
		$plaintext  = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			self::key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $plaintext || '' === $plaintext ) {
			return new \WP_Error(
				'shootcal_instagram_decrypt_failed',
				__( 'The stored Instagram token could not be decrypted. Save a new token.', 'shootcal-instagram-feed' )
			);
		}

		return $plaintext;
	}

	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|shootcal-instagram-feed', true );
	}
}

