<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\ActionScheduler;

use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\ActionScheduler\Stats;

final class StatsTest extends TestCase {

	public function test_from_rows_with_wpdb_strings(): void {
		$stats = Stats::from_rows(
			array(
				array( 'status' => 'pending', 'total' => '12' ),
				(object) array( 'status' => 'failed', 'total' => '3' ),
				array( 'status' => 'complete', 'total' => '1000' ),
				array( 'status' => 'custom', 'total' => '1' ),
				array( 'total' => '9' ),
			),
			array( 'total' => '5', 'oldest' => '2023-11-14 22:13:20' ),
			(object) array( 'total' => '2' ),
			array(
				array( 'hook' => 'a', 'total' => '2', 'last_failure' => '2023-11-14 22:13:20' ),
				array( 'total' => '1' ),
			),
			array( 'total' => '0', 'oldest' => null ),
			'3.9.0'
		);

		$this->assertTrue( $stats->is_available() );
		$this->assertSame( 12, $stats->count( 'pending' ) );
		$this->assertSame( 3, $stats->count( 'failed' ) );
		$this->assertSame( 0, $stats->count( 'in-progress' ) );
		$this->assertSame( 1, $stats->count( 'custom' ) );
		$this->assertSame( 0, $stats->count( 'nope' ) );
		$this->assertSame( 5, $stats->past_due() );
		$this->assertSame( 1700000000, $stats->oldest_past_due() );
		$this->assertSame( 2, $stats->failed_recent() );
		$this->assertSame(
			array(
				array(
					'hook'         => 'a',
					'count'        => 2,
					'last_failure' => 1700000000,
				),
			),
			$stats->top_failing()
		);
		$this->assertSame( 0, $stats->stuck() );
		$this->assertNull( $stats->oldest_stuck() );
		$this->assertSame( '3.9.0', $stats->version() );
		$this->assertSame( array( 'pending', 'in-progress', 'failed', 'complete', 'canceled', 'custom' ), array_keys( $stats->counts() ) );
	}

	public function test_from_empty_rows(): void {
		$stats = Stats::from_rows( array(), null, null, array(), null );

		$this->assertSame( 0, $stats->past_due() );
		$this->assertNull( $stats->oldest_past_due() );
		$this->assertSame( 0, $stats->failed_recent() );
	}

	public function test_oldest_is_dropped_when_count_is_zero(): void {
		$stats = new Stats( array(), 0, 123, 0, array(), 0, 456 );

		$this->assertNull( $stats->oldest_past_due() );
		$this->assertNull( $stats->oldest_stuck() );
	}

	public function test_negative_values_are_clamped(): void {
		$stats = new Stats( array( 'pending' => -5 ), -1, null, -2, array(), -3 );

		$this->assertSame( 0, $stats->count( 'pending' ) );
		$this->assertSame( 0, $stats->past_due() );
		$this->assertSame( 0, $stats->failed_recent() );
		$this->assertSame( 0, $stats->stuck() );
	}

	public function test_unavailable(): void {
		$stats = Stats::unavailable( 'nope' );

		$this->assertFalse( $stats->is_available() );
		$this->assertSame( 'nope', $stats->reason() );
	}
}
