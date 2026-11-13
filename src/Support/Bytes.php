<?php
/**
 * Parsing and formatting of byte sizes.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Support;

use InvalidArgumentException;

/**
 * Converts between "256K" style strings and bytes (binary multiples).
 */
final class Bytes {

	/**
	 * Parses "512", "256K", "256KB", "1M", "1.5MB" or "1G".
	 *
	 * @param string|int $value Size.
	 * @return int Bytes.
	 * @throws InvalidArgumentException When the value cannot be read.
	 */
	public static function parse( $value ): int {
		if ( is_int( $value ) ) {
			if ( $value < 0 ) {
				throw new InvalidArgumentException( 'A size cannot be negative.' );
			}
			return $value;
		}

		$text = strtoupper( str_replace( ' ', '', (string) $value ) );
		if ( ! preg_match( '/^(\d+(?:\.\d+)?)([KMG]I?B?|B)?$/', $text, $match ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid size "%s". Use for example 512, 256K, 1M or 1.5MB.', $value ) );
		}

		$number = (float) $match[1];
		$unit   = isset( $match[2] ) ? $match[2][0] : 'B';
		$powers = array(
			'B' => 0,
			'K' => 1,
			'M' => 2,
			'G' => 3,
		);

		return (int) round( $number * ( 1024 ** $powers[ $unit ] ) );
	}

	/**
	 * Formats bytes as "12.3 KB".
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	public static function human( int $bytes ): string {
		$units = array( 'B', 'KB', 'MB', 'GB' );
		$size  = (float) max( 0, $bytes );
		$index = 0;
		$last  = count( $units ) - 1;

		while ( $size >= 1024 && $index < $last ) {
			$size /= 1024;
			++$index;
		}

		if ( 0 === $index ) {
			return sprintf( '%d B', (int) $size );
		}

		return sprintf( '%.1f %s', $size, $units[ $index ] );
	}
}
