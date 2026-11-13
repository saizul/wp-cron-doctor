<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Fixer;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Cron\CronArray;
use Saizul\CronDoctor\Cron\Event;
use Saizul\CronDoctor\Fixer\OverdueSelector;
use Saizul\CronDoctor\Tests\Fixtures\CronBuilder;

final class OverdueSelectorTest extends TestCase {

	private const NOW = 10000;

	private function cron(): CronArray {
		return CronArray::from_option(
			CronBuilder::create()
				->single( self::NOW - 5000, 'old' )
				->single( self::NOW - 3000, 'wc_a' )
				->single( self::NOW - 100, 'fresh' )
				->single( self::NOW, 'now' )
				->single( self::NOW + 100, 'future' )
				->build()
		);
	}

	private function hooks( array $events ): array {
		return array_map( static fn( Event $e ) => $e->hook(), $events );
	}

	public function test_selects_due_events_oldest_first(): void {
		$this->assertSame( array( 'old', 'wc_a', 'fresh', 'now' ), $this->hooks( ( new OverdueSelector() )->select( $this->cron(), self::NOW, 0 ) ) );
	}

	public function test_limit(): void {
		$this->assertSame( array( 'old', 'wc_a' ), $this->hooks( ( new OverdueSelector() )->select( $this->cron(), self::NOW, 2 ) ) );
	}

	public function test_grace(): void {
		$this->assertSame( array( 'old', 'wc_a' ), $this->hooks( ( new OverdueSelector() )->select( $this->cron(), self::NOW, 0, 1000 ) ) );
	}

	public function test_only_and_exclude(): void {
		$selector = new OverdueSelector();

		$this->assertSame( array( 'wc_a' ), $this->hooks( $selector->select( $this->cron(), self::NOW, 0, 0, array( 'wc_*' ) ) ) );
		$this->assertSame( array( 'wc_a', 'fresh', 'now' ), $this->hooks( $selector->select( $this->cron(), self::NOW, 0, 0, array(), array( 'old' ) ) ) );
	}

	public function test_rejects_negative_values(): void {
		$this->expectException( InvalidArgumentException::class );
		( new OverdueSelector() )->select( $this->cron(), self::NOW, -1 );
	}

	public function test_rejects_negative_grace(): void {
		$this->expectException( InvalidArgumentException::class );
		( new OverdueSelector() )->select( $this->cron(), self::NOW, 1, -1 );
	}
}
