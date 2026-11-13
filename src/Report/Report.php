<?php
/**
 * A collection of findings.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Report;

use InvalidArgumentException;

/**
 * Ordered list of findings with summary helpers and exit code logic.
 */
final class Report {

	/**
	 * Value for --fail-on that never fails.
	 */
	public const FAIL_NEVER = 'never';

	/**
	 * Findings keyed by check.
	 *
	 * @var array<string, Finding>
	 */
	private array $findings = array();

	/**
	 * Time the report was made.
	 *
	 * @var int
	 */
	private int $generated_at;

	/**
	 * Constructor.
	 *
	 * @param int $generated_at Unix time.
	 */
	public function __construct( int $generated_at ) {
		$this->generated_at = $generated_at;
	}

	/**
	 * Adds a finding, replacing an earlier one of the same check.
	 *
	 * @param Finding $finding Finding.
	 * @return void
	 */
	public function add( Finding $finding ): void {
		$this->findings[ $finding->check() ] = $finding;
	}

	/**
	 * All findings in insertion order.
	 *
	 * @return Finding[]
	 */
	public function findings(): array {
		return array_values( $this->findings );
	}

	/**
	 * One finding.
	 *
	 * @param string $check Check identifier.
	 * @return Finding|null
	 */
	public function get( string $check ): ?Finding {
		return $this->findings[ $check ] ?? null;
	}

	/**
	 * Check identifiers.
	 *
	 * @return string[]
	 */
	public function checks(): array {
		return array_map( 'strval', array_keys( $this->findings ) );
	}

	/**
	 * Generation time.
	 *
	 * @return int
	 */
	public function generated_at(): int {
		return $this->generated_at;
	}

	/**
	 * Most severe level in the report.
	 *
	 * @return string
	 */
	public function worst(): string {
		$worst = Severity::OK;
		foreach ( $this->findings as $finding ) {
			$worst = Severity::max( $worst, $finding->severity() );
		}
		return $worst;
	}

	/**
	 * Overall status: like worst(), but "info" counts as "ok".
	 *
	 * @return string
	 */
	public function status(): string {
		$worst = $this->worst();
		return Severity::INFO === $worst ? Severity::OK : $worst;
	}

	/**
	 * Number of findings per level.
	 *
	 * @return array<string, int>
	 */
	public function totals(): array {
		$totals = array_fill_keys( Severity::all(), 0 );
		foreach ( $this->findings as $finding ) {
			++$totals[ $finding->severity() ];
		}
		return $totals;
	}

	/**
	 * Findings at or above a level.
	 *
	 * @param string $minimum Lowest level to keep.
	 * @return Finding[]
	 */
	public function filter( string $minimum ): array {
		return array_values(
			array_filter(
				$this->findings,
				static function ( Finding $finding ) use ( $minimum ): bool {
					return Severity::at_least( $finding->severity(), $minimum );
				}
			)
		);
	}

	/**
	 * Exit code for monitoring.
	 *
	 * The code is that of the worst level (0 ok, 1 warning, 2 critical) when that level
	 * is at least $fail_on; otherwise 0.
	 *
	 * @param string $fail_on "warning", "critical" or "never".
	 * @return int
	 * @throws InvalidArgumentException For an invalid threshold.
	 */
	public function exit_code( string $fail_on = Severity::WARNING ): int {
		if ( self::FAIL_NEVER === $fail_on ) {
			return Severity::EXIT_OK;
		}

		if ( ! in_array( $fail_on, array( Severity::WARNING, Severity::CRITICAL ), true ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid --fail-on value "%s". Use warning, critical or never.', $fail_on ) );
		}

		$worst = $this->worst();
		if ( ! Severity::at_least( $worst, $fail_on ) ) {
			return Severity::EXIT_OK;
		}

		return Severity::exit_code( $worst );
	}

	/**
	 * Summary rows for output formatters.
	 *
	 * @param string $minimum Lowest level to include.
	 * @return array<int, array<string, mixed>>
	 */
	public function rows( string $minimum = Severity::OK ): array {
		return array_map(
			static function ( Finding $finding ): array {
				return $finding->to_row();
			},
			$this->filter( $minimum )
		);
	}

	/**
	 * Single status line in the Nagios plugin format, with performance data.
	 *
	 * Example: "CRON WARNING - 2 warnings: overdue, duplicates | overdue=3 duplicates=1".
	 *
	 * @return string
	 */
	public function status_line(): string {
		$problems = $this->filter( Severity::WARNING );
		$label    = strtoupper( $this->status() );

		if ( array() === $problems ) {
			$text = 'all checks passed';
		} else {
			$totals = $this->totals();
			$parts  = array();
			if ( $totals[ Severity::CRITICAL ] > 0 ) {
				$parts[] = $totals[ Severity::CRITICAL ] . ' critical';
			}
			if ( $totals[ Severity::WARNING ] > 0 ) {
				$parts[] = $totals[ Severity::WARNING ] . ( 1 === $totals[ Severity::WARNING ] ? ' warning' : ' warnings' );
			}
			$names = array_map(
				static function ( Finding $finding ): string {
					return $finding->check();
				},
				$problems
			);
			$text  = implode( ', ', $parts ) . ': ' . implode( ', ', $names );
		}

		$perf = array();
		foreach ( $this->findings as $finding ) {
			$value = $finding->value();
			if ( is_int( $value ) || is_float( $value ) ) {
				$perf[] = $finding->check() . '=' . $value;
			}
		}

		return 'CRON ' . $label . ' - ' . $text . ( array() === $perf ? '' : ' | ' . implode( ' ', $perf ) );
	}
}
