<?php
/**
 * Reads Action Scheduler statistics from the database.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\WP;

use Saizul\CronDoctor\ActionScheduler\Schema;
use Saizul\CronDoctor\ActionScheduler\Stats;
use Saizul\CronDoctor\Report\Thresholds;

/**
 * Runs the read-only queries built by Schema.
 */
final class ActionSchedulerSource {

	/**
	 * Database object.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Schema.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * Constructor.
	 *
	 * @param \wpdb|null $db Database; the global $wpdb when null.
	 */
	public function __construct( $db = null ) {
		if ( null === $db ) {
			global $wpdb;
			$db = $wpdb;
		}
		$this->db     = $db;
		$this->schema = new Schema( (string) $db->prefix );
	}

	/**
	 * Collects statistics.
	 *
	 * @param int        $now        Current time.
	 * @param Thresholds $thresholds Limits (failure window, stuck age).
	 * @return Stats
	 */
	public function collect( int $now, Thresholds $thresholds ): Stats {
		if ( ! $this->table_exists() ) {
			if ( class_exists( 'ActionScheduler' ) ) {
				return Stats::unavailable( 'Action Scheduler is loaded but its custom tables were not found (an old version or the posts data store).' );
			}
			return Stats::unavailable( 'not installed (no ' . $this->schema->actions_table() . ' table).' );
		}

		$status_rows  = $this->results( $this->schema->status_counts_query() );
		$past_due_row = $this->row( $this->schema->past_due_query( $now ) );
		$failed_row   = $this->row( $this->schema->failed_since_query( $now - $thresholds->as_failed_window ) );
		$failing_rows = $this->results( $this->schema->top_failing_query( $now - $thresholds->as_failed_window, 10 ) );
		$stuck_row    = $this->row( $this->schema->stuck_query( $now - $thresholds->as_stuck_after ) );

		return Stats::from_rows( $status_rows, $past_due_row, $failed_row, $failing_rows, $stuck_row, $this->version() );
	}

	/**
	 * Whether the actions table exists.
	 *
	 * @return bool
	 */
	public function table_exists(): bool {
		$found = $this->db->get_var( $this->prepare( $this->schema->table_exists_query() ) );

		return is_string( $found ) && strtolower( $found ) === strtolower( $this->schema->actions_table() );
	}

	/**
	 * Library version, when the class is loaded.
	 *
	 * @return string
	 */
	private function version(): string {
		if ( class_exists( 'ActionScheduler_Versions' ) && method_exists( 'ActionScheduler_Versions', 'instance' ) ) {
			$versions = \ActionScheduler_Versions::instance();
			if ( method_exists( $versions, 'latest_version' ) ) {
				return (string) $versions->latest_version();
			}
		}
		return '';
	}

	/**
	 * Prepares a [ SQL, params ] pair.
	 *
	 * @param array{0: string, 1: array<int, mixed>} $query Query.
	 * @return string
	 */
	private function prepare( array $query ): string {
		list( $sql, $params ) = $query;
		if ( array() === $params ) {
			return $sql;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The SQL comes from Schema and only contains placeholders and the prefixed table name.
		return (string) $this->db->prepare( $sql, ...$params );
	}

	/**
	 * Fetches rows.
	 *
	 * @param array{0: string, 1: array<int, mixed>} $query Query.
	 * @return array<int, array<string, mixed>>
	 */
	private function results( array $query ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Read-only diagnostics on a custom table, prepared above.
		$rows = $this->db->get_results( $this->prepare( $query ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetches one row.
	 *
	 * @param array{0: string, 1: array<int, mixed>} $query Query.
	 * @return array<string, mixed>
	 */
	private function row( array $query ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Read-only diagnostics on a custom table, prepared above.
		$row = $this->db->get_row( $this->prepare( $query ), ARRAY_A );

		return is_array( $row ) ? $row : array();
	}
}
