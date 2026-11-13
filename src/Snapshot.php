<?php
/**
 * Everything the checks need, collected from WordPress beforehand.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor;

use InvalidArgumentException;
use Saizul\CronDoctor\ActionScheduler\Stats;
use Saizul\CronDoctor\Cron\CronArray;
use Saizul\CronDoctor\Loopback\LoopbackResult;

/**
 * Plain data container. The checks only read this object, so they run without WordPress.
 */
final class Snapshot {

	/**
	 * Current Unix time.
	 *
	 * @var int
	 */
	public int $now;

	/**
	 * Parsed cron option.
	 *
	 * @var CronArray
	 */
	public CronArray $cron;

	/**
	 * Hook name => whether at least one callback is attached.
	 *
	 * @var array<string, bool>
	 */
	public array $callbacks = array();

	/**
	 * Registered recurrences: name => interval in seconds.
	 *
	 * @var array<string, int>
	 */
	public array $schedules = array();

	/**
	 * Length of the serialized cron option in bytes.
	 *
	 * @var int
	 */
	public int $option_bytes = 0;

	/**
	 * Value of DISABLE_WP_CRON.
	 *
	 * @var bool
	 */
	public bool $disable_wp_cron = false;

	/**
	 * Value of ALTERNATE_WP_CRON.
	 *
	 * @var bool
	 */
	public bool $alternate_wp_cron = false;

	/**
	 * Last recorded cron run (Unix time), null when unknown.
	 *
	 * @var int|null
	 */
	public ?int $last_run = null;

	/**
	 * How the last run was started: "system", "wp-cli", "loopback", "alternate".
	 *
	 * @var string|null
	 */
	public ?string $last_run_source = null;

	/**
	 * Value of the `doing_cron` transient (Unix time with microseconds), null when absent.
	 *
	 * @var float|null
	 */
	public ?float $cron_lock = null;

	/**
	 * Loopback probe result, null when skipped.
	 *
	 * @var LoopbackResult|null
	 */
	public ?LoopbackResult $loopback = null;

	/**
	 * Action Scheduler statistics, null when skipped.
	 *
	 * @var Stats|null
	 */
	public ?Stats $action_scheduler = null;

	/**
	 * Hooks never reported as orphaned.
	 *
	 * @var string[]
	 */
	public array $ignored_hooks = array();

	/**
	 * Constructor.
	 *
	 * @param int       $now  Current time.
	 * @param CronArray $cron Parsed cron option.
	 */
	public function __construct( int $now, CronArray $cron ) {
		$this->now  = $now;
		$this->cron = $cron;
	}

	/**
	 * Builds a snapshot from an array of values (keys are the property names).
	 *
	 * @param array<string, mixed> $values Values; `now` and `cron` are required.
	 * @return self
	 * @throws InvalidArgumentException For unknown keys or missing required values.
	 */
	public static function from_array( array $values ): self {
		if ( ! isset( $values['now'], $values['cron'] ) ) {
			throw new InvalidArgumentException( 'A snapshot needs "now" and "cron".' );
		}

		$cron = $values['cron'] instanceof CronArray ? $values['cron'] : CronArray::from_option( $values['cron'] );
		$self = new self( (int) $values['now'], $cron );
		unset( $values['now'], $values['cron'] );

		foreach ( $values as $key => $value ) {
			if ( ! property_exists( $self, $key ) ) {
				throw new InvalidArgumentException( sprintf( 'Unknown snapshot field "%s".', $key ) );
			}
			$self->{$key} = $value;
		}

		return $self;
	}

	/**
	 * Whether a hook has a callback. Hooks that were not looked up count as registered.
	 *
	 * @param string $hook Hook name.
	 * @return bool
	 */
	public function has_callback( string $hook ): bool {
		return $this->callbacks[ $hook ] ?? true;
	}
}
