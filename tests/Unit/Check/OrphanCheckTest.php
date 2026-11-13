<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Check;

use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Check\OrphanCheck;
use Saizul\CronDoctor\Report\Severity;
use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\Snapshot;
use Saizul\CronDoctor\Tests\Fixtures\CronBuilder;

final class OrphanCheckTest extends TestCase {

	private function raw(): array {
		return CronBuilder::create()
			->recurring( 100, 'wp_version_check', 'twicedaily', 43200 )
			->recurring( 100, 'old_plugin_sync', 'hourly', 3600 )
			->single( 200, 'old_plugin_mail', array( 1 ) )
			->single( 300, 'old_plugin_mail', array( 2 ) )
			->build();
	}

	public function test_reports_hooks_without_callbacks(): void {
		$snapshot = Snapshot::from_array(
			array(
				'now'       => 1,
				'cron'      => $this->raw(),
				'callbacks' => array(
					'wp_version_check' => true,
					'old_plugin_sync'  => false,
					'old_plugin_mail'  => false,
				),
			)
		);

		$finding = ( new OrphanCheck() )->run( $snapshot, new Thresholds() )[0];

		$this->assertSame( Severity::WARNING, $finding->severity() );
		$this->assertSame( 2, $finding->value() );
		$this->assertStringContainsString( '2 hook(s) with 3 event(s)', $finding->summary() );
		$this->assertSame( array( 'old_plugin_mail', 'old_plugin_sync' ), array_column( $finding->items(), 'hook' ) );
		$this->assertSame( 2, $finding->items()[0]['events'] );
		$this->assertSame( 'single', $finding->items()[0]['schedule'] );
		$this->assertSame( 'hourly', $finding->items()[1]['schedule'] );
	}

	public function test_ignored_hooks_and_patterns(): void {
		$snapshot = Snapshot::from_array(
			array(
				'now'           => 1,
				'cron'          => $this->raw(),
				'callbacks'     => array(
					'wp_version_check' => true,
					'old_plugin_sync'  => false,
					'old_plugin_mail'  => false,
				),
				'ignored_hooks' => array( 'old_plugin_*' ),
			)
		);

		$this->assertSame( Severity::OK, ( new OrphanCheck() )->run( $snapshot, new Thresholds() )[0]->severity() );
	}

	public function test_all_registered(): void {
		$snapshot = Snapshot::from_array(
			array(
				'now'       => 1,
				'cron'      => CronBuilder::create()->single( 1, 'a' )->build(),
				'callbacks' => array( 'a' => true ),
			)
		);

		$this->assertSame( 'Every scheduled hook has a callback.', ( new OrphanCheck() )->run( $snapshot, new Thresholds() )[0]->summary() );
	}

	public function test_info_when_callbacks_were_not_inspected(): void {
		$snapshot = Snapshot::from_array( array( 'now' => 1, 'cron' => $this->raw() ) );

		$this->assertSame( Severity::INFO, ( new OrphanCheck() )->run( $snapshot, new Thresholds() )[0]->severity() );
	}

	public function test_ok_without_events(): void {
		$snapshot = Snapshot::from_array( array( 'now' => 1, 'cron' => array() ) );

		$this->assertSame( Severity::OK, ( new OrphanCheck() )->run( $snapshot, new Thresholds() )[0]->severity() );
	}
}
