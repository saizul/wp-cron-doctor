<?php
/**
 * Recurrence problems.
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
 * Reports very frequent recurrences and events whose recurrence is not registered.
 *
 * WP-Cron spawns at most once per WP_CRON_LOCK_TIMEOUT (60 seconds by default), so an
 * interval below a few minutes means the event is effectively always due and every
 * cron run executes it. An unknown recurrence means the plugin that registered it via
 * the `cron_schedules` filter is gone; wp_reschedule_event() then falls back to the
 * stored interval.
 */
final class ScheduleCheck implements Check {

	public const FREQUENT_ID = 'frequent';
	public const UNKNOWN_ID  = 'unknown-schedules';

	/**
	 * Runs the check.
	 *
	 * @param Snapshot   $snapshot   Collected data.
	 * @param Thresholds $thresholds Limits.
	 * @return Finding[]
	 */
	public function run( Snapshot $snapshot, Thresholds $thresholds ): array {
		return array(
			$this->frequent( $snapshot, $thresholds ),
			$this->unknown( $snapshot ),
		);
	}

	/**
	 * Effective interval: the registered one, else the stored one.
	 *
	 * @param Event              $event     Event.
	 * @param array<string, int> $schedules Registered recurrences.
	 * @return int|null
	 */
	public static function effective_interval( Event $event, array $schedules ): ?int {
		$schedule = $event->schedule();
		if ( false === $schedule ) {
			return null;
		}
		if ( isset( $schedules[ $schedule ] ) && (int) $schedules[ $schedule ] > 0 ) {
			return (int) $schedules[ $schedule ];
		}
		return $event->interval();
	}

	/**
	 * Very frequent recurring events.
	 *
	 * @param Snapshot   $snapshot   Collected data.
	 * @param Thresholds $thresholds Limits.
	 * @return Finding
	 */
	private function frequent( Snapshot $snapshot, Thresholds $thresholds ): Finding {
		$rows = array();
		foreach ( $snapshot->cron->events() as $event ) {
			$interval = self::effective_interval( $event, $snapshot->schedules );
			if ( null === $interval || $interval >= $thresholds->frequent_interval ) {
				continue;
			}
			$key = $event->hook() . '|' . $event->schedule();
			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array(
					'hook'     => $event->hook(),
					'schedule' => (string) $event->schedule(),
					'interval' => $interval,
					'every'    => Duration::human( $interval ),
					'events'   => 0,
				);
			}
			++$rows[ $key ]['events'];
		}

		if ( array() === $rows ) {
			return new Finding( self::FREQUENT_ID, Severity::OK, sprintf( 'No recurring event runs more often than every %s.', Duration::human( $thresholds->frequent_interval ) ), 0 );
		}

		$rows = array_values( $rows );
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return array( $a['interval'], $a['hook'] ) <=> array( $b['interval'], $b['hook'] );
			}
		);

		return new Finding(
			self::FREQUENT_ID,
			Severity::WARNING,
			sprintf( '%d hook(s) recur more often than every %s; the shortest interval is %s.', count( $rows ), Duration::human( $thresholds->frequent_interval ), Duration::human( $rows[0]['interval'] ) ),
			count( $rows ),
			$rows,
			'Very short intervals make every cron run do work and keep rewriting the cron option. Prefer Action Scheduler or a longer interval.'
		);
	}

	/**
	 * Events with a recurrence that is not registered.
	 *
	 * @param Snapshot $snapshot Collected data.
	 * @return Finding
	 */
	private function unknown( Snapshot $snapshot ): Finding {
		if ( array() === $snapshot->schedules ) {
			return new Finding( self::UNKNOWN_ID, Severity::INFO, 'Registered schedules were not inspected.', null );
		}

		$rows = array();
		foreach ( $snapshot->cron->events() as $event ) {
			$schedule = $event->schedule();
			if ( false === $schedule || isset( $snapshot->schedules[ $schedule ] ) ) {
				continue;
			}
			$key = $event->hook() . '|' . $schedule;
			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array(
					'hook'     => $event->hook(),
					'schedule' => $schedule,
					'interval' => null === $event->interval() ? '' : $event->interval(),
					'events'   => 0,
				);
			}
			++$rows[ $key ]['events'];
		}

		if ( array() === $rows ) {
			return new Finding( self::UNKNOWN_ID, Severity::OK, 'All recurrences are registered.', 0 );
		}

		$rows = array_values( $rows );

		return new Finding(
			self::UNKNOWN_ID,
			Severity::WARNING,
			sprintf( '%d hook(s) use a recurrence that is no longer registered.', count( $rows ) ),
			count( $rows ),
			$rows,
			'The plugin that added the recurrence with the cron_schedules filter is probably inactive. Events without a stored interval fail to reschedule.'
		);
	}
}
