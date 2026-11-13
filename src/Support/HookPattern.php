<?php
/**
 * Hook name patterns.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Support;

/**
 * Matches hook names against patterns where `*` stands for any characters.
 */
final class HookPattern {

	/**
	 * Splits a comma separated option value into patterns.
	 *
	 * @param mixed $value Option value (string, list or null).
	 * @return string[]
	 */
	public static function parse_list( $value ): array {
		if ( null === $value || false === $value || true === $value ) {
			return array();
		}

		$parts = is_array( $value ) ? $value : explode( ',', (string) $value );
		$out   = array();
		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( '' !== $part ) {
				$out[ $part ] = true;
			}
		}

		return array_map( 'strval', array_keys( $out ) );
	}

	/**
	 * Whether a hook matches one of the patterns.
	 *
	 * @param string   $hook     Hook name.
	 * @param string[] $patterns Patterns.
	 * @return bool
	 */
	public static function matches_any( string $hook, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			if ( self::matches( $hook, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a hook matches a pattern (case-sensitive, like WordPress hook names).
	 *
	 * @param string $hook    Hook name.
	 * @param string $pattern Pattern.
	 * @return bool
	 */
	public static function matches( string $hook, string $pattern ): bool {
		if ( false === strpos( $pattern, '*' ) ) {
			return $hook === $pattern;
		}

		$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/s';

		return 1 === preg_match( $regex, $hook );
	}
}
