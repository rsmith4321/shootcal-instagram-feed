<?php
/** Restore exact test snapshots without treating them as browser settings input. */
declare( strict_types=1 );

/** @param array<string,mixed> $snapshots Values captured with get_option($key, false). */
function shootcal_instagram_restore_test_options( array $snapshots ): void {
	$failed = array();
	foreach ( $snapshots as $key => $snapshot ) {
		// The registered settings sanitizer intentionally retains the CURRENT
		// token for browser writes. Test cleanup must restore the original bytes,
		// after that sanitizer, while leaving every pre-existing filter installed.
		$restore = static fn( $value ) => $snapshot;
		$hook = 'sanitize_option_' . $key;
		add_filter( $hook, $restore, PHP_INT_MAX );
		try {
			false === $snapshot ? delete_option( $key ) : update_option( $key, $snapshot, false );
			if ( get_option( $key, false ) !== $snapshot ) {
				$failed[] = $key;
			}
		} finally {
			remove_filter( $hook, $restore, PHP_INT_MAX );
		}
	}
	if ( $failed ) {
		throw new RuntimeException( 'Integration cleanup did not restore saved options: ' . implode( ', ', $failed ) );
	}
}
