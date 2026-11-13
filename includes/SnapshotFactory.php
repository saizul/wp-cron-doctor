<?php
/**
 * Collects a snapshot from WordPress.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\WP;

use Saizul\CronDoctor\Cron\CronArray;
use Saizul\CronDoctor\Heartbeat\Heartbeat;
use Saizul\CronDoctor\Loopback\LoopbackResult;
use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\Snapshot;

/**
 * The only place that reads cron state from WordPress for the report.
 */
final class SnapshotFactory {

	/**
	 * Loopback probe.
	 *
	 * @var LoopbackProbe
	 */
	private LoopbackProbe $probe;

	/**
	 * Action Scheduler reader factory.
	 *
	 * @var callable(): ActionSchedulerSource
	 */
	private $as_source;

	/**
	 * Constructor.
	 *
	 * @param LoopbackProbe|null $probe     Probe.
	 * @param callable|null      $as_source Returns an ActionSchedulerSource.
	 */
	public function __construct( ?LoopbackProbe $probe = null, ?callable $as_source = null ) {
		$this->probe     = $probe ?? new LoopbackProbe();
		$this->as_source = $as_source ?? static function (): ActionSchedulerSource {
			return new ActionSchedulerSource();
		};
	}

	/**
	 * Builds the snapshot.
	 *
	 * @param Thresholds $thresholds Limits.
	 * @param array      $options    {
	 *     Options.
	 *
	 *     @type bool     $loopback         Run the loopback test. Default true.
	 *     @type bool     $action_scheduler Inspect Action Scheduler. Default true.
	 *     @type string[] $ignored_hooks    Extra hooks or patterns that are never orphans.
	 *     @type int      $loopback_timeout Seconds. Default 10.
	 * }
	 * @return Snapshot
	 */
	public function create( Thresholds $thresholds, array $options = array() ): Snapshot {
		$now = time();
		$raw = get_option( 'cron' );

		$cron     = CronArray::from_option( $raw );
		$snapshot = new Snapshot( $now, $cron );

		$snapshot->option_bytes      = is_array( $raw ) ? strlen( (string) maybe_serialize( $raw ) ) : 0;
		$snapshot->callbacks         = $this->callbacks( $cron->hooks() );
		$snapshot->schedules         = $this->schedules();
		$snapshot->disable_wp_cron   = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$snapshot->alternate_wp_cron = defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON;
		$snapshot->cron_lock         = $this->lock();
		$snapshot->ignored_hooks     = $this->ignored_hooks( $options['ignored_hooks'] ?? array() );

		$record = Heartbeat::decode( get_option( Heartbeat::OPTION ) );
		if ( null !== $record ) {
			$snapshot->last_run        = $record['time'];
			$snapshot->last_run_source = $record['source'];
		}

		if ( $options['loopback'] ?? true ) {
			$snapshot->loopback = $this->probe->run( (int) ( $options['loopback_timeout'] ?? 10 ) );
		} else {
			$snapshot->loopback = LoopbackResult::skipped( 'disabled with --skip-loopback' );
		}

		if ( $options['action_scheduler'] ?? true ) {
			$source                     = call_user_func( $this->as_source );
			$snapshot->action_scheduler = $source->collect( $now, $thresholds );
		}

		return $snapshot;
	}

	/**
	 * Whether each hook has a callback.
	 *
	 * @param string[] $hooks Hook names.
	 * @return array<string, bool>
	 */
	private function callbacks( array $hooks ): array {
		$callbacks = array();
		foreach ( $hooks as $hook ) {
			$callbacks[ $hook ] = false !== has_action( $hook );
		}
		return $callbacks;
	}

	/**
	 * Registered recurrences.
	 *
	 * @return array<string, int>
	 */
	private function schedules(): array {
		$schedules = array();
		foreach ( (array) wp_get_schedules() as $name => $schedule ) {
			if ( is_array( $schedule ) && isset( $schedule['interval'] ) ) {
				$schedules[ (string) $name ] = (int) $schedule['interval'];
			}
		}
		return $schedules;
	}

	/**
	 * The `doing_cron` lock.
	 *
	 * @return float|null
	 */
	private function lock(): ?float {
		$lock = get_transient( 'doing_cron' );
		if ( ! is_numeric( $lock ) || (float) $lock <= 0 ) {
			return null;
		}
		return (float) $lock;
	}

	/**
	 * Hooks that are never reported as orphans.
	 *
	 * @param string[] $extra Hooks from the command line.
	 * @return string[]
	 */
	private function ignored_hooks( array $extra ): array {
		/**
		 * Filters hooks (or `*` patterns) that are never reported or removed as orphans.
		 *
		 * Use it for hooks whose callbacks are only attached in some requests.
		 *
		 * @param string[] $hooks Hook names or patterns.
		 */
		$hooks = apply_filters( 'wp_cron_doctor_ignored_hooks', array() );

		return array_values( array_unique( array_merge( array_map( 'strval', (array) $hooks ), $extra ) ) );
	}
}
