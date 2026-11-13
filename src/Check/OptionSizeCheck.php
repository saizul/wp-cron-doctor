<?php
/**
 * Size of the cron option.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Check;

use Saizul\CronDoctor\Report\Finding;
use Saizul\CronDoctor\Report\Severity;
use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\Snapshot;
use Saizul\CronDoctor\Support\Bytes;

/**
 * The cron option is autoloaded and rewritten on every schedule change, so a large one
 * slows every request and can exceed max_allowed_packet or object cache item limits.
 */
final class OptionSizeCheck implements Check {

	public const ID = 'option-size';

	/**
	 * Runs the check.
	 *
	 * @param Snapshot   $snapshot   Collected data.
	 * @param Thresholds $thresholds Limits.
	 * @return Finding[]
	 */
	public function run( Snapshot $snapshot, Thresholds $thresholds ): array {
		$bytes  = $snapshot->option_bytes;
		$events = $snapshot->cron->count();

		$severity = Severity::OK;
		if ( $bytes >= $thresholds->option_size_critical ) {
			$severity = Severity::CRITICAL;
		} elseif ( $bytes >= $thresholds->option_size_warning || $events >= $thresholds->events_warning ) {
			$severity = Severity::WARNING;
		}

		$summary = sprintf( 'The cron option is %s with %d event(s).', Bytes::human( $bytes ), $events );
		$advice  = '';
		if ( Severity::OK !== $severity ) {
			$advice = 'Remove duplicates and orphans first. Many single events for the same hook are better handled by Action Scheduler.';
		}

		$items = array();
		foreach ( $this->largest_hooks( $snapshot ) as $hook => $count ) {
			$items[] = array(
				'hook'   => (string) $hook,
				'events' => $count,
			);
		}

		return array( new Finding( self::ID, $severity, $summary, $bytes, Severity::OK === $severity ? array() : $items, $advice ) );
	}

	/**
	 * Hooks with the most events.
	 *
	 * @param Snapshot $snapshot Collected data.
	 * @param int      $limit    Maximum hooks.
	 * @return array<string, int>
	 */
	private function largest_hooks( Snapshot $snapshot, int $limit = 10 ): array {
		$counts = array();
		foreach ( $snapshot->cron->events() as $event ) {
			$counts[ $event->hook() ] = ( $counts[ $event->hook() ] ?? 0 ) + 1;
		}

		uksort(
			$counts,
			static function ( $a, $b ) use ( $counts ): int {
				return array( $counts[ $b ], (string) $a ) <=> array( $counts[ $a ], (string) $b );
			}
		);

		return array_slice( $counts, 0, $limit, true );
	}
}
