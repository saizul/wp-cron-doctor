<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Fixer;

use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Fixer\OrphanPlanner;
use Saizul\CronDoctor\Snapshot;
use Saizul\CronDoctor\Tests\Fixtures\CronBuilder;

final class OrphanPlannerTest extends TestCase {

	private function snapshot( array $ignored = array() ): Snapshot {
		return Snapshot::from_array(
			array(
				'now'           => 1,
				'cron'          => CronBuilder::create()
					->single( 100, 'live' )
					->single( 100, 'gone_a', array( 1 ) )
					->single( 200, 'gone_a', array( 2 ) )
					->single( 100, 'gone_b' )
					->single( 100, 'unknown' )
					->build(),
				'callbacks'     => array(
					'live'   => true,
					'gone_a' => false,
					'gone_b' => false,
				),
				'ignored_hooks' => $ignored,
			)
		);
	}

	public function test_orphans_grouped_by_hook(): void {
		$orphans = ( new OrphanPlanner() )->orphans( $this->snapshot() );

		// Hooks that were never looked up count as registered.
		$this->assertSame( array( 'gone_a', 'gone_b' ), array_keys( $orphans ) );
		$this->assertCount( 2, $orphans['gone_a'] );
	}

	public function test_plan_with_only_and_ignored(): void {
		$planner = new OrphanPlanner();

		$this->assertCount( 3, $planner->plan( $this->snapshot() ) );
		$this->assertCount( 1, $planner->plan( $this->snapshot(), array( 'gone_b' ) ) );
		$this->assertCount( 3, $planner->plan( $this->snapshot(), array( 'gone_*' ) ) );
		$this->assertCount( 1, $planner->plan( $this->snapshot( array( 'gone_a' ) ) ) );
		$this->assertCount( 0, $planner->plan( $this->snapshot( array( '*' ) ) ) );
	}
}
