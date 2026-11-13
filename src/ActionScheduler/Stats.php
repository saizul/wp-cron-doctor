<?php
/**
 * Action Scheduler queue statistics.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\ActionScheduler;

/**
 * Immutable numbers about the Action Scheduler queue.
 */
final class Stats {

	/**
	 * Whether the custom tables were found.
	 *
	 * @var bool
	 */
	private bool $available;

	/**
	 * Why the statistics are not available.
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Action count per status.
	 *
	 * @var array<string, int>
	 */
	private array $counts;

	/**
	 * Pending actions whose scheduled date has passed.
	 *
	 * @var int
	 */
	private int $past_due;

	/**
	 * Scheduled time of the oldest past-due action.
	 *
	 * @var int|null
	 */
	private ?int $oldest_past_due;

	/**
	 * Actions that failed within the window.
	 *
	 * @var int
	 */
	private int $failed_recent;

	/**
	 * Hooks with the most failures: list of hook, count, last_failure.
	 *
	 * @var array<int, array{hook: string, count: int, last_failure: int|null}>
	 */
	private array $top_failing;

	/**
	 * In-progress actions that have been running too long.
	 *
	 * @var int
	 */
	private int $stuck;

	/**
	 * Start time of the oldest stuck action.
	 *
	 * @var int|null
	 */
	private ?int $oldest_stuck;

	/**
	 * Action Scheduler version, when known.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Constructor.
	 *
	 * @param array<string, int>                                                  $counts          Count per status.
	 * @param int                                                                 $past_due        Past-due pending actions.
	 * @param int|null                                                            $oldest_past_due Oldest past-due time.
	 * @param int                                                                 $failed_recent   Recent failures.
	 * @param array<int, array{hook: string, count: int, last_failure: int|null}> $top_failing     Top failing hooks.
	 * @param int                                                                 $stuck           Stuck in-progress actions.
	 * @param int|null                                                            $oldest_stuck    Oldest stuck start time.
	 * @param string                                                              $version         Library version.
	 */
	public function __construct( array $counts, int $past_due = 0, ?int $oldest_past_due = null, int $failed_recent = 0, array $top_failing = array(), int $stuck = 0, ?int $oldest_stuck = null, string $version = '' ) {
		$normalized = array_fill_keys( Schema::statuses(), 0 );
		foreach ( $counts as $status => $count ) {
			$normalized[ (string) $status ] = max( 0, (int) $count );
		}

		$this->available       = true;
		$this->reason          = '';
		$this->counts          = $normalized;
		$this->past_due        = max( 0, $past_due );
		$this->oldest_past_due = $this->past_due > 0 ? $oldest_past_due : null;
		$this->failed_recent   = max( 0, $failed_recent );
		$this->top_failing     = array_values( $top_failing );
		$this->stuck           = max( 0, $stuck );
		$this->oldest_stuck    = $this->stuck > 0 ? $oldest_stuck : null;
		$this->version         = $version;
	}

	/**
	 * Statistics for a site where they could not be collected.
	 *
	 * @param string $reason Why.
	 * @return self
	 */
	public static function unavailable( string $reason ): self {
		$stats            = new self( array() );
		$stats->available = false;
		$stats->reason    = $reason;

		return $stats;
	}

	/**
	 * Builds statistics from database rows (objects or arrays as returned by wpdb).
	 *
	 * @param array<int, mixed> $status_rows  Rows with status and total.
	 * @param mixed             $past_due_row Row with total and oldest.
	 * @param mixed             $failed_row   Row with total.
	 * @param array<int, mixed> $failing_rows Rows with hook, total and last_failure.
	 * @param mixed             $stuck_row    Row with total and oldest.
	 * @param string            $version      Library version.
	 * @return self
	 */
	public static function from_rows( array $status_rows, $past_due_row, $failed_row, array $failing_rows, $stuck_row, string $version = '' ): self {
		$counts = array();
		foreach ( $status_rows as $row ) {
			$row    = (array) $row;
			$status = isset( $row['status'] ) ? (string) $row['status'] : '';
			if ( '' !== $status ) {
				$counts[ $status ] = ( $counts[ $status ] ?? 0 ) + (int) ( $row['total'] ?? 0 );
			}
		}

		$past_due = (array) $past_due_row;
		$failed   = (array) $failed_row;
		$stuck    = (array) $stuck_row;

		$top = array();
		foreach ( $failing_rows as $row ) {
			$row = (array) $row;
			if ( ! isset( $row['hook'] ) ) {
				continue;
			}
			$top[] = array(
				'hook'         => (string) $row['hook'],
				'count'        => (int) ( $row['total'] ?? 0 ),
				'last_failure' => Schema::from_mysql( $row['last_failure'] ?? null ),
			);
		}

		return new self(
			$counts,
			(int) ( $past_due['total'] ?? 0 ),
			Schema::from_mysql( $past_due['oldest'] ?? null ),
			(int) ( $failed['total'] ?? 0 ),
			$top,
			(int) ( $stuck['total'] ?? 0 ),
			Schema::from_mysql( $stuck['oldest'] ?? null ),
			$version
		);
	}

	/**
	 * Whether statistics were collected.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->available;
	}

	/**
	 * Why they were not.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Count for one status.
	 *
	 * @param string $status Status.
	 * @return int
	 */
	public function count( string $status ): int {
		return $this->counts[ $status ] ?? 0;
	}

	/**
	 * Counts per status.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		return $this->counts;
	}

	/**
	 * Past-due pending actions.
	 *
	 * @return int
	 */
	public function past_due(): int {
		return $this->past_due;
	}

	/**
	 * Oldest past-due scheduled time.
	 *
	 * @return int|null
	 */
	public function oldest_past_due(): ?int {
		return $this->oldest_past_due;
	}

	/**
	 * Failures in the window.
	 *
	 * @return int
	 */
	public function failed_recent(): int {
		return $this->failed_recent;
	}

	/**
	 * Top failing hooks.
	 *
	 * @return array<int, array{hook: string, count: int, last_failure: int|null}>
	 */
	public function top_failing(): array {
		return $this->top_failing;
	}

	/**
	 * Stuck in-progress actions.
	 *
	 * @return int
	 */
	public function stuck(): int {
		return $this->stuck;
	}

	/**
	 * Oldest stuck start time.
	 *
	 * @return int|null
	 */
	public function oldest_stuck(): ?int {
		return $this->oldest_stuck;
	}

	/**
	 * Library version.
	 *
	 * @return string
	 */
	public function version(): string {
		return $this->version;
	}
}
