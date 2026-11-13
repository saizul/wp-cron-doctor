<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Report;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Report\Finding;
use Saizul\CronDoctor\Report\Severity;

final class FindingTest extends TestCase {

	public function test_accessors_and_row(): void {
		$finding = new Finding( 'overdue', Severity::WARNING, 'Late.', 3, array( 5 => array( 'hook' => 'h' ) ), 'Fix it.' );

		$this->assertSame( 'overdue', $finding->check() );
		$this->assertSame( Severity::WARNING, $finding->severity() );
		$this->assertSame( 'Late.', $finding->summary() );
		$this->assertSame( 3, $finding->value() );
		$this->assertSame( array( array( 'hook' => 'h' ) ), $finding->items() );
		$this->assertSame( 'Fix it.', $finding->advice() );
		$this->assertTrue( $finding->is_problem() );

		$this->assertSame(
			array(
				'check'   => 'overdue',
				'status'  => 'warning',
				'value'   => 3,
				'summary' => 'Late.',
				'advice'  => 'Fix it.',
				'items'   => 1,
				'details' => array( array( 'hook' => 'h' ) ),
			),
			$finding->to_row()
		);
	}

	public function test_null_value_becomes_empty_string_in_row(): void {
		$finding = new Finding( 'x', Severity::INFO, 'Info.' );

		$this->assertSame( '', $finding->to_row()['value'] );
		$this->assertFalse( $finding->is_problem() );
	}

	public function test_rejects_unknown_severity(): void {
		$this->expectException( InvalidArgumentException::class );
		new Finding( 'x', 'bad', 'Nope.' );
	}
}
