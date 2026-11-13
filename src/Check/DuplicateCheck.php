<?php
/**
 * Duplicate events.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Check;

use Saizul\CronDoctor\Cron\Event;
use Saizul\CronDoctor\Fixer\DuplicatePlanner;
use Saizul\CronDoctor\Report\Finding;
use Saizul\CronDoctor\Report\Severity;
use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\Snapshot;
use Saizul\CronDoctor\Support\Args;

/**
 * Reports hooks scheduled more than once with the same arguments.
 */
final class DuplicateCheck implements Check {

	public const ID = 'duplicates';

	/**
	 * Planner.
	 *
	 * @var DuplicatePlanner
	 */
	private DuplicatePlanner $planner;

	/**
	 * Constructor.
	 *
	 * @param DuplicatePlanner|null $planner Planner.
	 */
	public function __construct( ?DuplicatePlanner $planner = null ) {
		$this->planner = $planner ?? new DuplicatePlanner();
	}

	/**
	 * Runs the check.
	 *
	 * @param Snapshot   $snapshot   Collected data.
	 * @param Thresholds $thresholds Limits.
	 * @return Finding[]
	 */
	public function run( Snapshot $snapshot, Thresholds $thresholds ): array {
		$groups = $this->planner->groups( $snapshot->cron );

		if ( array() === $groups ) {
			return array( new Finding( self::ID, Severity::OK, 'No duplicate events.', 0 ) );
		}

		$surplus = 0;
		$items   = array();
		foreach ( $groups as $group ) {
			$surplus += count( $group['remove'] );

			$items[] = array(
				'hook'       => $group['keep']->hook(),
				'args'       => Args::describe( $group['keep']->args() ),
				'copies'     => count( $group['remove'] ) + 1,
				'schedule'   => false === $group['keep']->schedule() ? 'single' : $group['keep']->schedule(),
				'next'       => gmdate( 'Y-m-d H:i:s', $group['keep']->timestamp() ),
				'timestamps' => implode(
					',',
					array_map(
						static function ( Event $event ): int {
							return $event->timestamp();
						},
						array_merge( array( $group['keep'] ), $group['remove'] )
					)
				),
			);
		}

		$severity = $surplus >= $thresholds->duplicates_critical ? Severity::CRITICAL : Severity::WARNING;

		return array(
			new Finding(
				self::ID,
				$severity,
				sprintf( '%d hook/argument combination(s) are scheduled more than once (%d surplus event(s)).', count( $groups ), $surplus ),
				$surplus,
				$items,
				'Preview with "wp cron-doctor dedupe", apply with "wp cron-doctor dedupe --yes", then fix the plugin that schedules without wp_next_scheduled().'
			),
		);
	}
}
