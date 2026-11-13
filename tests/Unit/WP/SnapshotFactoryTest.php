<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\WP;

use Saizul\CronDoctor\ActionScheduler\Stats;
use Saizul\CronDoctor\Heartbeat\Heartbeat;
use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\Tests\Fixtures\CronBuilder;
use Saizul\CronDoctor\Tests\Stubs\FakeWpdb;
use Saizul\CronDoctor\Tests\Stubs\WpState;
use Saizul\CronDoctor\WP\ActionSchedulerSource;
use Saizul\CronDoctor\WP\SnapshotFactory;

final class SnapshotFactoryTest extends WpTestCase {

	private function factory(): SnapshotFactory {
		return new SnapshotFactory(
			null,
			static function (): ActionSchedulerSource {
				return new ActionSchedulerSource( new FakeWpdb() );
			}
		);
	}

	public function test_collects_cron_state(): void {
		$raw = CronBuilder::create()
			->recurring( 100, 'wp_version_check', 'twicedaily', 43200 )
			->single( 200, 'removed_plugin_hook' )
			->build();

		WpState::$options['cron']             = $raw;
		WpState::$options[ Heartbeat::OPTION ] = Heartbeat::encode( 1234, 'system' );
		WpState::$transients['doing_cron']     = '1700000000.1234';
		add_action( 'wp_version_check', 'wp_version_check' );
		add_filter( 'wp_cron_doctor_ignored_hooks', static fn( array $hooks ) => array_merge( $hooks, array( 'from_filter' ) ) );

		$before   = time();
		$snapshot = $this->factory()->create( new Thresholds(), array( 'ignored_hooks' => array( 'from_cli' ) ) );

		$this->assertGreaterThanOrEqual( $before, $snapshot->now );
		$this->assertSame( 2, $snapshot->cron->count() );
		$this->assertSame( strlen( serialize( $raw ) ), $snapshot->option_bytes );
		$this->assertSame(
			array(
				'removed_plugin_hook' => false,
				'wp_version_check'    => true,
			),
			$snapshot->callbacks
		);
		$this->assertSame( 43200, $snapshot->schedules['twicedaily'] );
		$this->assertFalse( $snapshot->disable_wp_cron );
		$this->assertFalse( $snapshot->alternate_wp_cron );
		$this->assertSame( 1234, $snapshot->last_run );
		$this->assertSame( 'system', $snapshot->last_run_source );
		$this->assertSame( 1700000000.1234, $snapshot->cron_lock );
		$this->assertSame( array( 'from_filter', 'from_cli' ), $snapshot->ignored_hooks );
		$this->assertSame( 200, $snapshot->loopback->status() );
		$this->assertInstanceOf( Stats::class, $snapshot->action_scheduler );
		$this->assertFalse( $snapshot->action_scheduler->is_available() );
	}

	public function test_options_skip_slow_parts(): void {
		$snapshot = $this->factory()->create(
			new Thresholds(),
			array(
				'loopback'         => false,
				'action_scheduler' => false,
			)
		);

		$this->assertTrue( $snapshot->loopback->is_skipped() );
		$this->assertNull( $snapshot->action_scheduler );
		$this->assertSame( array(), WpState::$requests );
		$this->assertSame( 0, $snapshot->option_bytes );
		$this->assertNull( $snapshot->cron_lock );
		$this->assertNull( $snapshot->last_run );
	}

	public function test_invalid_lock_is_ignored(): void {
		WpState::$transients['doing_cron'] = 'garbage';

		$this->assertNull( $this->factory()->create( new Thresholds(), array( 'loopback' => false, 'action_scheduler' => false ) )->cron_lock );
	}
}
