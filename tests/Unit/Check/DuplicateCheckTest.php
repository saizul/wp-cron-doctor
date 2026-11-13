<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Check;

use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Check\DuplicateCheck;
use Saizul\CronDoctor\Report\Severity;
use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\Snapshot;
use Saizul\CronDoctor\Tests\Fixtures\CronBuilder;

final class DuplicateCheckTest extends TestCase {

	public function test_no_duplicates(): void {
		$raw     = CronBuilder::create()->single( 100, 'a', array( 1 ) )->single( 200, 'a', array( 2 ) )->single( 100, 'b', array( 1 ) )->build();
		$finding = ( new DuplicateCheck() )->run( Snapshot::from_array( array( 'now' => 1, 'cron' => $raw ) ), new Thresholds() )[0];

		$this->assertSame( Severity::OK, $finding->severity() );
		$this->assertSame( 0, $finding->value() );
	}

	public function test_reports_groups_and_surplus(): void {
		$raw = CronBuilder::create()
			->recurring( 100, 'sync', 'hourly', 3600 )
			->recurring( 200, 'sync', 'hourly', 3600 )
			->recurring( 300, 'sync', 'hourly', 3600 )
			->single( 100, 'mail', array( 'id' => 5 ) )
			->single( 900, 'mail', array( 'id' => 5 ) )
			->single( 900, 'mail', array( 'id' => 6 ) )
			->build();

		$finding = ( new DuplicateCheck() )->run( Snapshot::from_array( array( 'now' => 1, 'cron' => $raw ) ), new Thresholds() )[0];

		$this->assertSame( Severity::WARNING, $finding->severity() );
		$this->assertSame( 3, $finding->value() );
		$this->assertStringContainsString( '2 hook/argument combination(s)', $finding->summary() );

		$items = $finding->items();
		$this->assertSame( 'sync', $items[0]['hook'] );
		$this->assertSame( 3, $items[0]['copies'] );
		$this->assertSame( '100,200,300', $items[0]['timestamps'] );
		$this->assertSame( 'hourly', $items[0]['schedule'] );
		$this->assertSame( 'mail', $items[1]['hook'] );
		$this->assertSame( '{"id":5}', $items[1]['args'] );
		$this->assertSame( 'single', $items[1]['schedule'] );
	}

	public function test_critical_when_surplus_reaches_threshold(): void {
		$builder = CronBuilder::create();
		for ( $i = 0; $i < 4; $i++ ) {
			$builder->single( 100 + $i, 'spam' );
		}
		$thresholds = Thresholds::from_options( array( 'duplicates-critical' => '3' ) );
		$finding    = ( new DuplicateCheck() )->run( Snapshot::from_array( array( 'now' => 1, 'cron' => $builder->build() ) ), $thresholds )[0];

		$this->assertSame( Severity::CRITICAL, $finding->severity() );
		$this->assertSame( 3, $finding->value() );
	}
}
