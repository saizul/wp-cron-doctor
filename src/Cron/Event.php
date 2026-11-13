<?php
/**
 * A single scheduled WP-Cron event.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Cron;

use Saizul\CronDoctor\Support\Args;
use Saizul\CronDoctor\Support\Duration;

/**
 * Immutable value object for one entry of the `cron` option.
 */
final class Event {

	/**
	 * Unix timestamp (UTC) the event is due.
	 *
	 * @var int
	 */
	private int $timestamp;

	/**
	 * Hook name.
	 *
	 * @var string
	 */
	private string $hook;

	/**
	 * Key inside the cron array, normally md5( serialize( $args ) ).
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Recurrence name or false for a single event.
	 *
	 * @var string|false
	 */
	private $schedule;

	/**
	 * Arguments passed to the hook.
	 *
	 * @var array<mixed>
	 */
	private array $args;

	/**
	 * Stored interval in seconds, null for single events.
	 *
	 * @var int|null
	 */
	private ?int $interval;

	/**
	 * Constructor.
	 *
	 * @param int          $timestamp Due time.
	 * @param string       $hook      Hook name.
	 * @param array<mixed> $args      Arguments.
	 * @param string|false $schedule  Recurrence name or false.
	 * @param int|null     $interval  Interval in seconds.
	 * @param string|null  $key       Stored key, computed from the arguments when null.
	 */
	public function __construct( int $timestamp, string $hook, array $args = array(), $schedule = false, ?int $interval = null, ?string $key = null ) {
		$this->timestamp = $timestamp;
		$this->hook      = $hook;
		$this->args      = $args;
		$this->schedule  = ( is_string( $schedule ) && '' !== $schedule ) ? $schedule : false;
		$this->interval  = ( null !== $interval && $interval > 0 ) ? $interval : null;
		$this->key       = null === $key ? Args::key( $args ) : $key;
	}

	/**
	 * Due time.
	 *
	 * @return int
	 */
	public function timestamp(): int {
		return $this->timestamp;
	}

	/**
	 * Hook name.
	 *
	 * @return string
	 */
	public function hook(): string {
		return $this->hook;
	}

	/**
	 * Arguments.
	 *
	 * @return array<mixed>
	 */
	public function args(): array {
		return $this->args;
	}

	/**
	 * Key inside the cron array.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * Recurrence name, or false for a single event.
	 *
	 * @return string|false
	 */
	public function schedule() {
		return $this->schedule;
	}

	/**
	 * Stored interval in seconds.
	 *
	 * @return int|null
	 */
	public function interval(): ?int {
		return $this->interval;
	}

	/**
	 * Whether the event repeats.
	 *
	 * @return bool
	 */
	public function is_recurring(): bool {
		return false !== $this->schedule;
	}

	/**
	 * Whether the stored key matches the one WordPress computes from the arguments.
	 *
	 * Events with a mismatched key cannot be removed with wp_unschedule_event().
	 *
	 * @return bool
	 */
	public function has_valid_key(): bool {
		return Args::key( $this->args ) === $this->key;
	}

	/**
	 * Identity of the hook and arguments, used to find duplicates.
	 *
	 * @return string
	 */
	public function signature(): string {
		return $this->hook . '|' . $this->key;
	}

	/**
	 * Seconds the event is late at the given time (negative when it is in the future).
	 *
	 * @param int $now Current time.
	 * @return int
	 */
	public function lateness( int $now ): int {
		return $now - $this->timestamp;
	}

	/**
	 * Row for tables and machine readable output.
	 *
	 * @param int $now Current time.
	 * @return array<string, mixed>
	 */
	public function to_row( int $now ): array {
		$late = $this->lateness( $now );

		return array(
			'hook'      => $this->hook,
			'args'      => Args::describe( $this->args ),
			'timestamp' => $this->timestamp,
			'due'       => gmdate( 'Y-m-d H:i:s', $this->timestamp ),
			'late'      => $late > 0 ? Duration::human( $late ) : '',
			'late_secs' => max( 0, $late ),
			'schedule'  => false === $this->schedule ? 'single' : $this->schedule,
			'interval'  => null === $this->interval ? '' : $this->interval,
		);
	}
}
