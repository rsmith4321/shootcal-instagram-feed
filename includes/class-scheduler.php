<?php
/**
 * Background feed and token refresh scheduling.
 *
 * @package ShootCalInstagramFeed
 */

declare( strict_types=1 );

namespace ShootCalInstagramFeed;

defined( 'ABSPATH' ) || exit;

class Scheduler {

	private const SCHEDULE = 'shootcal_instagram_six_hours';

	public function __construct( private Feed_Store $store ) {
	}

	public function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_schedule' ) );
		add_action( CRON_HOOK, array( $this, 'run' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
	}

	/**
	 * @param array<string, array<string, int|string>> $schedules Existing schedules.
	 * @return array<string, array<string, int|string>>
	 */
	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every six hours', 'shootcal-social-feed' ),
		);

		return $schedules;
	}

	public static function schedule(): void {
		// Activation can run after plugins_loaded, so ensure the custom interval
		// exists even when the normal bootstrap callback has not run yet.
		add_filter( 'cron_schedules', array( self::class, 'add_schedule' ) );
		if ( ! wp_next_scheduled( CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::SCHEDULE, CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( CRON_HOOK );
	}

	public function run(): void {
		if ( ! Config::has_token() ) {
			return;
		}

		$this->store->refresh();
	}
}
