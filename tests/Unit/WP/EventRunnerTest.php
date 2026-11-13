<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\WP;

use RuntimeException;
use Saizul\CronDoctor\Cron\CronArray;
use Saizul\CronDoctor\Cron\Event;
use Saizul\CronDoctor\Tests\Fixtures\CronBuilder;
use Saizul\CronDoctor\Tests\Stubs\WpState;
use Saizul\CronDoctor\WP\EventRunner;
use WP_Error;

final class EventRunnerTest extends WpTestCase {

	public function test_unschedule_removes_exactly_one_event(): void {
		WpState::$options['cron'] = CronBuilder::create()->single( 100, 'a', array( 1 ) )->single( 200, 'a', array( 1 ) )->build();

		$this->assertTrue( ( new EventRunner() )->unschedule( new Event( 200, 'a', array( 1 ) ) ) );

		$cron = CronArray::from_option( WpState::$options['cron'] );
		$this->assertSame( 1, $cron->count() );
		$this->assertSame( 100, $cron->events()[0]->timestamp() );
	}

	public function test_unschedule_reports_errors(): void {
		WpState::$options['cron'] = array( 'version' => 2 );

		$this->assertSame( 'The cron event list could not be saved.', ( new EventRunner() )->unschedule( new Event( 1, 'a' ) ) );

		WpState::$unschedule_result = false;
		$this->assertSame( 'wp_unschedule_event() failed.', ( new EventRunner() )->unschedule( new Event( 1, 'a' ) ) );
	}

	public function test_unschedule_refuses_key_mismatch(): void {
		$this->assertStringContainsString( 'cannot remove', ( new EventRunner() )->unschedule( new Event( 1, 'a', array(), false, null, 'bad' ) ) );
	}

	public function test_run_single_event(): void {
		WpState::$options['cron'] = CronBuilder::create()->single( 100, 'send_mail', array( 'id' => 9 ) )->build();
		$seen                     = array();
		add_action(
			'send_mail',
			static function ( $id ) use ( &$seen ) {
				$seen[] = array( $id, wp_doing_cron() );
			}
		);

		$result = ( new EventRunner() )->run( new Event( 100, 'send_mail', array( 'id' => 9 ) ) );

		$this->assertSame( 'ran', $result['status'] );
		$this->assertSame( '', $result['error'] );
		$this->assertSame( array( array( 9, true ) ), $seen );
		$this->assertFalse( wp_doing_cron(), 'The wp_doing_cron filter is removed again.' );
		$this->assertSame( 0, CronArray::from_option( WpState::$options['cron'] )->count() );
	}

	public function test_run_recurring_event_reschedules_first(): void {
		$past                     = time() - 7200 - 10;
		WpState::$options['cron'] = CronBuilder::create()->recurring( $past, 'tick', 'hourly', 3600 )->build();

		$result = ( new EventRunner() )->run( new Event( $past, 'tick', array(), 'hourly', 3600 ) );

		$this->assertSame( 'ran', $result['status'] );
		$events = CronArray::from_option( WpState::$options['cron'] )->events();
		$this->assertCount( 1, $events );
		$this->assertGreaterThan( time(), $events[0]->timestamp() );
		$this->assertSame( 0, ( $events[0]->timestamp() - $past ) % 3600 );
		$this->assertSame( array( array( 'tick', array() ) ), WpState::$fired );
	}

	public function test_failed_reschedule_still_runs_like_wp_cron(): void {
		WpState::$options['cron'] = CronBuilder::create()->recurring( 100, 'orphan_schedule', 'gone', 60 )->build();

		$result = ( new EventRunner() )->run( new Event( 100, 'orphan_schedule', array(), 'gone', 60 ) );

		$this->assertSame( 'warning', $result['status'] );
		$this->assertStringContainsString( 'Not rescheduled: Event schedule does not exist.', $result['error'] );
		$this->assertCount( 1, WpState::$fired );
	}

	public function test_hook_is_not_fired_when_unschedule_fails(): void {
		WpState::$unschedule_result = new WP_Error( 'x', 'Denied by pre_unschedule_event.' );

		$result = ( new EventRunner() )->run( new Event( 100, 'a' ) );

		$this->assertSame( 'error', $result['status'] );
		$this->assertSame( 'Unschedule failed: Denied by pre_unschedule_event.', $result['error'] );
		$this->assertSame( array(), WpState::$fired );
	}

	public function test_exceptions_are_caught(): void {
		WpState::$options['cron'] = CronBuilder::create()->single( 100, 'boom' )->build();
		add_action(
			'boom',
			static function () {
				throw new RuntimeException( 'Kaboom' );
			}
		);

		$result = ( new EventRunner() )->run( new Event( 100, 'boom' ) );

		$this->assertSame( 'error', $result['status'] );
		$this->assertSame( 'RuntimeException: Kaboom', $result['error'] );
		$this->assertFalse( wp_doing_cron() );
	}
}
