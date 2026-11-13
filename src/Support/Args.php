<?php
/**
 * Helpers for cron event arguments.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Support;

/**
 * Computes event keys the way WordPress core does and formats arguments for display.
 */
final class Args {

	/**
	 * The key WordPress uses for an event inside the cron array.
	 *
	 * Mirrors `md5( serialize( $event->args ) )` in wp_schedule_event().
	 *
	 * @param array<mixed> $args Event arguments.
	 * @return string
	 */
	public static function key( array $args ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Must match the key WordPress core computes.
		return md5( serialize( $args ) );
	}

	/**
	 * Short single-line representation of the arguments.
	 *
	 * @param array<mixed> $args       Event arguments.
	 * @param int          $max_length Truncate after this many characters (0 = never).
	 * @return string
	 */
	public static function describe( array $args, int $max_length = 80 ): string {
		if ( array() === $args ) {
			return '[]';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure code, no WordPress available.
		$json = json_encode( $args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
		if ( false === $json ) {
			$json = '[unprintable]';
		}

		if ( $max_length > 3 && strlen( $json ) > $max_length ) {
			$json = substr( $json, 0, $max_length - 3 ) . '...';
		}

		return $json;
	}
}
