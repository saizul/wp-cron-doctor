<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\WP;

use Saizul\CronDoctor\Heartbeat\Heartbeat;
use Saizul\CronDoctor\Tests\Stubs\WpState;
use Saizul\CronDoctor\WP\HeartbeatRecorder;

final class HeartbeatRecorderTest extends WpTestCase {

	public function test_record_and_throttle(): void {
		$recorder = new HeartbeatRecorder();

		$this->assertTrue( $recorder->record( 'wp-cli' ) );
		$this->assertSame( 'wp-cli', WpState::$options[ Heartbeat::OPTION ]['source'] );
		$this->assertFalse( $recorder->record( 'wp-cli' ), 'Throttled within a minute.' );
		$this->assertTrue( $recorder->record( 'system' ), 'A new source is written at once.' );

		$recorder->forget();
		$this->assertArrayNotHasKey( Heartbeat::OPTION, WpState::$options );
	}

	public function test_current_request_only_when_doing_cron(): void {
		$recorder = new HeartbeatRecorder();

		$this->assertFalse( $recorder->record_current_request() );

		WpState::$doing_cron = true;
		$this->assertTrue( $recorder->record_current_request() );
		$this->assertSame( 'system', WpState::$options[ Heartbeat::OPTION ]['source'] );
	}

	public function test_spawned_request_is_loopback(): void {
		WpState::$doing_cron      = true;
		$_GET['doing_wp_cron']    = '1700000000.123';

		( new HeartbeatRecorder() )->record_current_request();

		$this->assertSame( 'loopback', WpState::$options[ Heartbeat::OPTION ]['source'] );
	}
}
