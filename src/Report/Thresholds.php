<?php
/**
 * Configurable limits for the checks.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Report;

use InvalidArgumentException;
use Saizul\CronDoctor\Support\Bytes;
use Saizul\CronDoctor\Support\Duration;

/**
 * Immutable set of limits. Durations are in seconds and sizes in bytes.
 */
final class Thresholds {

	/**
	 * Option name => [ property, type ]. Types: duration, bytes, int.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const OPTIONS = array(
		'overdue-warning'      => array( 'overdue_warning', 'duration' ),
		'overdue-critical'     => array( 'overdue_critical', 'duration' ),
		'frequent'             => array( 'frequent_interval', 'duration' ),
		'stale-after'          => array( 'stale_after', 'duration' ),
		'lock-stale'           => array( 'lock_stale', 'duration' ),
		'option-size-warning'  => array( 'option_size_warning', 'bytes' ),
		'option-size-critical' => array( 'option_size_critical', 'bytes' ),
		'events-warning'       => array( 'events_warning', 'int' ),
		'duplicates-critical'  => array( 'duplicates_critical', 'int' ),
		'loopback-slow'        => array( 'loopback_slow', 'duration' ),
		'as-past-due-warning'  => array( 'as_past_due_warning', 'duration' ),
		'as-past-due-critical' => array( 'as_past_due_critical', 'duration' ),
		'as-failed-window'     => array( 'as_failed_window', 'duration' ),
		'as-failed-warning'    => array( 'as_failed_warning', 'int' ),
		'as-failed-critical'   => array( 'as_failed_critical', 'int' ),
		'as-stuck-after'       => array( 'as_stuck_after', 'duration' ),
		'as-pending-warning'   => array( 'as_pending_warning', 'int' ),
	);

	/**
	 * Lateness that makes an event overdue (warning).
	 *
	 * @var int
	 */
	public int $overdue_warning = 600;

	/**
	 * Lateness that is critical.
	 *
	 * @var int
	 */
	public int $overdue_critical = 3600;

	/**
	 * Intervals shorter than this are "very frequent".
	 *
	 * @var int
	 */
	public int $frequent_interval = 300;

	/**
	 * Last cron run older than this means cron is not running.
	 *
	 * @var int
	 */
	public int $stale_after = 3600;

	/**
	 * A `doing_cron` lock older than this means a run died.
	 *
	 * @var int
	 */
	public int $lock_stale = 600;

	/**
	 * Size of the cron option that gives a warning.
	 *
	 * @var int
	 */
	public int $option_size_warning = 262144;

	/**
	 * Size of the cron option that is critical.
	 *
	 * @var int
	 */
	public int $option_size_critical = 1048576;

	/**
	 * Number of events that gives a warning.
	 *
	 * @var int
	 */
	public int $events_warning = 1000;

	/**
	 * Number of surplus duplicate events that is critical.
	 *
	 * @var int
	 */
	public int $duplicates_critical = 50;

	/**
	 * Loopback requests slower than this give a warning.
	 *
	 * @var int
	 */
	public int $loopback_slow = 5;

	/**
	 * Age of the oldest past-due action that gives a warning.
	 *
	 * @var int
	 */
	public int $as_past_due_warning = 600;

	/**
	 * Age of the oldest past-due action that is critical.
	 *
	 * @var int
	 */
	public int $as_past_due_critical = 3600;

	/**
	 * Only count actions that failed within this window.
	 *
	 * @var int
	 */
	public int $as_failed_window = 604800;

	/**
	 * Recent failures that give a warning.
	 *
	 * @var int
	 */
	public int $as_failed_warning = 1;

	/**
	 * Recent failures that are critical.
	 *
	 * @var int
	 */
	public int $as_failed_critical = 100;

	/**
	 * In-progress actions older than this are stuck.
	 *
	 * @var int
	 */
	public int $as_stuck_after = 3600;

	/**
	 * Pending actions that give a warning (queue backlog).
	 *
	 * @var int
	 */
	public int $as_pending_warning = 10000;

	/**
	 * Builds thresholds from command options such as `overdue-warning=15m`.
	 *
	 * Unknown keys are ignored so the whole assoc args array can be passed.
	 *
	 * @param array<string, mixed> $options Options.
	 * @return self
	 * @throws InvalidArgumentException When a value is invalid or the limits contradict each other.
	 */
	public static function from_options( array $options ): self {
		$thresholds = new self();

		foreach ( self::OPTIONS as $name => $spec ) {
			if ( ! array_key_exists( $name, $options ) ) {
				continue;
			}
			list( $property, $type ) = $spec;

			$value = $options[ $name ];
			if ( true === $value || null === $value ) {
				throw new InvalidArgumentException( sprintf( '--%s needs a value.', $name ) );
			}

			try {
				switch ( $type ) {
					case 'duration':
						$parsed = Duration::parse( $value );
						break;
					case 'bytes':
						$parsed = Bytes::parse( $value );
						break;
					default:
						if ( ! is_int( $value ) && ! ctype_digit( (string) $value ) ) {
							throw new InvalidArgumentException( sprintf( 'Expected a whole number, got "%s".', $value ) );
						}
						$parsed = (int) $value;
				}
			} catch ( InvalidArgumentException $e ) {
				throw new InvalidArgumentException( sprintf( '--%s: %s', $name, $e->getMessage() ), 0, $e );
			}

			$thresholds->{$property} = $parsed;
		}

		$thresholds->validate();

		return $thresholds;
	}

	/**
	 * Supported option names.
	 *
	 * @return string[]
	 */
	public static function option_names(): array {
		return array_keys( self::OPTIONS );
	}

	/**
	 * Checks that paired limits are in order.
	 *
	 * @return void
	 * @throws InvalidArgumentException When they are not.
	 */
	private function validate(): void {
		$pairs = array(
			array( 'overdue_warning', 'overdue_critical', 'overdue' ),
			array( 'option_size_warning', 'option_size_critical', 'option-size' ),
			array( 'as_past_due_warning', 'as_past_due_critical', 'as-past-due' ),
			array( 'as_failed_warning', 'as_failed_critical', 'as-failed' ),
		);

		foreach ( $pairs as $pair ) {
			if ( $this->{$pair[0]} > $this->{$pair[1]} ) {
				throw new InvalidArgumentException( sprintf( '--%1$s-warning must not be greater than --%1$s-critical.', $pair[2] ) );
			}
		}

		if ( $this->as_failed_warning < 1 ) {
			throw new InvalidArgumentException( '--as-failed-warning must be at least 1.' );
		}
	}
}
