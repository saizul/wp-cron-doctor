<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\WP;

use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\Tests\Stubs\FakeWpdb;
use Saizul\CronDoctor\WP\ActionSchedulerSource;

final class ActionSchedulerSourceTest extends WpTestCase {

	public function test_reports_missing_tables(): void {
		$db    = new FakeWpdb();
		$stats = ( new ActionSchedulerSource( $db ) )->collect( 1700000000, new Thresholds() );

		$this->assertFalse( $stats->is_available() );
		$this->assertStringContainsString( 'wp_actionscheduler_actions', $stats->reason() );
		$this->assertSame( array( "SHOW TABLES LIKE 'wp\\\\_actionscheduler\\\\_actions'" ), $db->queries );
	}

	public function test_collects_statistics(): void {
		$db          = new FakeWpdb();
		$db->prefix  = 'shop_';
		$db->answers = array(
			'SHOW TABLES'                            => 'shop_actionscheduler_actions',
			'GROUP BY status'                        => array(
				array( 'status' => 'pending', 'total' => '7' ),
				array( 'status' => 'failed', 'total' => '2' ),
			),
			'MIN(scheduled_date_gmt)'                => array( 'total' => '3', 'oldest' => '2023-11-14 21:13:20' ),
			'GROUP BY hook'                          => array( array( 'hook' => 'h', 'total' => '2', 'last_failure' => '2023-11-14 22:00:00' ) ),
			'SELECT COUNT(*) AS total FROM'          => array( 'total' => '2' ),
			'MIN(last_attempt_gmt)'                  => array( 'total' => '1', 'oldest' => '2023-11-14 10:00:00' ),
		);

		$stats = ( new ActionSchedulerSource( $db ) )->collect( 1700000000, new Thresholds() );

		$this->assertTrue( $stats->is_available() );
		$this->assertSame( 7, $stats->count( 'pending' ) );
		$this->assertSame( 3, $stats->past_due() );
		$this->assertSame( 1700000000 - 3600, $stats->oldest_past_due() );
		$this->assertSame( 2, $stats->failed_recent() );
		$this->assertSame( 'h', $stats->top_failing()[0]['hook'] );
		$this->assertSame( 1, $stats->stuck() );

		$sql = implode( "\n", $db->queries );
		$this->assertStringContainsString( "FROM shop_actionscheduler_actions WHERE status = 'pending' AND scheduled_date_gmt <= '2023-11-14 22:13:20'", $sql );
		$this->assertStringContainsString( "status = 'failed' AND last_attempt_gmt >= '2023-11-07 22:13:20'", $sql );
		$this->assertStringContainsString( 'LIMIT 10', $sql );
		$this->assertStringContainsString( "status = 'in-progress' AND last_attempt_gmt > '0000-00-00 00:00:00' AND last_attempt_gmt <= '2023-11-14 21:13:20'", $sql );
	}

	public function test_null_results_are_tolerated(): void {
		$db          = new FakeWpdb();
		$db->answers = array( 'SHOW TABLES' => 'wp_actionscheduler_actions' );

		$stats = ( new ActionSchedulerSource( $db ) )->collect( 1700000000, new Thresholds() );

		$this->assertTrue( $stats->is_available() );
		$this->assertSame( 0, $stats->past_due() );
	}

	public function test_uses_global_wpdb(): void {
		global $wpdb;
		$previous = $wpdb ?? null;
		$wpdb     = new FakeWpdb();

		try {
			$this->assertFalse( ( new ActionSchedulerSource() )->table_exists() );
		} finally {
			$wpdb = $previous;
		}
	}
}
