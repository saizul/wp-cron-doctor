<?php
/**
 * Read-only model of the `cron` option.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Cron;

use Saizul\CronDoctor\Support\Args;

/**
 * Parses the raw `cron` option into Event objects.
 *
 * The stored structure (version 2, used since WordPress 2.1) is:
 *
 *     array(
 *         1700000000 => array(
 *             'hook_name' => array(
 *                 md5( serialize( $args ) ) => array(
 *                     'schedule' => 'hourly',  // Or false for a single event.
 *                     'args'     => array(),
 *                     'interval' => 3600,      // Only for recurring events.
 *                 ),
 *             ),
 *         ),
 *         'version' => 2,
 *     )
 *
 * Like _get_cron_array(), a value that is not an array is read as "no events" and an
 * array without a `version` key is upgraded the way _upgrade_cron_array() does it.
 * Unlike core, malformed entries are not silently used: they are skipped and reported.
 */
final class CronArray {

	/**
	 * Events sorted by timestamp, then hook.
	 *
	 * @var Event[]
	 */
	private array $events = array();

	/**
	 * Structural problems found while parsing.
	 *
	 * @var string[]
	 */
	private array $problems = array();

	/**
	 * Value of the `version` key, null when missing.
	 *
	 * @var int|null
	 */
	private ?int $version = null;

	/**
	 * Whether the raw value was a legacy (version-less) array.
	 *
	 * @var bool
	 */
	private bool $legacy = false;

	/**
	 * Use from_option() or from_events().
	 */
	private function __construct() {}

	/**
	 * Builds the model from the raw option value.
	 *
	 * @param mixed $raw Value of get_option( 'cron' ).
	 * @return self
	 */
	public static function from_option( $raw ): self {
		$model = new self();

		if ( ! is_array( $raw ) ) {
			if ( false !== $raw && null !== $raw && '' !== $raw ) {
				$model->problems[] = sprintf( 'The cron option is a %s, not an array; WordPress treats it as empty.', gettype( $raw ) );
			}
			return $model;
		}

		if ( array_key_exists( 'version', $raw ) ) {
			$model->version = is_numeric( $raw['version'] ) ? (int) $raw['version'] : null;
			if ( 2 !== $model->version ) {
				$model->problems[] = sprintf( 'Unexpected cron array version "%s" (WordPress uses 2).', is_scalar( $raw['version'] ) ? (string) $raw['version'] : gettype( $raw['version'] ) );
			}
			unset( $raw['version'] );
		} else {
			$model->legacy = array() !== $raw;
			$raw           = self::upgrade_legacy( $raw );
		}

		foreach ( $raw as $timestamp => $hooks ) {
			$model->parse_timestamp( $timestamp, $hooks );
		}

		$model->sort();

		return $model;
	}

	/**
	 * Builds the model from events (mainly for tests and fixers).
	 *
	 * @param Event[] $events Events.
	 * @return self
	 */
	public static function from_events( array $events ): self {
		$model          = new self();
		$model->version = 2;
		$model->events  = array_values( $events );
		$model->sort();

		return $model;
	}

	/**
	 * Converts a pre-2.1 array (no key level) like _upgrade_cron_array() does.
	 *
	 * @param array<mixed> $raw Legacy array.
	 * @return array<mixed>
	 */
	private static function upgrade_legacy( array $raw ): array {
		$upgraded = array();

		foreach ( $raw as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				$upgraded[ $timestamp ] = $hooks;
				continue;
			}
			foreach ( $hooks as $hook => $event ) {
				$args = ( is_array( $event ) && isset( $event['args'] ) && is_array( $event['args'] ) ) ? $event['args'] : array();

				$upgraded[ $timestamp ][ $hook ][ Args::key( $args ) ] = $event;
			}
		}

		return $upgraded;
	}

	/**
	 * Parses the hooks of one timestamp.
	 *
	 * @param int|string $timestamp Array key.
	 * @param mixed      $hooks     Hooks for that time.
	 * @return void
	 */
	private function parse_timestamp( $timestamp, $hooks ): void {
		if ( ! is_int( $timestamp ) && ! ctype_digit( (string) $timestamp ) ) {
			$this->problems[] = sprintf( 'Unexpected key "%s" in the cron array.', $timestamp );
			return;
		}

		$time = (int) $timestamp;
		if ( $time <= 0 ) {
			$this->problems[] = sprintf( 'Invalid timestamp %d in the cron array.', $time );
			return;
		}

		if ( ! is_array( $hooks ) ) {
			$this->problems[] = sprintf( 'Timestamp %d holds a %s instead of a list of hooks.', $time, gettype( $hooks ) );
			return;
		}

		if ( array() === $hooks ) {
			$this->problems[] = sprintf( 'Timestamp %d has no hooks (left-over empty entry).', $time );
			return;
		}

		foreach ( $hooks as $hook => $events ) {
			$hook = (string) $hook;
			if ( '' === $hook ) {
				$this->problems[] = sprintf( 'Timestamp %d has an event with an empty hook name.', $time );
				continue;
			}
			if ( ! is_array( $events ) ) {
				$this->problems[] = sprintf( 'Hook "%s" at %d is a %s instead of a list of events.', $hook, $time, gettype( $events ) );
				continue;
			}
			foreach ( $events as $key => $data ) {
				$this->parse_event( $time, $hook, (string) $key, $data );
			}
		}
	}

	/**
	 * Parses one event entry.
	 *
	 * @param int    $time Timestamp.
	 * @param string $hook Hook name.
	 * @param string $key  Stored key.
	 * @param mixed  $data Event data.
	 * @return void
	 */
	private function parse_event( int $time, string $hook, string $key, $data ): void {
		if ( ! is_array( $data ) ) {
			$this->problems[] = sprintf( 'Event "%s" at %d is a %s instead of an array.', $hook, $time, gettype( $data ) );
			return;
		}

		$args = array_key_exists( 'args', $data ) ? $data['args'] : array();
		if ( ! is_array( $args ) ) {
			$this->problems[] = sprintf( 'Event "%s" at %d has arguments that are not an array.', $hook, $time );
			return;
		}

		$schedule = isset( $data['schedule'] ) && is_string( $data['schedule'] ) ? $data['schedule'] : false;
		$interval = isset( $data['interval'] ) && is_numeric( $data['interval'] ) ? (int) $data['interval'] : null;

		if ( false !== $schedule && null === $interval ) {
			$this->problems[] = sprintf( 'Recurring event "%s" at %d has no stored interval.', $hook, $time );
		}

		$event = new Event( $time, $hook, $args, $schedule, $interval, $key );
		if ( ! $event->has_valid_key() ) {
			$this->problems[] = sprintf( 'Event "%s" at %d is stored under key %s but its arguments hash to %s; wp_unschedule_event() cannot remove it.', $hook, $time, $key, Args::key( $args ) );
		}

		$this->events[] = $event;
	}

	/**
	 * Sorts events by timestamp, then hook, keeping the original order otherwise.
	 *
	 * @return void
	 */
	private function sort(): void {
		$indexed = array();
		foreach ( $this->events as $index => $event ) {
			$indexed[] = array( $index, $event );
		}

		usort(
			$indexed,
			static function ( array $a, array $b ): int {
				return array( $a[1]->timestamp(), $a[1]->hook(), $a[0] ) <=> array( $b[1]->timestamp(), $b[1]->hook(), $b[0] );
			}
		);

		$this->events = array_map(
			static function ( array $pair ): Event {
				return $pair[1];
			},
			$indexed
		);
	}

	/**
	 * All events.
	 *
	 * @return Event[]
	 */
	public function events(): array {
		return $this->events;
	}

	/**
	 * Number of events.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->events );
	}

	/**
	 * Unique hook names, sorted.
	 *
	 * @return string[]
	 */
	public function hooks(): array {
		$hooks = array();
		foreach ( $this->events as $event ) {
			$hooks[ $event->hook() ] = true;
		}
		$names = array_map( 'strval', array_keys( $hooks ) );
		sort( $names, SORT_STRING );

		return $names;
	}

	/**
	 * Events of one hook.
	 *
	 * @param string $hook Hook name.
	 * @return Event[]
	 */
	public function for_hook( string $hook ): array {
		return array_values(
			array_filter(
				$this->events,
				static function ( Event $event ) use ( $hook ): bool {
					return $event->hook() === $hook;
				}
			)
		);
	}

	/**
	 * Events due at or before the given time, like wp_get_ready_cron_jobs().
	 *
	 * @param int $now Current time.
	 * @return Event[]
	 */
	public function ready( int $now ): array {
		$ready = array();
		foreach ( $this->events as $event ) {
			if ( $event->timestamp() > $now ) {
				break;
			}
			$ready[] = $event;
		}

		return $ready;
	}

	/**
	 * Structural problems.
	 *
	 * @return string[]
	 */
	public function problems(): array {
		return $this->problems;
	}

	/**
	 * Stored version, null when missing.
	 *
	 * @return int|null
	 */
	public function version(): ?int {
		return $this->version;
	}

	/**
	 * Whether the raw array had no version key and was upgraded.
	 *
	 * @return bool
	 */
	public function is_legacy(): bool {
		return $this->legacy;
	}
}
