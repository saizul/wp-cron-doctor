<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Check;

use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Check\OverdueCheck;
use Saizul\CronDoctor\Report\Severity;
use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\Snapshot;
use Saizul\CronDoctor\Tests\Fixtures\CronBuilder;

final class OverdueCheckTest extends TestCase {

	private const NOW = 1700000000;

	private function run_check( array $raw ): array {
		return ( new OverdueCheck() )->run(
			Snapshot::from_array( array( 'now' => self::NOW, 'cron' => $raw ) ),
			new Thresholds()
		);
	}

	public function test_nothing_due(): void {
		$finding = $this->run_check( CronBuilder::create()->single( self::NOW + 60, 'future' )->build() )[0];

		$this->assertSame( Severity::OK, $finding->severity() );
		$this->assertSame( 'No events are overdue.', $finding->summary() );
		$this->assertSame( 0, $finding->value() );
	}

	public function test_due_within_grace_is_ok(): void {
		$finding = $this->run_check( CronBuilder::create()->single( self::NOW - 30, 'a' )->single( self::NOW, 'b' )->build() )[0];

		$this->assertSame( Severity::OK, $finding->severity() );
		$this->assertStringContainsString( '2 event(s) are due but within the 10m grace period', $finding->summary() );
	}

	public function test_warning_when_late_beyond_grace(): void {
		$finding = $this->run_check(
			CronBuilder::create()
				->single( self::NOW - 1200, 'late' )
				->single( self::NOW - 30, 'fresh' )
				->build()
		)[0];

		$this->assertSame( Severity::WARNING, $finding->severity() );
		$this->assertSame( 1, $finding->value() );
		$this->assertStringContainsString( 'the oldest is 20m late', $finding->summary() );
		$this->assertSame( 'late', $finding->items()[0]['hook'] );
		$this->assertSame( 1200, $finding->items()[0]['late_secs'] );
	}

	public function test_critical_when_very_late_and_sorted_oldest_first(): void {
		$finding = $this->run_check(
			CronBuilder::create()
				->recurring( self::NOW - 7200, 'oldest', 'hourly', 3600 )
				->single( self::NOW - 900, 'newer' )
				->build()
		)[0];

		$this->assertSame( Severity::CRITICAL, $finding->severity() );
		$this->assertSame( array( 'oldest', 'newer' ), array_column( $finding->items(), 'hook' ) );
		$this->assertSame( 'hourly', $finding->items()[0]['schedule'] );
	}

	public function test_custom_thresholds(): void {
		$snapshot   = Snapshot::from_array( array( 'now' => self::NOW, 'cron' => CronBuilder::create()->single( self::NOW - 120, 'a' )->build() ) );
		$thresholds = Thresholds::from_options( array( 'overdue-warning' => '1m', 'overdue-critical' => '2m' ) );

		$this->assertSame( Severity::CRITICAL, ( new OverdueCheck() )->run( $snapshot, $thresholds )[0]->severity() );
	}

	public function test_max_lateness(): void {
		$snapshot = Snapshot::from_array( array( 'now' => self::NOW, 'cron' => CronBuilder::create()->single( self::NOW - 50, 'a' )->single( self::NOW - 500, 'b' )->build() ) );
		$this->assertSame( 500, OverdueCheck::max_lateness( $snapshot ) );

		$empty = Snapshot::from_array( array( 'now' => self::NOW, 'cron' => array() ) );
		$this->assertSame( 0, OverdueCheck::max_lateness( $empty ) );
	}

	public function test_zero_grace_counts_any_past_event(): void {
		$snapshot = Snapshot::from_array( array( 'now' => self::NOW, 'cron' => CronBuilder::create()->single( self::NOW - 1, 'a' )->single( self::NOW, 'now' )->build() ) );

		$this->assertCount( 1, OverdueCheck::overdue_events( $snapshot, 0 ) );
	}
}
