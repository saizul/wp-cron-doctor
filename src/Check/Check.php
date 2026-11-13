<?php
/**
 * Contract for a diagnostic check.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Check;

use Saizul\CronDoctor\Report\Finding;
use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\Snapshot;

/**
 * A check reads a snapshot and returns one or more findings. It must not call WordPress.
 */
interface Check {

	/**
	 * Runs the check.
	 *
	 * @param Snapshot   $snapshot   Collected data.
	 * @param Thresholds $thresholds Limits.
	 * @return Finding[]
	 */
	public function run( Snapshot $snapshot, Thresholds $thresholds ): array;
}
