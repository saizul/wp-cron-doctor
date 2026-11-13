<?php
/**
 * Action Scheduler queue health.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Check;

use Saizul\CronDoctor\ActionScheduler\Schema;
use Saizul\CronDoctor\ActionScheduler\Stats;
use Saizul\CronDoctor\Report\Finding;
use Saizul\CronDoctor\Report\Severity;
use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\Snapshot;
use Saizul\CronDoctor\Support\Duration;

/**
 * Pending, past-due, failed and stuck actions.
 *
 * Action Scheduler (WooCommerce, WP Mail SMTP, Rank Math and others) runs its queue
 * from the WP-Cron hook `action_scheduler_run_queue`, so a cron problem shows up here
 * as a growing past-due backlog.
 */
final class ActionSchedulerCheck implements Check {

	public const ID          = 'action-scheduler';
	public const PAST_DUE_ID = 'as-past-due';
	public const FAILED_ID   = 'as-failed';
	public const STUCK_ID    = 'as-stuck';

	/**
	 * Runs the check.
	 *
	 * @param Snapshot   $snapshot   Collected data.
	 * @param Thresholds $thresholds Limits.
	 * @return Finding[]
	 */
	public function run( Snapshot $snapshot, Thresholds $thresholds ): array {
		$stats = $snapshot->action_scheduler;

		if ( null === $stats ) {
			return array( new Finding( self::ID, Severity::INFO, 'Action Scheduler check skipped.' ) );
		}

		if ( ! $stats->is_available() ) {
			return array( new Finding( self::ID, Severity::INFO, 'Action Scheduler not checked: ' . $stats->reason() ) );
		}

		return array(
			$this->queue( $stats, $thresholds ),
			$this->past_due( $stats, $snapshot->now, $thresholds ),
			$this->failed( $stats, $thresholds ),
			$this->stuck( $stats, $snapshot->now, $thresholds ),
		);
	}

	/**
	 * Queue size.
	 *
	 * @param Stats      $stats      Statistics.
	 * @param Thresholds $thresholds Limits.
	 * @return Finding
	 */
	private function queue( Stats $stats, Thresholds $thresholds ): Finding {
		$items = array();
		foreach ( $stats->counts() as $status => $count ) {
			$items[] = array(
				'status'  => (string) $status,
				'actions' => $count,
			);
		}

		$pending  = $stats->count( Schema::STATUS_PENDING );
		$version  = '' === $stats->version() ? '' : ' (Action Scheduler ' . $stats->version() . ')';
		$summary  = sprintf(
			'%d pending, %d in progress, %d failed, %d complete, %d canceled%s.',
			$pending,
			$stats->count( Schema::STATUS_RUNNING ),
			$stats->count( Schema::STATUS_FAILED ),
			$stats->count( Schema::STATUS_COMPLETE ),
			$stats->count( Schema::STATUS_CANCELED ),
			$version
		);
		$severity = $pending >= $thresholds->as_pending_warning ? Severity::WARNING : Severity::OK;
		$advice   = Severity::OK === $severity ? '' : 'A large pending queue usually means the queue runner cannot keep up. Run "wp action-scheduler run" or add a system cron.';

		return new Finding( self::ID, $severity, $summary, $pending, $items, $advice );
	}

	/**
	 * Past-due pending actions.
	 *
	 * @param Stats      $stats      Statistics.
	 * @param int        $now        Current time.
	 * @param Thresholds $thresholds Limits.
	 * @return Finding
	 */
	private function past_due( Stats $stats, int $now, Thresholds $thresholds ): Finding {
		$count  = $stats->past_due();
		$oldest = $stats->oldest_past_due();

		if ( 0 === $count || null === $oldest ) {
			return new Finding( self::PAST_DUE_ID, Severity::OK, 'No pending action is past due.', 0 );
		}

		$age      = max( 0, $now - $oldest );
		$severity = Severity::OK;
		if ( $age >= $thresholds->as_past_due_critical ) {
			$severity = Severity::CRITICAL;
		} elseif ( $age >= $thresholds->as_past_due_warning ) {
			$severity = Severity::WARNING;
		}

		$items = array(
			array(
				'past_due' => $count,
				'oldest'   => gmdate( 'Y-m-d H:i:s', $oldest ),
				'age'      => Duration::human( $age ),
			),
		);

		return new Finding(
			self::PAST_DUE_ID,
			$severity,
			sprintf( '%d pending action(s) are past due; the oldest by %s.', $count, Duration::human( $age ) ),
			$count,
			$items,
			Severity::OK === $severity ? '' : 'The queue runs from WP-Cron (action_scheduler_run_queue). Fix the cron findings first, then process the backlog with "wp action-scheduler run".'
		);
	}

	/**
	 * Recent failures.
	 *
	 * @param Stats      $stats      Statistics.
	 * @param Thresholds $thresholds Limits.
	 * @return Finding
	 */
	private function failed( Stats $stats, Thresholds $thresholds ): Finding {
		$count  = $stats->failed_recent();
		$window = Duration::human( $thresholds->as_failed_window );

		if ( $count < $thresholds->as_failed_warning ) {
			return new Finding( self::FAILED_ID, Severity::OK, sprintf( 'No actions failed in the last %s.', $window ), $count );
		}

		$items = array();
		foreach ( $stats->top_failing() as $row ) {
			$items[] = array(
				'hook'         => $row['hook'],
				'failures'     => $row['count'],
				'last_failure' => null === $row['last_failure'] ? '' : gmdate( 'Y-m-d H:i:s', $row['last_failure'] ),
			);
		}

		$top = array() === $items ? '' : sprintf( ' Top hook: %s (%d).', $items[0]['hook'], $items[0]['failures'] );

		return new Finding(
			self::FAILED_ID,
			$count >= $thresholds->as_failed_critical ? Severity::CRITICAL : Severity::WARNING,
			sprintf( '%d action(s) failed in the last %s.%s', $count, $window, $top ),
			$count,
			$items,
			'See the error messages with "wp action-scheduler action list --status=failed --hook=<hook>" or in Tools > Scheduled Actions.'
		);
	}

	/**
	 * Actions stuck in progress.
	 *
	 * @param Stats      $stats      Statistics.
	 * @param int        $now        Current time.
	 * @param Thresholds $thresholds Limits.
	 * @return Finding
	 */
	private function stuck( Stats $stats, int $now, Thresholds $thresholds ): Finding {
		$count = $stats->stuck();
		if ( 0 === $count ) {
			return new Finding( self::STUCK_ID, Severity::OK, sprintf( 'No action has been in progress for more than %s.', Duration::human( $thresholds->as_stuck_after ) ), 0 );
		}

		$oldest = $stats->oldest_stuck();
		$age    = null === $oldest ? '' : ' The oldest started ' . Duration::human( max( 0, $now - $oldest ) ) . ' ago.';

		return new Finding(
			self::STUCK_ID,
			Severity::WARNING,
			sprintf( '%d action(s) have been in progress for more than %s.%s', $count, Duration::human( $thresholds->as_stuck_after ), $age ),
			$count,
			array(),
			'The process running them probably died (fatal error, timeout). Action Scheduler normally marks such actions failed after its failure period; if they stay, check the PHP error log.'
		);
	}
}
