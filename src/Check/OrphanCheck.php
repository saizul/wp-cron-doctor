<?php
/**
 * Events without a callback.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Check;

use Saizul\CronDoctor\Fixer\OrphanPlanner;
use Saizul\CronDoctor\Report\Finding;
use Saizul\CronDoctor\Report\Severity;
use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\Snapshot;

/**
 * Reports hooks that are scheduled but have nothing attached.
 */
final class OrphanCheck implements Check {

	public const ID = 'orphans';

	/**
	 * Planner.
	 *
	 * @var OrphanPlanner
	 */
	private OrphanPlanner $planner;

	/**
	 * Constructor.
	 *
	 * @param OrphanPlanner|null $planner Planner.
	 */
	public function __construct( ?OrphanPlanner $planner = null ) {
		$this->planner = $planner ?? new OrphanPlanner();
	}

	/**
	 * Runs the check.
	 *
	 * @param Snapshot   $snapshot   Collected data.
	 * @param Thresholds $thresholds Limits.
	 * @return Finding[]
	 */
	public function run( Snapshot $snapshot, Thresholds $thresholds ): array {
		if ( 0 === $snapshot->cron->count() ) {
			return array( new Finding( self::ID, Severity::OK, 'No events are scheduled.', 0 ) );
		}

		if ( array() === $snapshot->callbacks ) {
			return array( new Finding( self::ID, Severity::INFO, 'Callbacks were not inspected.', null ) );
		}

		$orphans = $this->planner->orphans( $snapshot );
		if ( array() === $orphans ) {
			return array( new Finding( self::ID, Severity::OK, 'Every scheduled hook has a callback.', 0 ) );
		}

		$items  = array();
		$events = 0;
		foreach ( $orphans as $hook => $hook_events ) {
			$events += count( $hook_events );
			$first   = $hook_events[0];
			$items[] = array(
				'hook'     => (string) $hook,
				'events'   => count( $hook_events ),
				'next'     => gmdate( 'Y-m-d H:i:s', $first->timestamp() ),
				'schedule' => false === $first->schedule() ? 'single' : $first->schedule(),
			);
		}

		return array(
			new Finding(
				self::ID,
				Severity::WARNING,
				sprintf( '%d hook(s) with %d event(s) have no callback (left behind by removed plugins?).', count( $orphans ), $events ),
				count( $orphans ),
				$items,
				'Some plugins attach callbacks only in wp-admin or during cron requests. Confirm with "wp cron-doctor report --cron-context", then preview "wp cron-doctor clean-orphans".'
			),
		);
	}
}
