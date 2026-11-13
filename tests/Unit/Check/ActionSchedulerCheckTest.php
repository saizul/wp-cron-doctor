<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Check;

use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\ActionScheduler\Stats;
use Saizul\CronDoctor\Check\ActionSchedulerCheck;
use Saizul\CronDoctor\Report\Finding;
use Saizul\CronDoctor\Report\Severity;
use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\Snapshot;

final class ActionSchedulerCheckTest extends TestCase {

	private const NOW = 1700000000;

	/**
	 * @return array<string, Finding>
	 */
	private function findings( ?Stats $stats, array $options = array() ): array {
		$out = array();
		foreach ( ( new ActionSchedulerCheck() )->run( Snapshot::from_array( array( 'now' => self::NOW, 'cron' => array(), 'action_scheduler' => $stats ) ), Thresholds::from_options( $options ) ) as $finding ) {
			$out[ $finding->check() ] = $finding;
		}
		return $out;
	}

	public function test_skipped_and_unavailable(): void {
		$this->assertSame( array( 'action-scheduler' ), array_keys( $this->findings( null ) ) );
		$this->assertSame( Severity::INFO, $this->findings( null )['action-scheduler']->severity() );

		$unavailable = $this->findings( Stats::unavailable( 'not installed.' ) )['action-scheduler'];
		$this->assertSame( Severity::INFO, $unavailable->severity() );
		$this->assertSame( 'Action Scheduler not checked: not installed.', $unavailable->summary() );
	}

	public function test_healthy_queue(): void {
		$findings = $this->findings( new Stats( array( 'pending' => 3, 'complete' => 100 ), 0, null, 0, array(), 0, null, '3.9.2' ) );

		$this->assertSame( array( 'action-scheduler', 'as-past-due', 'as-failed', 'as-stuck' ), array_keys( $findings ) );
		foreach ( $findings as $finding ) {
			$this->assertSame( Severity::OK, $finding->severity(), $finding->check() );
		}
		$this->assertSame( '3 pending, 0 in progress, 0 failed, 100 complete, 0 canceled (Action Scheduler 3.9.2).', $findings['action-scheduler']->summary() );
		$this->assertCount( 5, $findings['action-scheduler']->items() );
	}

	public function test_large_pending_queue_is_warning(): void {
		$finding = $this->findings( new Stats( array( 'pending' => 20000 ) ) )['action-scheduler'];

		$this->assertSame( Severity::WARNING, $finding->severity() );
		$this->assertSame( 20000, $finding->value() );
	}

	public function test_past_due_levels(): void {
		$recent = $this->findings( new Stats( array(), 4, self::NOW - 60 ) )['as-past-due'];
		$this->assertSame( Severity::OK, $recent->severity() );
		$this->assertSame( 4, $recent->value() );

		$warn = $this->findings( new Stats( array(), 4, self::NOW - 1200 ) )['as-past-due'];
		$this->assertSame( Severity::WARNING, $warn->severity() );
		$this->assertStringContainsString( 'the oldest by 20m', $warn->summary() );
		$this->assertSame( '20m', $warn->items()[0]['age'] );

		$crit = $this->findings( new Stats( array(), 900, self::NOW - 86400 ) )['as-past-due'];
		$this->assertSame( Severity::CRITICAL, $crit->severity() );
		$this->assertStringContainsString( 'action_scheduler_run_queue', $crit->advice() );
	}

	public function test_failed_with_top_hooks(): void {
		$stats = new Stats(
			array( 'failed' => 500 ),
			0,
			null,
			12,
			array(
				array(
					'hook'         => 'woocommerce_deliver_webhook_async',
					'count'        => 10,
					'last_failure' => self::NOW - 60,
				),
				array(
					'hook'         => 'wc_admin_import_orders',
					'count'        => 2,
					'last_failure' => null,
				),
			)
		);
		$finding = $this->findings( $stats )['as-failed'];

		$this->assertSame( Severity::WARNING, $finding->severity() );
		$this->assertSame( '12 action(s) failed in the last 1w. Top hook: woocommerce_deliver_webhook_async (10).', $finding->summary() );
		$this->assertSame( 'woocommerce_deliver_webhook_async', $finding->items()[0]['hook'] );
		$this->assertSame( '', $finding->items()[1]['last_failure'] );

		$this->assertSame( Severity::CRITICAL, $this->findings( $stats, array( 'as-failed-critical' => '12' ) )['as-failed']->severity() );
		$this->assertSame( Severity::OK, $this->findings( $stats, array( 'as-failed-warning' => '13', 'as-failed-critical' => '20' ) )['as-failed']->severity() );
	}

	public function test_stuck(): void {
		$finding = $this->findings( new Stats( array( 'in-progress' => 2 ), 0, null, 0, array(), 2, self::NOW - 7200 ) )['as-stuck'];

		$this->assertSame( Severity::WARNING, $finding->severity() );
		$this->assertStringContainsString( 'The oldest started 2h ago.', $finding->summary() );
	}
}
