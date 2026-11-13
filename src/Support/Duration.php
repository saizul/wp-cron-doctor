<?php
/**
 * Parsing and formatting of time spans.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Support;

use InvalidArgumentException;

/**
 * Converts between "1h30m" style strings and seconds.
 */
final class Duration {

	/**
	 * Seconds per unit, longest first.
	 *
	 * @var array<string, int>
	 */
	private const UNITS = array(
		'w' => 604800,
		'd' => 86400,
		'h' => 3600,
		'm' => 60,
		's' => 1,
	);

	/**
	 * Parses a duration such as "90", "90s", "15m", "2h", "1d" or "1h30m".
	 *
	 * A bare number is read as seconds.
	 *
	 * @param string|int $value Duration.
	 * @return int Seconds.
	 * @throws InvalidArgumentException When the value cannot be read.
	 */
	public static function parse( $value ): int {
		if ( is_int( $value ) ) {
			if ( $value < 0 ) {
				throw new InvalidArgumentException( 'A duration cannot be negative.' );
			}
			return $value;
		}

		$text = strtolower( trim( (string) $value ) );
		if ( '' === $text ) {
			throw new InvalidArgumentException( 'A duration cannot be empty.' );
		}

		if ( ctype_digit( $text ) ) {
			return (int) $text;
		}

		if ( ! preg_match( '/^(?:\d+\s*[wdhms]\s*)+$/', $text ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid duration "%s". Use for example 90, 45s, 15m, 2h, 1d or 1h30m.', $value ) );
		}

		preg_match_all( '/(\d+)\s*([wdhms])/', $text, $matches, PREG_SET_ORDER );

		$seconds = 0;
		foreach ( $matches as $match ) {
			$seconds += (int) $match[1] * self::UNITS[ $match[2] ];
		}

		return $seconds;
	}

	/**
	 * Formats seconds as a short human readable string, e.g. "2h 5m".
	 *
	 * @param int|float $seconds Seconds, may be negative.
	 * @param int       $parts   Maximum number of units to show.
	 * @return string
	 */
	public static function human( $seconds, int $parts = 2 ): string {
		$seconds = (int) round( (float) $seconds );
		$sign    = $seconds < 0 ? '-' : '';
		$seconds = abs( $seconds );

		if ( 0 === $seconds ) {
			return '0s';
		}

		$out = array();
		foreach ( self::UNITS as $unit => $size ) {
			if ( $seconds >= $size ) {
				$count    = intdiv( $seconds, $size );
				$seconds -= $count * $size;
				$out[]    = $count . $unit;
			}
			if ( count( $out ) >= max( 1, $parts ) ) {
				break;
			}
		}

		return $sign . implode( ' ', $out );
	}
}
