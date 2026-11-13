<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Check;

use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Check\LoopbackCheck;
use Saizul\CronDoctor\Loopback\LoopbackResult;
use Saizul\CronDoctor\Report\Finding;
use Saizul\CronDoctor\Report\Severity;
use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\Snapshot;

final class LoopbackCheckTest extends TestCase {

	private const URL = 'https://example.com/wp-cron.php';

	private function finding( ?LoopbackResult $result, bool $disabled = false ): Finding {
		$snapshot = Snapshot::from_array(
			array(
				'now'             => 1,
				'cron'            => array(),
				'loopback'        => $result,
				'disable_wp_cron' => $disabled,
			)
		);
		return ( new LoopbackCheck() )->run( $snapshot, new Thresholds() )[0];
	}

	public function test_skipped(): void {
		$this->assertSame( Severity::INFO, $this->finding( null )->severity() );
		$finding = $this->finding( LoopbackResult::skipped( 'disabled with --skip-loopback' ) );
		$this->assertSame( 'Loopback test skipped: disabled with --skip-loopback.', $finding->summary() );
	}

	public function test_success(): void {
		$finding = $this->finding( LoopbackResult::response( self::URL, 200, 0.123 ) );

		$this->assertSame( Severity::OK, $finding->severity() );
		$this->assertSame( 'wp-cron.php is reachable (HTTP 200 in 0.12s).', $finding->summary() );
		$this->assertSame( 0.123, $finding->value() );
		$this->assertSame( self::URL, $finding->items()[0]['url'] );
	}

	public function test_slow_success_is_warning(): void {
		$this->assertSame( Severity::WARNING, $this->finding( LoopbackResult::response( self::URL, 200, 7.5 ) )->severity() );
	}

	public function test_redirect_is_warning_with_target(): void {
		$finding = $this->finding( LoopbackResult::response( self::URL, 301, 0.1, 'https://www.example.com/wp-cron.php' ) );

		$this->assertSame( Severity::WARNING, $finding->severity() );
		$this->assertStringContainsString( 'HTTP 301 to https://www.example.com/wp-cron.php', $finding->summary() );
	}

	/**
	 * @dataProvider critical_statuses
	 */
	public function test_critical_statuses( int $status, string $text ): void {
		$finding = $this->finding( LoopbackResult::response( self::URL, $status, 0.1 ) );

		$this->assertSame( Severity::CRITICAL, $finding->severity() );
		$this->assertStringContainsString( $text, $finding->summary() );
	}

	public function critical_statuses(): array {
		return array(
			array( 401, 'blocked (HTTP 401)' ),
			array( 403, 'blocked (HTTP 403)' ),
			array( 404, 'not found' ),
			array( 429, 'rate limited' ),
			array( 500, 'returned HTTP 500' ),
			array( 503, 'returned HTTP 503' ),
		);
	}

	public function test_transport_failures(): void {
		$timeout = $this->finding( LoopbackResult::failure( self::URL, 'cURL error 28: Operation timed out after 10001 milliseconds', 10.0 ) );
		$this->assertSame( Severity::CRITICAL, $timeout->severity() );
		$this->assertStringContainsString( 'timed out after 10.00s', $timeout->summary() );

		$dns = $this->finding( LoopbackResult::failure( self::URL, 'cURL error 6: Could not resolve host', 0.01 ) );
		$this->assertSame( Severity::CRITICAL, $dns->severity() );
		$this->assertStringContainsString( 'Could not resolve host', $dns->summary() );
		$this->assertSame( '', $dns->items()[0]['status'] );
	}

	public function test_problems_are_info_when_wp_cron_is_disabled(): void {
		$finding = $this->finding( LoopbackResult::response( self::URL, 403, 0.1 ), true );

		$this->assertSame( Severity::INFO, $finding->severity() );
		$this->assertStringContainsString( 'does not depend on it', $finding->summary() );

		$this->assertSame( Severity::OK, $this->finding( LoopbackResult::response( self::URL, 200, 0.1 ), true )->severity() );
	}
}
