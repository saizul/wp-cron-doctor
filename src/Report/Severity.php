<?php
/**
 * Severity levels and exit codes.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Report;

use InvalidArgumentException;

/**
 * Severity of a finding. Exit codes follow the Nagios plugin convention.
 */
final class Severity {

	public const OK       = 'ok';
	public const INFO     = 'info';
	public const WARNING  = 'warning';
	public const CRITICAL = 'critical';

	/**
	 * Exit code when everything is fine.
	 */
	public const EXIT_OK = 0;

	/**
	 * Exit code for warnings.
	 */
	public const EXIT_WARNING = 1;

	/**
	 * Exit code for critical problems.
	 */
	public const EXIT_CRITICAL = 2;

	/**
	 * Exit code when the check itself could not run.
	 */
	public const EXIT_UNKNOWN = 3;

	/**
	 * Order of the levels.
	 *
	 * @var array<string, int>
	 */
	private const RANK = array(
		self::OK       => 0,
		self::INFO     => 1,
		self::WARNING  => 2,
		self::CRITICAL => 3,
	);

	/**
	 * All levels, lowest first.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array_keys( self::RANK );
	}

	/**
	 * Whether the value is a known level.
	 *
	 * @param string $severity Level.
	 * @return bool
	 */
	public static function is_valid( string $severity ): bool {
		return isset( self::RANK[ $severity ] );
	}

	/**
	 * Numeric rank of a level.
	 *
	 * @param string $severity Level.
	 * @return int
	 * @throws InvalidArgumentException For unknown levels.
	 */
	public static function rank( string $severity ): int {
		if ( ! self::is_valid( $severity ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown severity "%s".', $severity ) );
		}
		return self::RANK[ $severity ];
	}

	/**
	 * The more severe of two levels.
	 *
	 * @param string $a Level.
	 * @param string $b Level.
	 * @return string
	 */
	public static function max( string $a, string $b ): string {
		return self::rank( $a ) >= self::rank( $b ) ? $a : $b;
	}

	/**
	 * Whether $severity is at least $threshold.
	 *
	 * @param string $severity  Level.
	 * @param string $threshold Level.
	 * @return bool
	 */
	public static function at_least( string $severity, string $threshold ): bool {
		return self::rank( $severity ) >= self::rank( $threshold );
	}

	/**
	 * Exit code for a level.
	 *
	 * @param string $severity Level.
	 * @return int
	 */
	public static function exit_code( string $severity ): int {
		switch ( $severity ) {
			case self::CRITICAL:
				return self::EXIT_CRITICAL;
			case self::WARNING:
				return self::EXIT_WARNING;
			default:
				self::rank( $severity );
				return self::EXIT_OK;
		}
	}
}
