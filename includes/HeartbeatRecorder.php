<?php
/**
 * Records when cron runs.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\WP;

use Saizul\CronDoctor\Heartbeat\Heartbeat;

/**
 * Writes the last-run record, at most once per minute per source.
 */
final class HeartbeatRecorder {

	/**
	 * Records a run now.
	 *
	 * @param string $source Source.
	 * @return bool Whether the option was written.
	 */
	public function record( string $source ): bool {
		$now = time();
		if ( ! Heartbeat::should_write( get_option( Heartbeat::OPTION ), $now, $source ) ) {
			return false;
		}

		return (bool) update_option( Heartbeat::OPTION, Heartbeat::encode( $now, $source ), false );
	}

	/**
	 * Records the current request if it is a WP-Cron request (plugin mode).
	 *
	 * @return bool Whether the option was written.
	 */
	public function record_current_request(): bool {
		// WP-CLI runs are recorded by the after_invoke hook in command.php, and only for
		// `wp cron event run --due-now`; `wp cron-doctor report --cron-context` must not count.
		if ( ! wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only checks for the presence of the argument WordPress adds itself.
		$has_key = isset( $_GET['doing_wp_cron'] );
		$source  = Heartbeat::classify( $has_key, defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON, false );

		return $this->record( $source );
	}

	/**
	 * Removes the record.
	 *
	 * @return void
	 */
	public function forget(): void {
		delete_option( Heartbeat::OPTION );
	}
}
