<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Report;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Report\Baseline;
use Saizul\CronDoctor\Report\Finding;
use Saizul\CronDoctor\Report\Report;
use Saizul\CronDoctor\Report\Severity;

final class BaselineTest extends TestCase {

	/**
	 * @param string[] $hooks
	 */
	private function orphans( string $severity, array $hooks ): Finding {
		$items = array();
		foreach ( $hooks as $index => $hook ) {
			$items[] = array(
				'hook'   => $hook,
				'events' => $index + 1,
				'next'   => gmdate( 'Y-m-d H:i:s', 1000 + $index ),
			);
		}
		return new Finding( 'orphans', $severity, count( $hooks ) . ' orphaned hooks.', count( $hooks ), $items, 'Remove them.' );
	}

	private function report( int $now, Finding ...$findings ): Report {
		$report = new Report( $now );
		$report->add( new Finding( 'runner', Severity::OK, 'Runner fine.' ) );
		foreach ( $findings as $finding ) {
			$report->add( $finding );
		}
		return $report;
	}

	public function test_from_report_records_problems_only(): void {
		$report = $this->report(
			1000,
			$this->orphans( Severity::WARNING, array( 'b_hook', 'a_hook', 'a_hook' ) ),
			new Finding( 'option-size', Severity::CRITICAL, 'Huge.', 2000000 ),
			new Finding( 'frequent', Severity::INFO, 'Some.' )
		);

		$baseline = Baseline::from_report( $report );

		$this->assertSame( array( 'option-size', 'orphans' ), $baseline->checks() );
		$data = $baseline->to_array();
		$this->assertSame( Baseline::FORMAT, $data['format'] );
		$this->assertSame( 1, $data['version'] );
		$this->assertSame( 1000, $data['created_at'] );
		$this->assertSame( '1970-01-01T00:16:40Z', $data['created'] );
		$this->assertNull( $data['expires_at'] );
		$checks = (array) $data['checks'];
		$this->assertSame( array( 'a_hook', 'b_hook' ), $checks['orphans']['items'] );
		$this->assertSame( 2000000, $checks['option-size']['value'] );
		$this->assertSame( array(), $checks['option-size']['items'] );
	}

	public function test_from_report_can_limit_checks(): void {
		$report = $this->report(
			1000,
			$this->orphans( Severity::WARNING, array( 'x' ) ),
			new Finding( 'option-size', Severity::CRITICAL, 'Huge.' )
		);

		$this->assertSame( array( 'orphans' ), Baseline::from_report( $report, null, array( 'orphans', 'duplicates' ) )->checks() );
	}

	public function test_json_round_trip(): void {
		$baseline = Baseline::from_report( $this->report( 1000, $this->orphans( Severity::WARNING, array( 'x' ) ) ), 5000 );
		$copy     = Baseline::from_json( $baseline->to_json() );

		$this->assertEquals( $baseline->to_array()['checks'], $copy->to_array()['checks'] );
		$this->assertSame( 5000, $copy->expires_at() );
		$this->assertSame( 1000, $copy->created_at() );
		$this->assertStringEndsWith( "}\n", $baseline->to_json() );
	}

	public function test_empty_baseline_serialises_checks_as_object(): void {
		$json = Baseline::from_report( $this->report( 1000 ) )->to_json();

		$this->assertStringContainsString( '"checks": {}', $json );
		$this->assertSame( array(), Baseline::from_json( $json )->checks() );
	}

	public function test_known_problem_is_downgraded_to_info(): void {
		$baseline = Baseline::from_report( $this->report( 1000, $this->orphans( Severity::WARNING, array( 'a', 'b' ) ) ) );

		$later  = $this->report( 2000, $this->orphans( Severity::WARNING, array( 'b' ) ) );
		$result = $baseline->apply( $later );

		$finding = $result->get( 'orphans' );
		$this->assertSame( Severity::INFO, $finding->severity() );
		$this->assertSame( Baseline::ACCEPTED_PREFIX . '1 orphaned hooks.', $finding->summary() );
		$this->assertSame( 1, $finding->value(), 'The metric is kept for performance data.' );
		$this->assertSame( 'Remove them.', $finding->advice() );
		$this->assertSame( 0, $result->exit_code() );
		$this->assertSame( Severity::OK, $result->status() );
		$this->assertSame( 2000, $result->generated_at() );
		$this->assertSame( array( 'runner', 'orphans' ), $result->checks(), 'Order is kept.' );
		$this->assertSame( Severity::WARNING, $later->get( 'orphans' )->severity(), 'The input report is not changed.' );
	}

	public function test_new_items_keep_severity(): void {
		$baseline = Baseline::from_report( $this->report( 1000, $this->orphans( Severity::WARNING, array( 'a' ) ) ) );

		$result  = $baseline->apply( $this->report( 2000, $this->orphans( Severity::WARNING, array( 'a', 'c', 'b' ) ) ) );
		$finding = $result->get( 'orphans' );

		$this->assertSame( Severity::WARNING, $finding->severity() );
		$this->assertSame( '3 orphaned hooks. (new since baseline: b, c)', $finding->summary() );
		$this->assertSame( 1, $result->exit_code() );
	}

	public function test_many_new_items_are_truncated(): void {
		$baseline = Baseline::from_report( $this->report( 1000, $this->orphans( Severity::WARNING, array( 'a' ) ) ) );
		$hooks    = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'h7' );

		$finding = $baseline->apply( $this->report( 2000, $this->orphans( Severity::WARNING, $hooks ) ) )->get( 'orphans' );

		$this->assertStringEndsWith( '(new since baseline: h1, h2, h3, h4, h5 and 2 more)', $finding->summary() );
	}

	public function test_worse_severity_is_reported(): void {
		$baseline = Baseline::from_report( $this->report( 1000, new Finding( 'overdue', Severity::WARNING, 'Late.', 900 ) ) );

		$finding = $baseline->apply( $this->report( 2000, new Finding( 'overdue', Severity::CRITICAL, 'Very late.', 9000 ) ) )->get( 'overdue' );

		$this->assertSame( Severity::CRITICAL, $finding->severity() );
		$this->assertSame( 'Very late. (worse than baseline: was warning)', $finding->summary() );
	}

	public function test_better_severity_is_accepted(): void {
		$baseline = Baseline::from_report( $this->report( 1000, new Finding( 'overdue', Severity::CRITICAL, 'Very late.' ) ) );

		$finding = $baseline->apply( $this->report( 2000, new Finding( 'overdue', Severity::WARNING, 'Late.' ) ) )->get( 'overdue' );

		$this->assertSame( Severity::INFO, $finding->severity() );
	}

	public function test_unknown_check_is_unchanged(): void {
		$baseline = Baseline::from_report( $this->report( 1000, new Finding( 'overdue', Severity::WARNING, 'Late.' ) ) );

		$result = $baseline->apply( $this->report( 2000, new Finding( 'duplicates', Severity::WARNING, 'Dupes.' ) ) );

		$this->assertSame( Severity::WARNING, $result->get( 'duplicates' )->severity() );
		$this->assertSame( 'Dupes.', $result->get( 'duplicates' )->summary() );
	}

	public function test_expired_baseline_changes_nothing(): void {
		$baseline = Baseline::from_report( $this->report( 1000, new Finding( 'overdue', Severity::WARNING, 'Late.' ) ), 1500 );
		$later    = $this->report( 1500, new Finding( 'overdue', Severity::WARNING, 'Late.' ) );

		$this->assertFalse( $baseline->is_expired( 1499 ) );
		$this->assertTrue( $baseline->is_expired( 1500 ) );
		$this->assertSame( $later, $baseline->apply( $later ) );
	}

	public function test_resolved_lists_fixed_checks(): void {
		$baseline = Baseline::from_report(
			$this->report(
				1000,
				new Finding( 'overdue', Severity::WARNING, 'Late.' ),
				$this->orphans( Severity::WARNING, array( 'a' ) )
			)
		);

		$later = $this->report(
			2000,
			new Finding( 'overdue', Severity::OK, 'Nothing late.' ),
			$this->orphans( Severity::WARNING, array( 'a' ) )
		);

		$this->assertSame( array( 'overdue' ), $baseline->resolved( $later ) );
	}

	public function test_item_keys_use_identity_fields_only(): void {
		$this->assertSame( 'hook_a []', Baseline::item_key( array( 'hook' => 'hook_a', 'args' => '[]', 'copies' => 4, 'next' => 'x' ) ) );
		$this->assertSame( 'Event without hook.', Baseline::item_key( array( 'problem' => 'Event without hook.' ) ) );
		$this->assertNull( Baseline::item_key( array( 'status' => 'pending', 'actions' => 4 ) ) );
		$this->assertNull( Baseline::item_key( array( 'hook' => '' ) ) );
		$this->assertSame( array( 'a', 'b' ), Baseline::item_keys( array( array( 'hook' => 'b' ), array( 'url' => 'x' ), array( 'hook' => 'a' ), array( 'hook' => 'b' ) ) ) );
	}

	/**
	 * @dataProvider invalid_documents
	 */
	public function test_invalid_documents_are_rejected( string $json, string $message ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		Baseline::from_json( $json );
	}

	public function invalid_documents(): array {
		$ok = array(
			'format'     => Baseline::FORMAT,
			'version'    => 1,
			'created_at' => 1000,
			'checks'     => array(),
		);

		$cases = array(
			'not json'       => array( '{', 'not valid JSON' ),
			'wrong format'   => array( array( 'format' => 'other' ) + $ok, 'Not a baseline file' ),
			'wrong version'  => array( array( 'version' => 2 ) + $ok, 'Unsupported baseline version' ),
			'no created_at'  => array( array( 'created_at' => '1000' ) + $ok, '"created_at"' ),
			'bad expires'    => array( array( 'expires_at' => 'soon' ) + $ok, '"expires_at"' ),
			'expires early'  => array( array( 'expires_at' => 900 ) + $ok, 'must expire after' ),
			'no checks'      => array( array( 'checks' => 'all' ) + $ok, '"checks" object' ),
			'no severity'    => array( array( 'checks' => array( 'overdue' => array( 'items' => array() ) ) ) + $ok, 'needs a "severity"' ),
			'ok severity'    => array( array( 'checks' => array( 'overdue' => array( 'severity' => 'ok' ) ) ) + $ok, 'warning or critical' ),
			'bad items'      => array( array( 'checks' => array( 'overdue' => array( 'severity' => 'warning', 'items' => 'x' ) ) ) + $ok, 'invalid "items"' ),
		);

		foreach ( $cases as $name => $case ) {
			if ( is_array( $case[0] ) ) {
				$cases[ $name ][0] = (string) json_encode( $case[0] );
			}
		}
		return $cases;
	}

	public function test_lenient_values_are_normalised(): void {
		$baseline = Baseline::from_array(
			array(
				'format'     => Baseline::FORMAT,
				'version'    => 1,
				'created_at' => 1000,
				'checks'     => array(
					'orphans' => array(
						'severity' => 'warning',
						'value'    => array( 'nested' ),
						'items'    => array( 'a', 'a', array( 'x' ), 7 ),
					),
				),
			)
		);

		$entry = ( (array) $baseline->to_array()['checks'] )['orphans'];
		$this->assertNull( $entry['value'] );
		$this->assertSame( array( 'a', '7' ), $entry['items'] );
	}
}
