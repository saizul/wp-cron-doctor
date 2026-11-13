<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Report;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Report\Finding;
use Saizul\CronDoctor\Report\Report;
use Saizul\CronDoctor\Report\Severity;

final class ReportTest extends TestCase {

	private function report( string ...$severities ): Report {
		$report = new Report( 1000 );
		foreach ( $severities as $index => $severity ) {
			$report->add( new Finding( 'check' . $index, $severity, 'Summary ' . $index, $index ) );
		}
		return $report;
	}

	public function test_empty_report_is_ok(): void {
		$report = new Report( 1000 );

		$this->assertSame( Severity::OK, $report->worst() );
		$this->assertSame( 0, $report->exit_code() );
		$this->assertSame( 'CRON OK - all checks passed', $report->status_line() );
		$this->assertSame( 1000, $report->generated_at() );
	}

	public function test_worst_and_totals(): void {
		$report = $this->report( Severity::OK, Severity::WARNING, Severity::INFO, Severity::WARNING );

		$this->assertSame( Severity::WARNING, $report->worst() );
		$this->assertSame(
			array(
				'ok'       => 1,
				'info'     => 1,
				'warning'  => 2,
				'critical' => 0,
			),
			$report->totals()
		);
	}

	public function test_same_check_replaces_earlier_finding(): void {
		$report = new Report( 1 );
		$report->add( new Finding( 'a', Severity::CRITICAL, 'First' ) );
		$report->add( new Finding( 'a', Severity::OK, 'Second' ) );

		$this->assertCount( 1, $report->findings() );
		$this->assertSame( 'Second', $report->get( 'a' )->summary() );
		$this->assertNull( $report->get( 'missing' ) );
		$this->assertSame( array( 'a' ), $report->checks() );
	}

	/**
	 * @dataProvider exit_codes
	 */
	public function test_exit_code( array $severities, string $fail_on, int $expected ): void {
		$this->assertSame( $expected, $this->report( ...$severities )->exit_code( $fail_on ) );
	}

	public function exit_codes(): array {
		return array(
			'ok'                        => array( array( 'ok', 'info' ), 'warning', 0 ),
			'warning fails'             => array( array( 'ok', 'warning' ), 'warning', 1 ),
			'critical fails'            => array( array( 'warning', 'critical' ), 'warning', 2 ),
			'warning ignored'           => array( array( 'warning' ), 'critical', 0 ),
			'critical with critical'    => array( array( 'warning', 'critical' ), 'critical', 2 ),
			'never'                     => array( array( 'critical' ), 'never', 0 ),
		);
	}

	public function test_invalid_fail_on(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->report( Severity::OK )->exit_code( 'info' );
	}

	public function test_filter_and_rows(): void {
		$report = $this->report( Severity::OK, Severity::CRITICAL, Severity::INFO );

		$this->assertCount( 3, $report->rows() );
		$problems = $report->rows( Severity::WARNING );
		$this->assertCount( 1, $problems );
		$this->assertSame( 'check1', $problems[0]['check'] );
		$this->assertCount( 2, $report->filter( Severity::INFO ) );
	}

	public function test_status_line_lists_problems_and_perfdata(): void {
		$report = new Report( 1 );
		$report->add( new Finding( 'overdue', Severity::CRITICAL, 'x', 3 ) );
		$report->add( new Finding( 'duplicates', Severity::WARNING, 'x', 1 ) );
		$report->add( new Finding( 'orphans', Severity::WARNING, 'x', 2 ) );
		$report->add( new Finding( 'loopback', Severity::OK, 'x', 0.25 ) );
		$report->add( new Finding( 'runner', Severity::OK, 'x', null ) );
		$report->add( new Finding( 'text', Severity::OK, 'x', 'abc' ) );

		$this->assertSame(
			'CRON CRITICAL - 1 critical, 2 warnings: overdue, duplicates, orphans | overdue=3 duplicates=1 orphans=2 loopback=0.25',
			$report->status_line()
		);
	}

	public function test_status_line_singular_warning_and_info_counts_as_ok(): void {
		$report = new Report( 1 );
		$report->add( new Finding( 'a', Severity::WARNING, 'x' ) );
		$this->assertSame( 'CRON WARNING - 1 warning: a', $report->status_line() );

		$info = new Report( 1 );
		$info->add( new Finding( 'a', Severity::INFO, 'x' ) );
		$this->assertSame( 'CRON OK - all checks passed', $info->status_line() );
		$this->assertSame( Severity::INFO, $info->worst() );
		$this->assertSame( Severity::OK, $info->status() );
	}
}
