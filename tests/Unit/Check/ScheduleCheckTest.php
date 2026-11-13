<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Check;

use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Check\ScheduleCheck;
use Saizul\CronDoctor\Cron\Event;
use Saizul\CronDoctor\Report\Severity;
use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\Snapshot;
use Saizul\CronDoctor\Tests\Fixtures\CronBuilder;

final class ScheduleCheckTest extends TestCase {

	private const SCHEDULES = array(
		'hourly'           => 3600,
		'every_minute'     => 60,
		'every_10_seconds' => 10,
	);

	private function findings( array $raw, array $schedules = self::SCHEDULES ): array {
		$out = array();
		foreach ( ( new ScheduleCheck() )->run( Snapshot::from_array( array( 'now' => 1, 'cron' => $raw, 'schedules' => $schedules ) ), new Thresholds() ) as $finding ) {
			$out[ $finding->check() ] = $finding;
		}
		return $out;
	}

	public function test_all_fine(): void {
		$findings = $this->findings( CronBuilder::create()->recurring( 100, 'a', 'hourly', 3600 )->single( 100, 'b' )->build() );

		$this->assertSame( Severity::OK, $findings['frequent']->severity() );
		$this->assertSame( Severity::OK, $findings['unknown-schedules']->severity() );
	}

	public function test_frequent_uses_registered_interval_and_groups_rows(): void {
		$raw = CronBuilder::create()
			->recurring( 100, 'poller', 'every_minute', 999999 )
			->recurring( 200, 'poller', 'every_minute', 999999, array( 2 ) )
			->recurring( 100, 'fast', 'every_10_seconds', 10 )
			->recurring( 100, 'ok', 'hourly', 3600 )
			->build();

		$finding = $this->findings( $raw )['frequent'];

		$this->assertSame( Severity::WARNING, $finding->severity() );
		$this->assertSame( 2, $finding->value() );
		$this->assertStringContainsString( 'the shortest interval is 10s', $finding->summary() );
		$this->assertSame( 'fast', $finding->items()[0]['hook'] );
		$this->assertSame( 'poller', $finding->items()[1]['hook'] );
		$this->assertSame( 60, $finding->items()[1]['interval'] );
		$this->assertSame( 2, $finding->items()[1]['events'] );
	}

	public function test_frequent_falls_back_to_stored_interval(): void {
		$finding = $this->findings( CronBuilder::create()->recurring( 100, 'gone', 'every_30s', 30 )->build() )['frequent'];

		$this->assertSame( Severity::WARNING, $finding->severity() );
		$this->assertSame( 30, $finding->items()[0]['interval'] );
	}

	public function test_unknown_schedules(): void {
		$raw = CronBuilder::create()
			->recurring( 100, 'gone', 'every_6_hours', 21600 )
			->recurring( 200, 'gone', 'every_6_hours', 21600, array( 1 ) )
			->build();

		$finding = $this->findings( $raw )['unknown-schedules'];

		$this->assertSame( Severity::WARNING, $finding->severity() );
		$this->assertSame( 1, $finding->value() );
		$this->assertSame(
			array(
				'hook'     => 'gone',
				'schedule' => 'every_6_hours',
				'interval' => 21600,
				'events'   => 2,
			),
			$finding->items()[0]
		);
	}

	public function test_unknown_schedules_need_registered_list(): void {
		$finding = $this->findings( CronBuilder::create()->recurring( 1, 'a', 'x', 60 )->build(), array() )['unknown-schedules'];

		$this->assertSame( Severity::INFO, $finding->severity() );
	}

	public function test_effective_interval(): void {
		$this->assertNull( ScheduleCheck::effective_interval( new Event( 1, 'a' ), self::SCHEDULES ) );
		$this->assertSame( 3600, ScheduleCheck::effective_interval( new Event( 1, 'a', array(), 'hourly', 5 ), self::SCHEDULES ) );
		$this->assertSame( 5, ScheduleCheck::effective_interval( new Event( 1, 'a', array(), 'missing', 5 ), self::SCHEDULES ) );
		$this->assertNull( ScheduleCheck::effective_interval( new Event( 1, 'a', array(), 'missing' ), self::SCHEDULES ) );
	}
}
