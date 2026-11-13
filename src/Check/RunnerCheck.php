<?php
/**
 * How cron is triggered.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Check;

use Saizul\CronDoctor\Heartbeat\Heartbeat;
use Saizul\CronDoctor\Report\Finding;
use Saizul\CronDoctor\Report\Severity;
use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\Snapshot;
use Saizul\CronDoctor\Support\Duration;

/**
 * Checks DISABLE_WP_CRON against evidence that a system cron runs, ALTERNATE_WP_CRON,
 * and the `doing_cron` lock.
 */
final class RunnerCheck implements Check {

	public const ID           = 'runner';
	public const ALTERNATE_ID = 'alternate-cron';
	public const LOCK_ID      = 'cron-lock';

	/**
	 * Runs the check.
	 *
	 * @param Snapshot   $snapshot   Collected data.
	 * @param Thresholds $thresholds Limits.
	 * @return Finding[]
	 */
	public function run( Snapshot $snapshot, Thresholds $thresholds ): array {
		return array(
			$this->runner( $snapshot, $thresholds ),
			$this->alternate( $snapshot ),
			$this->lock( $snapshot, $thresholds ),
		);
	}

	/**
	 * Latest evidence of a run.
	 *
	 * @param Snapshot $snapshot Collected data.
	 * @return array{time: int, source: string}|null
	 */
	public static function last_run( Snapshot $snapshot ): ?array {
		$record = null;
		if ( null !== $snapshot->last_run ) {
			$record = Heartbeat::encode( $snapshot->last_run, $snapshot->last_run_source ?? Heartbeat::SOURCE_SYSTEM );
		}
		return Heartbeat::latest( $record, $snapshot->cron_lock, $snapshot->now );
	}

	/**
	 * DISABLE_WP_CRON and last-run evidence.
	 *
	 * @param Snapshot   $snapshot   Collected data.
	 * @param Thresholds $thresholds Limits.
	 * @return Finding
	 */
	private function runner( Snapshot $snapshot, Thresholds $thresholds ): Finding {
		$last     = self::last_run( $snapshot );
		$max_late = OverdueCheck::max_lateness( $snapshot );
		$age      = null === $last ? null : max( 0, $snapshot->now - $last['time'] );
		$seen     = null === $last ? '' : sprintf( 'last run seen %s ago (%s)', Duration::human( (int) $age ), $last['source'] );
		$items    = array(
			array(
				'disable_wp_cron' => $snapshot->disable_wp_cron ? 'yes' : 'no',
				'last_run'        => null === $last ? '' : gmdate( 'Y-m-d H:i:s', $last['time'] ),
				'last_run_source' => null === $last ? '' : $last['source'],
				'max_lateness'    => Duration::human( $max_late ),
			),
		);

		if ( $snapshot->disable_wp_cron ) {
			$cron_advice = 'Add a system cron job: run "wp cron-doctor system-cron" for a ready-made crontab line or systemd timer.';

			if ( null !== $age && $age <= $thresholds->stale_after ) {
				return new Finding( self::ID, Severity::OK, 'DISABLE_WP_CRON is set and a system cron is running: ' . $seen . '.', $age, $items );
			}
			if ( null !== $age ) {
				return new Finding( self::ID, Severity::CRITICAL, 'DISABLE_WP_CRON is set but cron has not run recently: ' . $seen . '.', $age, $items, $cron_advice );
			}
			if ( $max_late >= $thresholds->overdue_critical ) {
				return new Finding( self::ID, Severity::CRITICAL, sprintf( 'DISABLE_WP_CRON is set, no run has been recorded and events are %s late: no system cron detected.', Duration::human( $max_late ) ), null, $items, $cron_advice );
			}
			if ( $max_late >= $thresholds->overdue_warning ) {
				return new Finding( self::ID, Severity::WARNING, sprintf( 'DISABLE_WP_CRON is set, no run has been recorded and events are %s late.', Duration::human( $max_late ) ), null, $items, $cron_advice );
			}
			return new Finding( self::ID, Severity::INFO, 'DISABLE_WP_CRON is set. No run has been recorded yet, but no event is overdue, so something is running them.', null, $items, 'Activate the plugin or schedule "wp cron event run --due-now" so the last run gets recorded.' );
		}

		$summary = 'WP-Cron is triggered by page visits (DISABLE_WP_CRON is not set)' . ( '' === $seen ? '' : '; ' . $seen ) . '.';
		if ( $max_late >= $thresholds->overdue_warning && ( null === $age || $age > $thresholds->stale_after ) ) {
			return new Finding( self::ID, Severity::WARNING, $summary . sprintf( ' Events are %s late, so visits are not triggering cron.', Duration::human( $max_late ) ), $age, $items, 'Low traffic, full-page caching or a blocked loopback can stop visit-based cron. A system cron is more reliable.' );
		}

		return new Finding( self::ID, Severity::OK, $summary, $age, $items, 'For production sites a system cron with DISABLE_WP_CRON is more predictable than visit-based cron.' );
	}

	/**
	 * ALTERNATE_WP_CRON.
	 *
	 * @param Snapshot $snapshot Collected data.
	 * @return Finding
	 */
	private function alternate( Snapshot $snapshot ): Finding {
		if ( ! $snapshot->alternate_wp_cron ) {
			return new Finding( self::ALTERNATE_ID, Severity::OK, 'ALTERNATE_WP_CRON is not set.' );
		}

		if ( $snapshot->disable_wp_cron ) {
			return new Finding( self::ALTERNATE_ID, Severity::INFO, 'ALTERNATE_WP_CRON is set but has no effect because DISABLE_WP_CRON is set.', null, array(), 'Remove the ALTERNATE_WP_CRON constant from wp-config.php.' );
		}

		return new Finding(
			self::ALTERNATE_ID,
			Severity::WARNING,
			'ALTERNATE_WP_CRON is set: visitors are redirected to run cron in their own request.',
			null,
			array(),
			'It is a workaround for hosts that block loopback requests. Prefer DISABLE_WP_CRON with a system cron.'
		);
	}

	/**
	 * The `doing_cron` lock.
	 *
	 * @param Snapshot   $snapshot   Collected data.
	 * @param Thresholds $thresholds Limits.
	 * @return Finding
	 */
	private function lock( Snapshot $snapshot, Thresholds $thresholds ): Finding {
		$lock = $snapshot->cron_lock;
		if ( null === $lock || $lock <= 0 ) {
			return new Finding( self::LOCK_ID, Severity::OK, 'No cron lock is held.' );
		}

		$age = (int) floor( $snapshot->now - $lock );
		if ( $age < -600 ) {
			return new Finding( self::LOCK_ID, Severity::WARNING, sprintf( 'The doing_cron lock is %s in the future; WordPress will reset it. Check the server clock.', Duration::human( -$age ) ), $age );
		}

		if ( $age <= $thresholds->lock_stale ) {
			return new Finding( self::LOCK_ID, Severity::OK, sprintf( 'A cron run started %s ago and holds the lock.', Duration::human( max( 0, $age ) ) ), max( 0, $age ) );
		}

		return new Finding(
			self::LOCK_ID,
			Severity::WARNING,
			sprintf( 'The doing_cron lock was set %s ago and never released.', Duration::human( $age ) ),
			$age,
			array(),
			'wp-cron.php deletes the lock when a run finishes. A stale lock means the last run died (fatal error, timeout, memory limit) or the spawned loopback request never arrived.'
		);
	}
}
