<?php
/**
 * Structural problems in the cron option.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Check;

use Saizul\CronDoctor\Report\Finding;
use Saizul\CronDoctor\Report\Severity;
use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\Snapshot;

/**
 * Reports malformed entries, key mismatches and an unexpected version.
 */
final class IntegrityCheck implements Check {

	public const ID = 'integrity';

	/**
	 * Runs the check.
	 *
	 * @param Snapshot   $snapshot   Collected data.
	 * @param Thresholds $thresholds Limits.
	 * @return Finding[]
	 */
	public function run( Snapshot $snapshot, Thresholds $thresholds ): array {
		$problems = $snapshot->cron->problems();

		if ( array() === $problems ) {
			$summary = sprintf( 'The cron option is well formed (%d events).', $snapshot->cron->count() );
			if ( $snapshot->cron->is_legacy() ) {
				return array( new Finding( self::ID, Severity::INFO, 'The cron option has no version key; WordPress upgrades it the next time it is read.', 0 ) );
			}
			return array( new Finding( self::ID, Severity::OK, $summary, 0 ) );
		}

		$items = array_map(
			static function ( string $problem ): array {
				return array( 'problem' => $problem );
			},
			$problems
		);

		return array(
			new Finding(
				self::ID,
				Severity::WARNING,
				sprintf( '%d structural problem(s) in the cron option.', count( $problems ) ),
				count( $problems ),
				$items,
				'Malformed entries are usually written by a plugin that edits the cron option directly. Back up the option before changing it.'
			),
		);
	}
}
