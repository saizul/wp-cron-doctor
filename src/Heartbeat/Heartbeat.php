<?php
/**
 * Last-run bookkeeping.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Heartbeat;

/**
 * Pure helpers for the "last cron run" record.
 *
 * WordPress does not store when cron last ran. This package records it in its own
 * option when a cron request starts (plugin mode) and after `wp cron event run --due-now`
 * (package mode). The I/O lives in includes/; the decisions live here.
 */
final class Heartbeat {

	/**
	 * Option that holds the record.
	 */
	public const OPTION = 'wp_cron_doctor_last_run';

	/**
	 * Minimum seconds between two writes.
	 */
	public const THROTTLE = 60;

	public const SOURCE_SYSTEM    = 'system';
	public const SOURCE_LOOPBACK  = 'loopback';
	public const SOURCE_ALTERNATE = 'alternate';
	public const SOURCE_WP_CLI    = 'wp-cli';
	public const SOURCE_LOCK      = 'doing_cron lock';

	/**
	 * Reads a stored record.
	 *
	 * @param mixed $raw Option value.
	 * @return array{time: int, source: string}|null
	 */
	public static function decode( $raw ): ?array {
		if ( ! is_array( $raw ) || ! isset( $raw['time'] ) || ! is_numeric( $raw['time'] ) ) {
			return null;
		}

		$time = (int) $raw['time'];
		if ( $time <= 0 ) {
			return null;
		}

		$source = isset( $raw['source'] ) && is_string( $raw['source'] ) && '' !== $raw['source'] ? $raw['source'] : self::SOURCE_SYSTEM;

		return array(
			'time'   => $time,
			'source' => $source,
		);
	}

	/**
	 * Builds a record for storage.
	 *
	 * @param int    $time   Unix time.
	 * @param string $source Source.
	 * @return array{time: int, source: string}
	 */
	public static function encode( int $time, string $source ): array {
		return array(
			'time'   => $time,
			'source' => $source,
		);
	}

	/**
	 * Whether a new record should be written (avoids a database write on every request).
	 *
	 * @param mixed  $previous Stored option value.
	 * @param int    $now      Current time.
	 * @param string $source   Source of the current run.
	 * @param int    $throttle Minimum seconds between writes.
	 * @return bool
	 */
	public static function should_write( $previous, int $now, string $source, int $throttle = self::THROTTLE ): bool {
		$record = self::decode( $previous );
		if ( null === $record ) {
			return true;
		}

		if ( $record['source'] !== $source ) {
			return true;
		}

		return ( $now - $record['time'] ) >= $throttle || $record['time'] > $now;
	}

	/**
	 * Classifies a request to wp-cron.php.
	 *
	 * WordPress adds `doing_wp_cron` to the URL when it spawns cron itself (loopback) and
	 * to the redirect URL with ALTERNATE_WP_CRON; a request without it comes from a
	 * system cron job or another external trigger.
	 *
	 * @param bool $has_doing_wp_cron Whether the `doing_wp_cron` query arg is present.
	 * @param bool $alternate         Whether ALTERNATE_WP_CRON is on.
	 * @param bool $is_cli            Whether the request runs in WP-CLI.
	 * @return string
	 */
	public static function classify( bool $has_doing_wp_cron, bool $alternate, bool $is_cli ): string {
		if ( $is_cli ) {
			return self::SOURCE_WP_CLI;
		}
		if ( ! $has_doing_wp_cron ) {
			return self::SOURCE_SYSTEM;
		}
		return $alternate ? self::SOURCE_ALTERNATE : self::SOURCE_LOOPBACK;
	}

	/**
	 * Whether a finished `wp cron event run` invocation ran the due events.
	 *
	 * @param array<string, mixed> $assoc_args Command options.
	 * @return bool
	 */
	public static function is_due_now_run( array $assoc_args ): bool {
		return ! empty( $assoc_args['due-now'] ) || ! empty( $assoc_args['all'] );
	}

	/**
	 * Picks the most recent evidence of a cron run.
	 *
	 * @param array{time: int, source: string}|null $record Stored record.
	 * @param float|null                            $lock   Value of the `doing_cron` transient.
	 * @param int                                   $now    Current time.
	 * @return array{time: int, source: string}|null
	 */
	public static function latest( ?array $record, ?float $lock, int $now ): ?array {
		$candidates = array();

		if ( null !== $record ) {
			$candidates[] = $record;
		}

		// WordPress resets locks that are more than 10 minutes in the future; ignore those too.
		if ( null !== $lock && $lock > 0 && $lock <= $now + 600 ) {
			$candidates[] = self::encode( (int) floor( $lock ), self::SOURCE_LOCK );
		}

		$latest = null;
		foreach ( $candidates as $candidate ) {
			if ( null === $latest || $candidate['time'] > $latest['time'] ) {
				$latest = $candidate;
			}
		}

		return $latest;
	}
}
