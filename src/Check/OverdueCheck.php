<?php
/**
 * Overdue events.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Check;

use Saizul\CronDoctor\Cron\Event;
use Saizul\CronDoctor\Report\Finding;
use Saizul\CronDoctor\Report\Severity;
use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\Snapshot;
use Saizul\CronDoctor\Support\Duration;

/**
 * Lists events that should have run already and how late they are.
 */
final class OverdueCheck implements Check {

	public const ID = 'overdue';

	/**
	 * Runs the check.
	 *
	 * @param Snapshot   $snapshot   Collected data.
	 * @param Thresholds $thresholds Limits.
	 * @return Finding[]
	 */
	public function run( Snapshot $snapshot, Thresholds $thresholds ): array {
		$now     = $snapshot->now;
		$overdue = self::overdue_events( $snapshot, $thresholds->overdue_warning );
		$due     = count( $snapshot->cron->ready( $now ) );

		if ( array() === $overdue ) {
			$summary = 0 === $due
				? 'No events are overdue.'
				: sprintf( '%d event(s) are due but within the %s grace period.', $due, Duration::human( $thresholds->overdue_warning ) );

			return array( new Finding( self::ID, Severity::OK, $summary, 0 ) );
		}

		$max = 0;
		foreach ( $overdue as $event ) {
			$max = max( $max, $event->lateness( $now ) );
		}

		$severity = $max >= $thresholds->overdue_critical ? Severity::CRITICAL : Severity::WARNING;
		$items    = array_map(
			static function ( Event $event ) use ( $now ): array {
				return $event->to_row( $now );
			},
			$overdue
		);

		return array(
			new Finding(
				self::ID,
				$severity,
				sprintf( '%d event(s) overdue by more than %s; the oldest is %s late.', count( $overdue ), Duration::human( $thresholds->overdue_warning ), Duration::human( $max ) ),
				count( $overdue ),
				$items,
				'Check that cron runs (see the runner and loopback checks), then run "wp cron-doctor run-overdue".'
			),
		);
	}

	/**
	 * Events late by at least $grace seconds, oldest first.
	 *
	 * @param Snapshot $snapshot Collected data.
	 * @param int      $grace    Seconds.
	 * @return Event[]
	 */
	public static function overdue_events( Snapshot $snapshot, int $grace ): array {
		return array_values(
			array_filter(
				$snapshot->cron->ready( $snapshot->now ),
				static function ( Event $event ) use ( $snapshot, $grace ): bool {
					return $event->lateness( $snapshot->now ) >= max( 1, $grace );
				}
			)
		);
	}

	/**
	 * Lateness of the most overdue event, 0 when none.
	 *
	 * @param Snapshot $snapshot Collected data.
	 * @return int
	 */
	public static function max_lateness( Snapshot $snapshot ): int {
		$ready = $snapshot->cron->ready( $snapshot->now );
		if ( array() === $ready ) {
			return 0;
		}
		return max( 0, $ready[0]->lateness( $snapshot->now ) );
	}
}
