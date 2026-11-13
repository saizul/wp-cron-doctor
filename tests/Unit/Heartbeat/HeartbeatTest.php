<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Heartbeat;

use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Heartbeat\Heartbeat;

final class HeartbeatTest extends TestCase {

	public function test_decode(): void {
		$this->assertNull( Heartbeat::decode( false ) );
		$this->assertNull( Heartbeat::decode( array() ) );
		$this->assertNull( Heartbeat::decode( array( 'time' => 'x' ) ) );
		$this->assertNull( Heartbeat::decode( array( 'time' => 0 ) ) );
		$this->assertSame( array( 'time' => 5, 'source' => 'system' ), Heartbeat::decode( array( 'time' => '5' ) ) );
		$this->assertSame( array( 'time' => 5, 'source' => 'wp-cli' ), Heartbeat::decode( array( 'time' => 5, 'source' => 'wp-cli' ) ) );
	}

	public function test_encode_roundtrip(): void {
		$this->assertSame( array( 'time' => 9, 'source' => 'loopback' ), Heartbeat::decode( Heartbeat::encode( 9, 'loopback' ) ) );
	}

	public function test_should_write_throttles_per_source(): void {
		$previous = Heartbeat::encode( 1000, 'system' );

		$this->assertTrue( Heartbeat::should_write( false, 1000, 'system' ) );
		$this->assertFalse( Heartbeat::should_write( $previous, 1030, 'system' ) );
		$this->assertTrue( Heartbeat::should_write( $previous, 1060, 'system' ) );
		$this->assertTrue( Heartbeat::should_write( $previous, 1001, 'wp-cli' ) );
		// A record from the future (clock change) is replaced.
		$this->assertTrue( Heartbeat::should_write( $previous, 900, 'system' ) );
		$this->assertTrue( Heartbeat::should_write( $previous, 1005, 'system', 5 ) );
	}

	public function test_classify(): void {
		$this->assertSame( 'wp-cli', Heartbeat::classify( true, true, true ) );
		$this->assertSame( 'system', Heartbeat::classify( false, false, false ) );
		$this->assertSame( 'system', Heartbeat::classify( false, true, false ) );
		$this->assertSame( 'loopback', Heartbeat::classify( true, false, false ) );
		$this->assertSame( 'alternate', Heartbeat::classify( true, true, false ) );
	}

	public function test_is_due_now_run(): void {
		$this->assertTrue( Heartbeat::is_due_now_run( array( 'due-now' => true ) ) );
		$this->assertTrue( Heartbeat::is_due_now_run( array( 'all' => true ) ) );
		$this->assertFalse( Heartbeat::is_due_now_run( array() ) );
		$this->assertFalse( Heartbeat::is_due_now_run( array( 'due-now' => false ) ) );
	}

	public function test_latest(): void {
		$record = Heartbeat::encode( 1000, 'system' );

		$this->assertNull( Heartbeat::latest( null, null, 2000 ) );
		$this->assertSame( $record, Heartbeat::latest( $record, null, 2000 ) );
		$this->assertSame( $record, Heartbeat::latest( $record, 900.5, 2000 ) );
		$this->assertSame( array( 'time' => 1500, 'source' => 'doing_cron lock' ), Heartbeat::latest( $record, 1500.9, 2000 ) );
		// Locks far in the future are ignored like spawn_cron() resets them.
		$this->assertSame( $record, Heartbeat::latest( $record, 5000.0, 2000 ) );
		$this->assertSame( $record, Heartbeat::latest( $record, 0.0, 2000 ) );
	}
}
