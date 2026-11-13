<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\ActionScheduler;

use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\ActionScheduler\Schema;

final class SchemaTest extends TestCase {

	public function test_table_names(): void {
		$schema = new Schema( 'wp_' );

		$this->assertSame( 'wp_actionscheduler_actions', $schema->actions_table() );
		$this->assertSame( 'wp_actionscheduler_groups', $schema->groups_table() );
		$this->assertSame( 'wp_actionscheduler_claims', $schema->claims_table() );
		$this->assertSame( 'wp_actionscheduler_logs', $schema->logs_table() );
	}

	public function test_prefix_is_sanitized(): void {
		$this->assertSame( 'wp2_DROPTABLExactionscheduler_actions', ( new Schema( 'wp2_; DROP TABLE x;--' ) )->actions_table() );
		$this->assertSame( 'site_3_actionscheduler_actions', ( new Schema( 'site_3_' ) )->actions_table() );
	}

	public function test_statuses_match_action_scheduler_store(): void {
		$this->assertSame( array( 'pending', 'in-progress', 'failed', 'complete', 'canceled' ), Schema::statuses() );
	}

	public function test_table_exists_query_escapes_like_wildcards(): void {
		list( $sql, $params ) = ( new Schema( 'wp_' ) )->table_exists_query();

		$this->assertSame( 'SHOW TABLES LIKE %s', $sql );
		$this->assertSame( array( 'wp\\_actionscheduler\\_actions' ), $params );
	}

	public function test_queries(): void {
		$schema = new Schema( 'wp_' );

		list( $sql, $params ) = $schema->status_counts_query();
		$this->assertSame( 'SELECT status, COUNT(*) AS total FROM wp_actionscheduler_actions GROUP BY status', $sql );
		$this->assertSame( array(), $params );

		list( $sql, $params ) = $schema->past_due_query( 1700000000 );
		$this->assertStringContainsString( 'MIN(scheduled_date_gmt) AS oldest', $sql );
		$this->assertStringContainsString( 'WHERE status = %s AND scheduled_date_gmt <= %s', $sql );
		$this->assertSame( array( 'pending', '2023-11-14 22:13:20' ), $params );

		list( $sql, $params ) = $schema->failed_since_query( 0 );
		$this->assertStringContainsString( 'last_attempt_gmt >= %s', $sql );
		$this->assertSame( array( 'failed', '1970-01-01 00:00:00' ), $params );

		list( $sql, $params ) = $schema->top_failing_query( 0, 0 );
		$this->assertStringContainsString( 'GROUP BY hook ORDER BY total DESC, hook ASC LIMIT %d', $sql );
		$this->assertSame( 1, $params[2] );

		list( $sql, $params ) = $schema->stuck_query( 1700000000 );
		$this->assertStringContainsString( 'last_attempt_gmt > %s AND last_attempt_gmt <= %s', $sql );
		$this->assertSame( array( 'in-progress', '0000-00-00 00:00:00', '2023-11-14 22:13:20' ), $params );
	}

	public function test_placeholder_counts_match_parameters(): void {
		$schema = new Schema( 'wp_' );
		foreach ( array( $schema->status_counts_query(), $schema->past_due_query( 1 ), $schema->failed_since_query( 1 ), $schema->top_failing_query( 1 ), $schema->stuck_query( 1 ), $schema->table_exists_query() ) as $query ) {
			$this->assertSame( preg_match_all( '/%[sd]/', $query[0] ), count( $query[1] ), $query[0] );
		}
	}

	public function test_mysql_dates(): void {
		$this->assertSame( '2023-11-14 22:13:20', Schema::to_mysql( 1700000000 ) );
		$this->assertSame( 1700000000, Schema::from_mysql( '2023-11-14 22:13:20' ) );
		$this->assertNull( Schema::from_mysql( '0000-00-00 00:00:00' ) );
		$this->assertNull( Schema::from_mysql( '' ) );
		$this->assertNull( Schema::from_mysql( null ) );
		$this->assertNull( Schema::from_mysql( 'not a date' ) );
	}
}
