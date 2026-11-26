<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Fixer;

use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Cron\CronArray;
use Saizul\CronDoctor\Cron\Event;
use Saizul\CronDoctor\Fixer\DuplicatePlanner;
use Saizul\CronDoctor\Tests\Fixtures\CronBuilder;

final class DuplicatePlannerTest extends TestCase {

	private function cron(): CronArray {
		return CronArray::from_option(
			CronBuilder::create()
				->single( 300, 'a', array( 1 ) )
				->single( 100, 'a', array( 1 ) )
				->single( 200, 'a', array( 1 ) )
				->single( 100, 'a', array( '1' ) )
				->single( 500, 'b' )
				->single( 400, 'b' )
				->single( 100, 'c' )
				->build()
		);
	}

	public function test_groups_keep_the_earliest_copy(): void {
		$groups = ( new DuplicatePlanner() )->groups( $this->cron() );

		$this->assertCount( 2, $groups );
		// Biggest group first.
		$this->assertSame( 'a', $groups[0]['keep']->hook() );
		$this->assertSame( 100, $groups[0]['keep']->timestamp() );
		$this->assertSame( array( 200, 300 ), array_map( static fn( Event $e ) => $e->timestamp(), $groups[0]['remove'] ) );
		$this->assertSame( 400, $groups[1]['keep']->timestamp() );
	}

	public function test_type_differences_are_not_duplicates(): void {
		$groups = ( new DuplicatePlanner() )->groups( $this->cron() );

		foreach ( $groups as $group ) {
			foreach ( $group['remove'] as $event ) {
				$this->assertNotSame( array( '1' ), $event->args() );
			}
		}
	}

	public function test_plan_sorted_by_time(): void {
		$plan = ( new DuplicatePlanner() )->plan( $this->cron() );

		$this->assertSame(
			array( 'a@200', 'a@300', 'b@500' ),
			array_map( static fn( Event $e ) => $e->hook() . '@' . $e->timestamp(), $plan )
		);
	}

	public function test_hook_filter(): void {
		$plan = ( new DuplicatePlanner() )->plan( $this->cron(), array( 'b' ) );

		$this->assertCount( 1, $plan );
		$this->assertSame( 'b', $plan[0]->hook() );
	}

	public function test_hook_patterns_and_exclusions(): void {
		$cron = CronArray::from_option(
			CronBuilder::create()
				->single( 100, 'shop_sync' )
				->single( 200, 'shop_sync' )
				->single( 100, 'shop_cleanup' )
				->single( 300, 'shop_cleanup' )
				->single( 100, 'other' )
				->single( 400, 'other' )
				->build()
		);
		$planner = new DuplicatePlanner();
		$hooks   = static fn( array $events ): array => array_map( static fn( Event $e ) => $e->hook() . '@' . $e->timestamp(), $events );

		$this->assertSame( array( 'shop_sync@200', 'shop_cleanup@300' ), $hooks( $planner->plan( $cron, array( 'shop_*' ) ) ) );
		$this->assertSame( array( 'shop_sync@200' ), $hooks( $planner->plan( $cron, array( 'shop_*' ), array( 'shop_cleanup' ) ) ) );
		$this->assertSame( array( 'other@400' ), $hooks( $planner->plan( $cron, array(), array( 'shop_*' ) ) ) );
		$this->assertSame( array(), $planner->plan( $cron, array( 'nothing_*' ) ) );
		$this->assertCount( 3, $planner->groups( $cron ) );
		$this->assertCount( 2, $planner->groups( $cron, array(), array( 'other' ) ) );
	}

	public function test_empty(): void {
		$this->assertSame( array(), ( new DuplicatePlanner() )->plan( CronArray::from_option( array() ) ) );
	}
}
