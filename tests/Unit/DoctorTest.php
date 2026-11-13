<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\ActionScheduler\Stats;
use Saizul\CronDoctor\Check\Check;
use Saizul\CronDoctor\Doctor;
use Saizul\CronDoctor\Loopback\LoopbackResult;
use Saizul\CronDoctor\Report\Finding;
use Saizul\CronDoctor\Report\Severity;
use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\Snapshot;
use Saizul\CronDoctor\Tests\Fixtures\CronBuilder;

final class DoctorTest extends TestCase {

	private const NOW = 1700000000;

	public function test_healthy_site(): void {
		$snapshot = Snapshot::from_array(
			array(
				'now'              => self::NOW,
				'cron'             => CronBuilder::create()
					->recurring( self::NOW + 600, 'wp_version_check', 'twicedaily', 43200 )
					->recurring( self::NOW + 60, 'action_scheduler_run_queue', 'every_minute', 60 )
					->build(),
				'callbacks'        => array(
					'wp_version_check'           => true,
					'action_scheduler_run_queue' => true,
				),
				'schedules'        => array(
					'twicedaily'   => 43200,
					'every_minute' => 60,
				),
				'option_bytes'     => 900,
				'loopback'         => LoopbackResult::response( 'https://example.com/wp-cron.php', 200, 0.05 ),
				'action_scheduler' => new Stats( array( 'pending' => 2 ) ),
			)
		);

		$report = ( new Doctor() )->diagnose( $snapshot, Thresholds::from_options( array( 'frequent' => '1m' ) ) );

		$this->assertSame( Doctor::check_ids(), $report->checks() );
		$this->assertSame( Severity::OK, $report->worst() );
		$this->assertSame( 0, $report->exit_code() );
	}

	public function test_broken_site(): void {
		$snapshot = Snapshot::from_array(
			array(
				'now'              => self::NOW,
				'cron'             => CronBuilder::create()
					->single( self::NOW - 86400, 'gone_plugin_task' )
					->single( self::NOW - 7200, 'dupe' )
					->single( self::NOW - 3600, 'dupe' )
					->build(),
				'callbacks'        => array(
					'gone_plugin_task' => false,
					'dupe'             => true,
				),
				'schedules'        => array( 'hourly' => 3600 ),
				'option_bytes'     => 500,
				'disable_wp_cron'  => true,
				'loopback'         => LoopbackResult::response( 'https://example.com/wp-cron.php', 403, 0.05 ),
				'action_scheduler' => new Stats( array( 'pending' => 50 ), 50, self::NOW - 86400 ),
			)
		);

		$report = ( new Doctor() )->diagnose( $snapshot );

		$this->assertSame( Severity::CRITICAL, $report->worst() );
		$this->assertSame( 2, $report->exit_code() );
		$this->assertSame( Severity::CRITICAL, $report->get( 'runner' )->severity() );
		$this->assertSame( Severity::CRITICAL, $report->get( 'overdue' )->severity() );
		$this->assertSame( Severity::WARNING, $report->get( 'duplicates' )->severity() );
		$this->assertSame( Severity::WARNING, $report->get( 'orphans' )->severity() );
		$this->assertSame( Severity::INFO, $report->get( 'loopback' )->severity() );
		$this->assertSame( Severity::CRITICAL, $report->get( 'as-past-due' )->severity() );
		$this->assertStringStartsWith( 'CRON CRITICAL - ', $report->status_line() );
	}

	public function test_custom_checks(): void {
		$check = new class() implements Check {
			public function run( Snapshot $snapshot, Thresholds $thresholds ): array {
				return array( new Finding( 'custom', Severity::WARNING, 'Custom.' ) );
			}
		};

		$report = ( new Doctor( array( $check ) ) )->diagnose( Snapshot::from_array( array( 'now' => 1, 'cron' => array() ) ) );

		$this->assertSame( array( 'custom' ), $report->checks() );
		$this->assertSame( 1, $report->exit_code() );
	}

	public function test_check_ids_are_unique(): void {
		$this->assertSame( Doctor::check_ids(), array_values( array_unique( Doctor::check_ids() ) ) );
	}
}
