<?php
/**
 * Changes and runs cron events through the WordPress API.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\WP;

use Saizul\CronDoctor\Cron\Event;
use Throwable;

/**
 * Removes and runs events. Uses wp_unschedule_event() and wp_reschedule_event() so the
 * usual `pre_unschedule_event` / `pre_reschedule_event` filters and object caches work.
 */
final class EventRunner {

	/**
	 * Removes one event.
	 *
	 * @param Event $event Event.
	 * @return true|string True or an error message.
	 */
	public function unschedule( Event $event ) {
		if ( ! $event->has_valid_key() ) {
			return 'The stored key does not match the arguments; wp_unschedule_event() cannot remove this event.';
		}

		$result = wp_unschedule_event( $event->timestamp(), $event->hook(), $event->args(), true );

		return $this->outcome( $result, 'wp_unschedule_event() failed.' );
	}

	/**
	 * Runs one event the way wp-cron.php does: reschedule (recurring events), unschedule,
	 * then fire the hook.
	 *
	 * @param Event $event Event.
	 * @return array{status: string, seconds: float, error: string}
	 */
	public function run( Event $event ): array {
		$start   = microtime( true );
		$warning = '';

		// Like wp-cron.php, a failed reschedule is reported but does not stop the run.
		if ( $event->is_recurring() ) {
			$rescheduled = wp_reschedule_event( $event->timestamp(), (string) $event->schedule(), $event->hook(), $event->args(), true );
			$outcome     = $this->outcome( $rescheduled, 'wp_reschedule_event() failed.' );
			if ( true !== $outcome ) {
				$warning = 'Not rescheduled: ' . $outcome;
			}
		}

		// Unlike wp-cron.php, the hook is not fired when the event cannot be removed, so it cannot run twice.
		$outcome = $this->unschedule( $event );
		if ( true !== $outcome ) {
			return $this->result( 'error', $start, trim( 'Unschedule failed: ' . $outcome . ' ' . $warning ) );
		}

		$doing_cron = static function (): bool {
			return true;
		};
		add_filter( 'wp_doing_cron', $doing_cron );

		try {
			do_action_ref_array( $event->hook(), $event->args() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Fires the scheduled event, like wp-cron.php.
		} catch ( Throwable $e ) {
			return $this->result( 'error', $start, trim( get_class( $e ) . ': ' . $e->getMessage() . ' ' . $warning ) );
		} finally {
			remove_filter( 'wp_doing_cron', $doing_cron );
		}

		return $this->result( '' === $warning ? 'ran' : 'warning', $start, $warning );
	}

	/**
	 * Converts a WordPress return value.
	 *
	 * @param mixed  $result   Return value.
	 * @param string $fallback Message when the value is false.
	 * @return true|string
	 */
	private function outcome( $result, string $fallback ) {
		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}
		return false === $result ? $fallback : true;
	}

	/**
	 * Builds a result row.
	 *
	 * @param string $status Status.
	 * @param float  $start  Start time.
	 * @param string $error  Error message.
	 * @return array{status: string, seconds: float, error: string}
	 */
	private function result( string $status, float $start, string $error ): array {
		return array(
			'status'  => $status,
			'seconds' => round( microtime( true ) - $start, 3 ),
			'error'   => $error,
		);
	}
}
