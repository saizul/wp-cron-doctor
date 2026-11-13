<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Check;

use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Check\RunnerCheck;
use Saizul\CronDoctor\Report\Finding;
use Saizul\CronDoctor\Report\Severity;
use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\Snapshot;
use Saizul\CronDoctor\Tests\Fixtures\CronBuilder;

final class RunnerCheckTest extends TestCase {

	private const NOW = 1700000000;

	/**
	 * @return array<string, Finding>
	 */
	private function findings( array $values ): array {
		$values += array(
			'now'  => self::NOW,
			'cron' => array(),
		);
		$out = array();
		foreach ( ( new RunnerCheck() )->run( Snapshot::from_array( $values ), new Thresholds() ) as $finding ) {
			$out[ $finding->check() ] = $finding;
		}
		return $out;
	}

	public function test_returns_three_findings(): void {
		$this->assertSame( array( 'runner', 'alternate-cron', 'cron-lock' ), array_keys( $this->findings( array() ) ) );
	}

	public function test_disabled_with_recent_heartbeat_is_ok(): void {
		$runner = $this->findings(
			array(
				'disable_wp_cron' => true,
				'last_run'        => self::NOW - 120,
				'last_run_source' => 'wp-cli',
			)
		)['runner'];

		$this->assertSame( Severity::OK, $runner->severity() );
		$this->assertStringContainsString( 'last run seen 2m ago (wp-cli)', $runner->summary() );
		$this->assertSame( 120, $runner->value() );
		$this->assertSame( 'yes', $runner->items()[0]['disable_wp_cron'] );
	}

	public function test_disabled_with_stale_heartbeat_is_critical(): void {
		$runner = $this->findings(
			array(
				'disable_wp_cron' => true,
				'last_run'        => self::NOW - 7200,
			)
		)['runner'];

		$this->assertSame( Severity::CRITICAL, $runner->severity() );
		$this->assertStringContainsString( 'has not run recently', $runner->summary() );
		$this->assertStringContainsString( 'system-cron', $runner->advice() );
	}

	public function test_disabled_without_evidence_and_very_late_events_is_critical(): void {
		$runner = $this->findings(
			array(
				'disable_wp_cron' => true,
				'cron'            => CronBuilder::create()->single( self::NOW - 7200, 'a' )->build(),
			)
		)['runner'];

		$this->assertSame( Severity::CRITICAL, $runner->severity() );
		$this->assertStringContainsString( 'no system cron detected', $runner->summary() );
	}

	public function test_disabled_without_evidence_and_late_events_is_warning(): void {
		$runner = $this->findings(
			array(
				'disable_wp_cron' => true,
				'cron'            => CronBuilder::create()->single( self::NOW - 900, 'a' )->build(),
			)
		)['runner'];

		$this->assertSame( Severity::WARNING, $runner->severity() );
	}

	public function test_disabled_without_evidence_but_nothing_late_is_info(): void {
		$runner = $this->findings(
			array(
				'disable_wp_cron' => true,
				'cron'            => CronBuilder::create()->single( self::NOW + 60, 'a' )->build(),
			)
		)['runner'];

		$this->assertSame( Severity::INFO, $runner->severity() );
	}

	public function test_lock_counts_as_evidence(): void {
		$runner = $this->findings(
			array(
				'disable_wp_cron' => true,
				'cron_lock'       => self::NOW - 30.5,
			)
		)['runner'];

		$this->assertSame( Severity::OK, $runner->severity() );
		$this->assertStringContainsString( 'doing_cron lock', $runner->summary() );
	}

	public function test_visit_based_cron_ok(): void {
		$runner = $this->findings( array() )['runner'];

		$this->assertSame( Severity::OK, $runner->severity() );
		$this->assertStringContainsString( 'triggered by page visits', $runner->summary() );
		$this->assertSame( 'no', $runner->items()[0]['disable_wp_cron'] );
	}

	public function test_visit_based_cron_with_late_events_and_no_recent_run_is_warning(): void {
		$runner = $this->findings(
			array(
				'cron'     => CronBuilder::create()->single( self::NOW - 1800, 'a' )->build(),
				'last_run' => self::NOW - 86400,
			)
		)['runner'];

		$this->assertSame( Severity::WARNING, $runner->severity() );
		$this->assertStringContainsString( 'visits are not triggering cron', $runner->summary() );
	}

	public function test_visit_based_cron_with_late_events_but_recent_run_is_ok(): void {
		$runner = $this->findings(
			array(
				'cron'     => CronBuilder::create()->single( self::NOW - 1800, 'a' )->build(),
				'last_run' => self::NOW - 60,
			)
		)['runner'];

		$this->assertSame( Severity::OK, $runner->severity() );
	}

	public function test_alternate_cron(): void {
		$this->assertSame( Severity::OK, $this->findings( array() )['alternate-cron']->severity() );
		$this->assertSame( Severity::WARNING, $this->findings( array( 'alternate_wp_cron' => true ) )['alternate-cron']->severity() );
		$this->assertSame(
			Severity::INFO,
			$this->findings(
				array(
					'alternate_wp_cron' => true,
					'disable_wp_cron'   => true,
				)
			)['alternate-cron']->severity()
		);
	}

	public function test_lock_states(): void {
		$this->assertSame( 'No cron lock is held.', $this->findings( array() )['cron-lock']->summary() );

		$fresh = $this->findings( array( 'cron_lock' => self::NOW - 20.0 ) )['cron-lock'];
		$this->assertSame( Severity::OK, $fresh->severity() );
		$this->assertSame( 20, $fresh->value() );

		$stale = $this->findings( array( 'cron_lock' => self::NOW - 3600.0 ) )['cron-lock'];
		$this->assertSame( Severity::WARNING, $stale->severity() );
		$this->assertStringContainsString( 'never released', $stale->summary() );

		$future = $this->findings( array( 'cron_lock' => self::NOW + 3600.0 ) )['cron-lock'];
		$this->assertSame( Severity::WARNING, $future->severity() );
		$this->assertStringContainsString( 'in the future', $future->summary() );
	}

	public function test_last_run_prefers_newest_evidence(): void {
		$snapshot = Snapshot::from_array(
			array(
				'now'             => self::NOW,
				'cron'            => array(),
				'last_run'        => self::NOW - 500,
				'last_run_source' => 'system',
				'cron_lock'       => self::NOW - 100.0,
			)
		);

		$this->assertSame(
			array(
				'time'   => self::NOW - 100,
				'source' => 'doing_cron lock',
			),
			RunnerCheck::last_run( $snapshot )
		);
	}
}
