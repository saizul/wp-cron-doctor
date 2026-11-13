<?php
/**
 * The result of one check.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Report;

use InvalidArgumentException;

/**
 * Immutable result of one diagnostic check.
 */
final class Finding {

	/**
	 * Check identifier, e.g. "overdue".
	 *
	 * @var string
	 */
	private string $check;

	/**
	 * Severity level.
	 *
	 * @var string
	 */
	private string $severity;

	/**
	 * One line summary.
	 *
	 * @var string
	 */
	private string $summary;

	/**
	 * Main metric (count, bytes, seconds...).
	 *
	 * @var int|float|string|null
	 */
	private $value;

	/**
	 * Detail rows.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $items;

	/**
	 * Suggested next step.
	 *
	 * @var string
	 */
	private string $advice;

	/**
	 * Constructor.
	 *
	 * @param string                           $check    Check identifier.
	 * @param string                           $severity Severity level.
	 * @param string                           $summary  One line summary.
	 * @param int|float|string|null            $value    Main metric.
	 * @param array<int, array<string, mixed>> $items    Detail rows.
	 * @param string                           $advice   Suggested next step.
	 * @throws InvalidArgumentException For an unknown severity.
	 */
	public function __construct( string $check, string $severity, string $summary, $value = null, array $items = array(), string $advice = '' ) {
		if ( ! Severity::is_valid( $severity ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown severity "%s".', $severity ) );
		}

		$this->check    = $check;
		$this->severity = $severity;
		$this->summary  = $summary;
		$this->value    = $value;
		$this->items    = array_values( $items );
		$this->advice   = $advice;
	}

	/**
	 * Check identifier.
	 *
	 * @return string
	 */
	public function check(): string {
		return $this->check;
	}

	/**
	 * Severity level.
	 *
	 * @return string
	 */
	public function severity(): string {
		return $this->severity;
	}

	/**
	 * Summary.
	 *
	 * @return string
	 */
	public function summary(): string {
		return $this->summary;
	}

	/**
	 * Main metric.
	 *
	 * @return int|float|string|null
	 */
	public function value() {
		return $this->value;
	}

	/**
	 * Detail rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * Suggested next step.
	 *
	 * @return string
	 */
	public function advice(): string {
		return $this->advice;
	}

	/**
	 * Whether the check needs attention.
	 *
	 * @return bool
	 */
	public function is_problem(): bool {
		return Severity::at_least( $this->severity, Severity::WARNING );
	}

	/**
	 * Summary row.
	 *
	 * @return array<string, mixed>
	 */
	public function to_row(): array {
		return array(
			'check'   => $this->check,
			'status'  => $this->severity,
			'value'   => null === $this->value ? '' : $this->value,
			'summary' => $this->summary,
			'advice'  => $this->advice,
			'items'   => count( $this->items ),
			'details' => $this->items,
		);
	}
}
