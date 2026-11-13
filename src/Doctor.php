<?php
/**
 * Runs all checks.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor;

use Saizul\CronDoctor\Check\ActionSchedulerCheck;
use Saizul\CronDoctor\Check\Check;
use Saizul\CronDoctor\Check\DuplicateCheck;
use Saizul\CronDoctor\Check\IntegrityCheck;
use Saizul\CronDoctor\Check\LoopbackCheck;
use Saizul\CronDoctor\Check\OptionSizeCheck;
use Saizul\CronDoctor\Check\OrphanCheck;
use Saizul\CronDoctor\Check\OverdueCheck;
use Saizul\CronDoctor\Check\RunnerCheck;
use Saizul\CronDoctor\Check\ScheduleCheck;
use Saizul\CronDoctor\Report\Report;
use Saizul\CronDoctor\Report\Thresholds;

/**
 * Turns a snapshot into a report.
 */
final class Doctor {

	/**
	 * Checks in report order.
	 *
	 * @var Check[]
	 */
	private array $checks;

	/**
	 * Constructor.
	 *
	 * @param Check[]|null $checks Checks; the default set when null.
	 */
	public function __construct( ?array $checks = null ) {
		$this->checks = $checks ?? self::default_checks();
	}

	/**
	 * The built-in checks.
	 *
	 * @return Check[]
	 */
	public static function default_checks(): array {
		return array(
			new RunnerCheck(),
			new LoopbackCheck(),
			new OverdueCheck(),
			new DuplicateCheck(),
			new OrphanCheck(),
			new ScheduleCheck(),
			new OptionSizeCheck(),
			new IntegrityCheck(),
			new ActionSchedulerCheck(),
		);
	}

	/**
	 * Runs every check.
	 *
	 * @param Snapshot        $snapshot   Collected data.
	 * @param Thresholds|null $thresholds Limits; defaults when null.
	 * @return Report
	 */
	public function diagnose( Snapshot $snapshot, ?Thresholds $thresholds = null ): Report {
		$thresholds = $thresholds ?? new Thresholds();
		$report     = new Report( $snapshot->now );

		foreach ( $this->checks as $check ) {
			foreach ( $check->run( $snapshot, $thresholds ) as $finding ) {
				$report->add( $finding );
			}
		}

		return $report;
	}

	/**
	 * Identifiers of all built-in findings, for `--check` validation.
	 *
	 * @return string[]
	 */
	public static function check_ids(): array {
		return array(
			RunnerCheck::ID,
			RunnerCheck::ALTERNATE_ID,
			RunnerCheck::LOCK_ID,
			LoopbackCheck::ID,
			OverdueCheck::ID,
			DuplicateCheck::ID,
			OrphanCheck::ID,
			ScheduleCheck::FREQUENT_ID,
			ScheduleCheck::UNKNOWN_ID,
			OptionSizeCheck::ID,
			IntegrityCheck::ID,
			ActionSchedulerCheck::ID,
			ActionSchedulerCheck::PAST_DUE_ID,
			ActionSchedulerCheck::FAILED_ID,
			ActionSchedulerCheck::STUCK_ID,
		);
	}
}
