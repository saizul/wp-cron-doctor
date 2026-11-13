<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Report;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Report\Severity;

final class SeverityTest extends TestCase {

	public function test_order(): void {
		$this->assertSame( array( 'ok', 'info', 'warning', 'critical' ), Severity::all() );
		$this->assertLessThan( Severity::rank( Severity::WARNING ), Severity::rank( Severity::INFO ) );
		$this->assertSame( Severity::CRITICAL, Severity::max( Severity::WARNING, Severity::CRITICAL ) );
		$this->assertSame( Severity::WARNING, Severity::max( Severity::WARNING, Severity::OK ) );
		$this->assertTrue( Severity::at_least( Severity::CRITICAL, Severity::WARNING ) );
		$this->assertFalse( Severity::at_least( Severity::INFO, Severity::WARNING ) );
	}

	public function test_exit_codes_follow_nagios(): void {
		$this->assertSame( 0, Severity::exit_code( Severity::OK ) );
		$this->assertSame( 0, Severity::exit_code( Severity::INFO ) );
		$this->assertSame( 1, Severity::exit_code( Severity::WARNING ) );
		$this->assertSame( 2, Severity::exit_code( Severity::CRITICAL ) );
		$this->assertSame( 3, Severity::EXIT_UNKNOWN );
	}

	public function test_unknown_severity(): void {
		$this->assertFalse( Severity::is_valid( 'fatal' ) );
		$this->expectException( InvalidArgumentException::class );
		Severity::exit_code( 'fatal' );
	}
}
