<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Check;

use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Check\IntegrityCheck;
use Saizul\CronDoctor\Report\Severity;
use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\Snapshot;
use Saizul\CronDoctor\Tests\Fixtures\CronBuilder;

final class IntegrityCheckTest extends TestCase {

	private function finding( array $raw ) {
		return ( new IntegrityCheck() )->run( Snapshot::from_array( array( 'now' => 1, 'cron' => $raw ) ), new Thresholds() )[0];
	}

	public function test_well_formed(): void {
		$finding = $this->finding( CronBuilder::create()->single( 1, 'a' )->build() );

		$this->assertSame( Severity::OK, $finding->severity() );
		$this->assertStringContainsString( '1 events', $finding->summary() );
	}

	public function test_legacy_is_info(): void {
		$this->assertSame( Severity::INFO, $this->finding( CronBuilder::create()->single( 1, 'a' )->build( true ) )->severity() );
	}

	public function test_problems_are_listed(): void {
		$finding = $this->finding( CronBuilder::create()->single( 1, 'a' )->raw( 5, 'bad' )->raw( 'x', 1 )->build() );

		$this->assertSame( Severity::WARNING, $finding->severity() );
		$this->assertSame( 2, $finding->value() );
		$this->assertArrayHasKey( 'problem', $finding->items()[0] );
	}
}
