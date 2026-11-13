<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Cron\Event;

final class EventTest extends TestCase {

	public function test_single_event_defaults(): void {
		$event = new Event( 1000, 'my_hook' );

		$this->assertSame( 1000, $event->timestamp() );
		$this->assertSame( 'my_hook', $event->hook() );
		$this->assertSame( array(), $event->args() );
		$this->assertFalse( $event->schedule() );
		$this->assertNull( $event->interval() );
		$this->assertFalse( $event->is_recurring() );
		$this->assertSame( md5( serialize( array() ) ), $event->key() );
		$this->assertTrue( $event->has_valid_key() );
	}

	public function test_recurring_event(): void {
		$event = new Event( 1000, 'h', array( 1 ), 'hourly', 3600 );

		$this->assertTrue( $event->is_recurring() );
		$this->assertSame( 'hourly', $event->schedule() );
		$this->assertSame( 3600, $event->interval() );
	}

	public function test_empty_schedule_and_zero_interval_are_normalized(): void {
		$event = new Event( 1000, 'h', array(), '', 0 );

		$this->assertFalse( $event->schedule() );
		$this->assertNull( $event->interval() );
	}

	public function test_key_mismatch_is_detected(): void {
		$event = new Event( 1000, 'h', array( 1 ), false, null, 'not-the-hash' );

		$this->assertSame( 'not-the-hash', $event->key() );
		$this->assertFalse( $event->has_valid_key() );
	}

	public function test_signature_ignores_timestamp(): void {
		$a = new Event( 1000, 'h', array( 1 ) );
		$b = new Event( 2000, 'h', array( 1 ) );
		$c = new Event( 1000, 'h', array( 2 ) );

		$this->assertSame( $a->signature(), $b->signature() );
		$this->assertNotSame( $a->signature(), $c->signature() );
	}

	public function test_lateness(): void {
		$event = new Event( 1000, 'h' );

		$this->assertSame( 500, $event->lateness( 1500 ) );
		$this->assertSame( -500, $event->lateness( 500 ) );
	}

	public function test_to_row(): void {
		$row = ( new Event( 1700000000, 'h', array( 'a' ), 'daily', 86400 ) )->to_row( 1700003600 );

		$this->assertSame( 'h', $row['hook'] );
		$this->assertSame( '["a"]', $row['args'] );
		$this->assertSame( '2023-11-14 22:13:20', $row['due'] );
		$this->assertSame( '1h', $row['late'] );
		$this->assertSame( 3600, $row['late_secs'] );
		$this->assertSame( 'daily', $row['schedule'] );
		$this->assertSame( 86400, $row['interval'] );

		$future = ( new Event( 2000, 'h' ) )->to_row( 1000 );
		$this->assertSame( '', $future['late'] );
		$this->assertSame( 0, $future['late_secs'] );
		$this->assertSame( 'single', $future['schedule'] );
		$this->assertSame( '', $future['interval'] );
	}
}
