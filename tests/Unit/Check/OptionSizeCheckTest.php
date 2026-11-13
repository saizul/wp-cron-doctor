<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Check;

use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Check\OptionSizeCheck;
use Saizul\CronDoctor\Report\Severity;
use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\Snapshot;
use Saizul\CronDoctor\Tests\Fixtures\CronBuilder;

final class OptionSizeCheckTest extends TestCase {

	private function finding( int $bytes, array $raw, array $options = array() ) {
		return ( new OptionSizeCheck() )->run(
			Snapshot::from_array( array( 'now' => 1, 'cron' => $raw, 'option_bytes' => $bytes ) ),
			Thresholds::from_options( $options )
		)[0];
	}

	public function test_small_option_is_ok(): void {
		$finding = $this->finding( 2048, CronBuilder::create()->single( 1, 'a' )->build() );

		$this->assertSame( Severity::OK, $finding->severity() );
		$this->assertSame( 2048, $finding->value() );
		$this->assertSame( 'The cron option is 2.0 KB with 1 event(s).', $finding->summary() );
		$this->assertSame( array(), $finding->items() );
	}

	public function test_warning_and_critical_by_size(): void {
		$raw = CronBuilder::create()->single( 1, 'a' )->build();

		$this->assertSame( Severity::WARNING, $this->finding( 300000, $raw )->severity() );
		$this->assertSame( Severity::CRITICAL, $this->finding( 2000000, $raw )->severity() );
	}

	public function test_warning_by_event_count_lists_largest_hooks(): void {
		$builder = CronBuilder::create();
		for ( $i = 1; $i <= 5; $i++ ) {
			$builder->single( $i, 'big', array( $i ) );
		}
		$builder->single( 1, 'small' );

		$finding = $this->finding( 100, $builder->build(), array( 'events-warning' => '5' ) );

		$this->assertSame( Severity::WARNING, $finding->severity() );
		$this->assertSame(
			array(
				array( 'hook' => 'big', 'events' => 5 ),
				array( 'hook' => 'small', 'events' => 1 ),
			),
			$finding->items()
		);
		$this->assertNotSame( '', $finding->advice() );
	}
}
