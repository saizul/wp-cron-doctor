<?php
/**
 * Action Scheduler table names, statuses and queries.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\ActionScheduler;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Knows the Action Scheduler custom-table schema (Action Scheduler 3.0+).
 *
 * Table names come from ActionScheduler_StoreSchema and ActionScheduler_LoggerSchema,
 * statuses from the ActionScheduler_Store::STATUS_* constants.
 *
 * The queries are returned as [ SQL, parameters ] with wpdb::prepare() placeholders so
 * they can be tested without a database. Table names are interpolated because wpdb's
 * `%i` placeholder needs WordPress 6.2; they are built from $wpdb->prefix only.
 */
final class Schema {

	public const ACTIONS_TABLE = 'actionscheduler_actions';
	public const GROUPS_TABLE  = 'actionscheduler_groups';
	public const CLAIMS_TABLE  = 'actionscheduler_claims';
	public const LOGS_TABLE    = 'actionscheduler_logs';

	public const STATUS_COMPLETE = 'complete';
	public const STATUS_PENDING  = 'pending';
	public const STATUS_RUNNING  = 'in-progress';
	public const STATUS_FAILED   = 'failed';
	public const STATUS_CANCELED = 'canceled';

	/**
	 * MySQL zero date used by Action Scheduler for "never".
	 */
	public const ZERO_DATE = '0000-00-00 00:00:00';

	/**
	 * Table prefix, e.g. "wp_".
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Constructor.
	 *
	 * @param string $prefix Table prefix ($wpdb->prefix).
	 */
	public function __construct( string $prefix ) {
		$this->prefix = preg_replace( '/[^A-Za-z0-9_]/', '', $prefix ) ?? '';
	}

	/**
	 * All statuses.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return array(
			self::STATUS_PENDING,
			self::STATUS_RUNNING,
			self::STATUS_FAILED,
			self::STATUS_COMPLETE,
			self::STATUS_CANCELED,
		);
	}

	/**
	 * Full name of the actions table.
	 *
	 * @return string
	 */
	public function actions_table(): string {
		return $this->prefix . self::ACTIONS_TABLE;
	}

	/**
	 * Full name of the groups table.
	 *
	 * @return string
	 */
	public function groups_table(): string {
		return $this->prefix . self::GROUPS_TABLE;
	}

	/**
	 * Full name of the claims table.
	 *
	 * @return string
	 */
	public function claims_table(): string {
		return $this->prefix . self::CLAIMS_TABLE;
	}

	/**
	 * Full name of the logs table.
	 *
	 * @return string
	 */
	public function logs_table(): string {
		return $this->prefix . self::LOGS_TABLE;
	}

	/**
	 * Query that checks whether the actions table exists.
	 *
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	public function table_exists_query(): array {
		return array( 'SHOW TABLES LIKE %s', array( $this->escape_like( $this->actions_table() ) ) );
	}

	/**
	 * Number of actions per status.
	 *
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	public function status_counts_query(): array {
		return array( 'SELECT status, COUNT(*) AS total FROM ' . $this->actions_table() . ' GROUP BY status', array() );
	}

	/**
	 * Pending actions scheduled before $now, and the oldest of them.
	 *
	 * @param int $now Current time.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	public function past_due_query( int $now ): array {
		return array(
			'SELECT COUNT(*) AS total, MIN(scheduled_date_gmt) AS oldest FROM ' . $this->actions_table() . ' WHERE status = %s AND scheduled_date_gmt <= %s',
			array( self::STATUS_PENDING, self::to_mysql( $now ) ),
		);
	}

	/**
	 * Actions that failed since $since.
	 *
	 * @param int $since Start of the window.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	public function failed_since_query( int $since ): array {
		return array(
			'SELECT COUNT(*) AS total FROM ' . $this->actions_table() . ' WHERE status = %s AND last_attempt_gmt >= %s',
			array( self::STATUS_FAILED, self::to_mysql( $since ) ),
		);
	}

	/**
	 * Hooks with the most failures since $since.
	 *
	 * @param int $since Start of the window.
	 * @param int $limit Maximum hooks.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	public function top_failing_query( int $since, int $limit = 10 ): array {
		return array(
			'SELECT hook, COUNT(*) AS total, MAX(last_attempt_gmt) AS last_failure FROM ' . $this->actions_table() . ' WHERE status = %s AND last_attempt_gmt >= %s GROUP BY hook ORDER BY total DESC, hook ASC LIMIT %d',
			array( self::STATUS_FAILED, self::to_mysql( $since ), max( 1, $limit ) ),
		);
	}

	/**
	 * In-progress actions whose last attempt started before $before.
	 *
	 * @param int $before Cut-off.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	public function stuck_query( int $before ): array {
		return array(
			'SELECT COUNT(*) AS total, MIN(last_attempt_gmt) AS oldest FROM ' . $this->actions_table() . ' WHERE status = %s AND last_attempt_gmt > %s AND last_attempt_gmt <= %s',
			array( self::STATUS_RUNNING, self::ZERO_DATE, self::to_mysql( $before ) ),
		);
	}

	/**
	 * Escapes LIKE wildcards like wpdb::esc_like().
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function escape_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * Formats a Unix time as a MySQL UTC datetime.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	public static function to_mysql( int $time ): string {
		return gmdate( 'Y-m-d H:i:s', $time );
	}

	/**
	 * Parses a MySQL UTC datetime; null for empty and zero dates.
	 *
	 * @param mixed $value Datetime string.
	 * @return int|null
	 */
	public static function from_mysql( $value ): ?int {
		if ( ! is_string( $value ) || '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
			return null;
		}

		try {
			$date = new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return null;
		}

		return $date->getTimestamp();
	}
}
