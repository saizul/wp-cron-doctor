<?php
/**
 * Accepted findings, so monitoring only alerts on new problems.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Report;

use InvalidArgumentException;

/**
 * A recorded set of known problems.
 *
 * A baseline stores, per check, the severity that was accepted and the identity of the
 * detail rows (hook, arguments or problem text). When it is applied to a later report,
 * a problem finding that is no worse than the recorded severity and has no new detail
 * rows is downgraded to "info", so it no longer changes the exit code. A finding that got
 * worse, or that lists hooks the baseline does not know, keeps its severity and says why.
 */
final class Baseline {

	/**
	 * Value of the "format" key in the JSON document.
	 */
	public const FORMAT = 'wp-cron-doctor-baseline';

	/**
	 * Current document version.
	 */
	public const VERSION = 1;

	/**
	 * Prefix added to the summary of accepted findings.
	 */
	public const ACCEPTED_PREFIX = 'Accepted by baseline: ';

	/**
	 * Detail row fields that identify a row, in key order.
	 */
	private const IDENTITY_FIELDS = array( 'hook', 'args', 'problem' );

	/**
	 * Most new detail rows named in a summary.
	 */
	private const MAX_NAMED = 5;

	/**
	 * Accepted checks: severity, value and item keys.
	 *
	 * @var array<string, array{severity: string, value: int|float|string|null, items: string[]}>
	 */
	private array $checks;

	/**
	 * Creation time.
	 *
	 * @var int
	 */
	private int $created_at;

	/**
	 * Expiry time, or null when the baseline never expires.
	 *
	 * @var int|null
	 */
	private ?int $expires_at;

	/**
	 * Constructor.
	 *
	 * @param array<string, array{severity: string, value: int|float|string|null, items: string[]}> $checks     Accepted checks.
	 * @param int                                                                                   $created_at Creation time.
	 * @param int|null                                                                              $expires_at Expiry time.
	 * @throws InvalidArgumentException For an invalid entry.
	 */
	public function __construct( array $checks, int $created_at, ?int $expires_at = null ) {
		foreach ( $checks as $check => $entry ) {
			if ( '' === (string) $check ) {
				throw new InvalidArgumentException( 'Baseline check names must be non-empty strings.' );
			}
			if ( ! Severity::is_valid( $entry['severity'] ) || ! Severity::at_least( $entry['severity'], Severity::WARNING ) ) {
				throw new InvalidArgumentException( sprintf( 'Baseline entry "%s" must have the severity warning or critical.', (string) $check ) );
			}
		}
		if ( null !== $expires_at && $expires_at <= $created_at ) {
			throw new InvalidArgumentException( 'The baseline must expire after it was created.' );
		}

		ksort( $checks );
		$this->checks     = $checks;
		$this->created_at = $created_at;
		$this->expires_at = $expires_at;
	}

	/**
	 * Records the problems of a report.
	 *
	 * @param Report   $report     Report.
	 * @param int|null $expires_at Expiry time.
	 * @param string[] $only       Only these checks; all problems when empty.
	 * @return self
	 */
	public static function from_report( Report $report, ?int $expires_at = null, array $only = array() ): self {
		$checks = array();
		foreach ( $report->filter( Severity::WARNING ) as $finding ) {
			if ( array() !== $only && ! in_array( $finding->check(), $only, true ) ) {
				continue;
			}
			$checks[ $finding->check() ] = array(
				'severity' => $finding->severity(),
				'value'    => $finding->value(),
				'items'    => self::item_keys( $finding->items() ),
			);
		}

		return new self( $checks, $report->generated_at(), $expires_at );
	}

	/**
	 * Reads a baseline document.
	 *
	 * @param string $json JSON text.
	 * @return self
	 * @throws InvalidArgumentException For an invalid document.
	 */
	public static function from_json( string $json ): self {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			throw new InvalidArgumentException( 'The baseline is not valid JSON.' );
		}
		return self::from_array( $data );
	}

	/**
	 * Reads a decoded baseline document.
	 *
	 * @param array<string, mixed> $data Document.
	 * @return self
	 * @throws InvalidArgumentException For an invalid document.
	 */
	public static function from_array( array $data ): self {
		if ( self::FORMAT !== ( $data['format'] ?? null ) ) {
			throw new InvalidArgumentException( sprintf( 'Not a baseline file: the "format" key must be "%s".', self::FORMAT ) );
		}
		if ( self::VERSION !== ( $data['version'] ?? null ) ) {
			throw new InvalidArgumentException( sprintf( 'Unsupported baseline version; this release reads version %d.', self::VERSION ) );
		}
		if ( ! is_int( $data['created_at'] ?? null ) ) {
			throw new InvalidArgumentException( 'The baseline needs an integer "created_at" time.' );
		}
		$expires = $data['expires_at'] ?? null;
		if ( null !== $expires && ! is_int( $expires ) ) {
			throw new InvalidArgumentException( 'The baseline "expires_at" must be an integer time or null.' );
		}
		if ( ! is_array( $data['checks'] ?? null ) ) {
			throw new InvalidArgumentException( 'The baseline needs a "checks" object.' );
		}

		$checks = array();
		foreach ( $data['checks'] as $check => $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['severity'] ?? null ) ) {
				throw new InvalidArgumentException( sprintf( 'Baseline entry "%s" needs a "severity".', (string) $check ) );
			}
			$items = $entry['items'] ?? array();
			if ( ! is_array( $items ) ) {
				throw new InvalidArgumentException( sprintf( 'Baseline entry "%s" has invalid "items".', (string) $check ) );
			}
			$value = $entry['value'] ?? null;
			if ( null !== $value && ! is_scalar( $value ) ) {
				$value = null;
			}
			$checks[ (string) $check ] = array(
				'severity' => $entry['severity'],
				'value'    => is_bool( $value ) ? (int) $value : $value,
				'items'    => array_values( array_unique( array_map( 'strval', array_filter( $items, 'is_scalar' ) ) ) ),
			);
		}

		return new self( $checks, $data['created_at'], $expires );
	}

	/**
	 * Document for storage.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'format'     => self::FORMAT,
			'version'    => self::VERSION,
			'created_at' => $this->created_at,
			'created'    => gmdate( 'Y-m-d\TH:i:s\Z', $this->created_at ),
			'expires_at' => $this->expires_at,
			'checks'     => (object) $this->checks,
		);
	}

	/**
	 * JSON text for storage.
	 *
	 * @return string
	 */
	public function to_json(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure code, no WordPress available.
		return (string) json_encode( $this->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ) . "\n";
	}

	/**
	 * Accepted check names.
	 *
	 * @return string[]
	 */
	public function checks(): array {
		return array_map( 'strval', array_keys( $this->checks ) );
	}

	/**
	 * Creation time.
	 *
	 * @return int
	 */
	public function created_at(): int {
		return $this->created_at;
	}

	/**
	 * Expiry time, or null.
	 *
	 * @return int|null
	 */
	public function expires_at(): ?int {
		return $this->expires_at;
	}

	/**
	 * Whether the baseline has expired at a time.
	 *
	 * @param int $now Unix time.
	 * @return bool
	 */
	public function is_expired( int $now ): bool {
		return null !== $this->expires_at && $now >= $this->expires_at;
	}

	/**
	 * A new report in which accepted problems are downgraded to "info".
	 *
	 * An expired baseline changes nothing.
	 *
	 * @param Report $report Report.
	 * @return Report
	 */
	public function apply( Report $report ): Report {
		if ( $this->is_expired( $report->generated_at() ) ) {
			return $report;
		}

		$result = new Report( $report->generated_at() );
		foreach ( $report->findings() as $finding ) {
			$result->add( $this->apply_one( $finding ) );
		}
		return $result;
	}

	/**
	 * Accepted checks that are no longer problems, so the baseline can be refreshed.
	 *
	 * @param Report $report Report without the baseline applied.
	 * @return string[]
	 */
	public function resolved( Report $report ): array {
		$resolved = array();
		foreach ( $this->checks() as $check ) {
			$finding = $report->get( $check );
			if ( null !== $finding && ! $finding->is_problem() ) {
				$resolved[] = $check;
			}
		}
		return $resolved;
	}

	/**
	 * Applies the baseline to one finding.
	 *
	 * @param Finding $finding Finding.
	 * @return Finding
	 */
	private function apply_one( Finding $finding ): Finding {
		$entry = $this->checks[ $finding->check() ] ?? null;
		if ( null === $entry || ! $finding->is_problem() ) {
			return $finding;
		}

		if ( Severity::rank( $finding->severity() ) > Severity::rank( $entry['severity'] ) ) {
			return $this->with_summary( $finding, $finding->severity(), $finding->summary() . sprintf( ' (worse than baseline: was %s)', $entry['severity'] ) );
		}

		$new = array_values( array_diff( self::item_keys( $finding->items() ), $entry['items'] ) );
		if ( array() !== $new ) {
			$named = array_slice( $new, 0, self::MAX_NAMED );
			$more  = count( $new ) - count( $named );
			$note  = sprintf( ' (new since baseline: %s%s)', implode( ', ', $named ), $more > 0 ? sprintf( ' and %d more', $more ) : '' );
			return $this->with_summary( $finding, $finding->severity(), $finding->summary() . $note );
		}

		return $this->with_summary( $finding, Severity::INFO, self::ACCEPTED_PREFIX . $finding->summary() );
	}

	/**
	 * Copy of a finding with another severity and summary.
	 *
	 * @param Finding $finding  Finding.
	 * @param string  $severity Severity.
	 * @param string  $summary  Summary.
	 * @return Finding
	 */
	private function with_summary( Finding $finding, string $severity, string $summary ): Finding {
		return new Finding( $finding->check(), $severity, $summary, $finding->value(), $finding->items(), $finding->advice() );
	}

	/**
	 * Stable identity keys of detail rows; rows without identity fields are skipped.
	 *
	 * @param array<int, array<string, mixed>> $items Detail rows.
	 * @return string[]
	 */
	public static function item_keys( array $items ): array {
		$keys = array();
		foreach ( $items as $item ) {
			$key = self::item_key( $item );
			if ( null !== $key ) {
				$keys[ $key ] = true;
			}
		}
		$keys = array_map( 'strval', array_keys( $keys ) );
		sort( $keys );
		return $keys;
	}

	/**
	 * Identity key of one detail row, e.g. "my_hook" or "my_hook [1,2]".
	 *
	 * Volatile fields such as next run times, counts and lateness are left out, so the
	 * key stays the same while a known problem persists.
	 *
	 * @param array<string, mixed> $item Detail row.
	 * @return string|null Null when the row has no identity fields.
	 */
	public static function item_key( array $item ): ?string {
		$parts = array();
		foreach ( self::IDENTITY_FIELDS as $field ) {
			if ( isset( $item[ $field ] ) && is_scalar( $item[ $field ] ) && '' !== (string) $item[ $field ] ) {
				$parts[] = (string) $item[ $field ];
			}
		}
		return array() === $parts ? null : implode( ' ', $parts );
	}
}
