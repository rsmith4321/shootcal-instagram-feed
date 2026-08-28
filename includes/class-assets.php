<?php
/**
 * Front-end stylesheet registration.
 *
 * @package ShootCalInstagramFeed
 */

declare( strict_types=1 );

namespace ShootCalInstagramFeed;

defined( 'ABSPATH' ) || exit;

class Assets {

	private const STYLE_HANDLE = 'shootcal-instagram-feed';
	private const SCRIPT_HANDLE = 'shootcal-instagram-feed';

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_style' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_style' ) );
	}

	public function register_style(): void {
		wp_register_style(
			self::STYLE_HANDLE,
			PLUGIN_URL . 'assets/feed.css',
			array(),
			VERSION
		);
		wp_register_script(
			self::SCRIPT_HANDLE,
			PLUGIN_URL . 'assets/feed.js',
			array(),
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	public static function enqueue(): void {
		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			wp_register_style( self::STYLE_HANDLE, PLUGIN_URL . 'assets/feed.css', array(), VERSION );
		}
		wp_enqueue_style( self::STYLE_HANDLE );
	}

	public static function enqueue_dynamic(): void {
		self::enqueue();
		if ( ! wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
			wp_register_script(
				self::SCRIPT_HANDLE,
				PLUGIN_URL . 'assets/feed.js',
				array(),
				VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}
		wp_enqueue_script( self::SCRIPT_HANDLE );
	}
}
