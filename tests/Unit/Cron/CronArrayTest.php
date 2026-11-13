<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Cron\CronArray;
use Saizul\CronDoctor\Cron\Event;
use Saizul\CronDoctor\Tests\Fixtures\CronBuilder;

final class CronArrayTest extends TestCase {

	public function test_parses_the_wordpress_structure(): void {
		$raw = CronBuilder::create()
			->recurring( 2000, 'wp_version_check', 'twicedaily', 43200 )
			->single( 1000, 'my_single', array( 7 ) )
			->recurring( 2000, 'delete_expired_transients', 'daily', 86400 )
			->build();

		$cron = CronArray::from_option( $raw );

		$this->assertSame( 3, $cron->count() );
		$this->assertSame( 2, $cron->version() );
		$this->assertFalse( $cron->is_legacy() );
		$this->assertSame( array(), $cron->problems() );

		$events = $cron->events();
		$this->assertSame( 'my_single', $events[0]->hook() );
		$this->assertSame( array( 7 ), $events[0]->args() );
		// Same timestamp: sorted by hook.
		$this->assertSame( 'delete_expired_transients', $events[1]->hook() );
		$this->assertSame( 'wp_version_check', $events[2]->hook() );
		$this->assertSame( 43200, $events[2]->interval() );
	}

	public function test_multiple_events_per_hook_and_timestamp(): void {
		$raw  = CronBuilder::create()->single( 1000, 'h', array( 1 ) )->single( 1000, 'h', array( 2 ) )->build();
		$cron = CronArray::from_option( $raw );

		$this->assertSame( 2, $cron->count() );
		$this->assertSame( array( 'h' ), $cron->hooks() );
		$this->assertCount( 2, $cron->for_hook( 'h' ) );
		$this->assertCount( 0, $cron->for_hook( 'other' ) );
	}

	/**
	 * @dataProvider empty_values
	 */
	public function test_non_array_is_empty_like_get_cron_array( $raw, int $problems ): void {
		$cron = CronArray::from_option( $raw );

		$this->assertSame( 0, $cron->count() );
		$this->assertCount( $problems, $cron->problems() );
	}

	public function empty_values(): array {
		return array(
			'false (option missing)' => array( false, 0 ),
			'null'                   => array( null, 0 ),
			'empty string'           => array( '', 0 ),
			'string'                 => array( 'garbage', 1 ),
			'int'                    => array( 5, 1 ),
			'only version'           => array( array( 'version' => 2 ), 0 ),
		);
	}

	public function test_legacy_array_is_upgraded_like_upgrade_cron_array(): void {
		// Pre-2.1 format: no key level, no version.
		$raw = array(
			1000 => array(
				'old_hook' => array(
					'schedule' => 'hourly',
					'args'     => array( 'x' ),
					'interval' => 3600,
				),
			),
		);

		$cron = CronArray::from_option( $raw );

		$this->assertTrue( $cron->is_legacy() );
		$this->assertNull( $cron->version() );
		$this->assertSame( 1, $cron->count() );
		$event = $cron->events()[0];
		$this->assertSame( 'old_hook', $event->hook() );
		$this->assertSame( md5( serialize( array( 'x' ) ) ), $event->key() );
		$this->assertTrue( $event->has_valid_key() );
	}

	public function test_unexpected_version_is_reported(): void {
		$cron = CronArray::from_option( array( 'version' => 3 ) );

		$this->assertSame( 3, $cron->version() );
		$this->assertStringContainsString( 'version "3"', $cron->problems()[0] );
	}

	public function test_malformed_entries_are_skipped_and_reported(): void {
		$raw = CronBuilder::create()
			->single( 1000, 'good' )
			->raw( 'junk', array() )
			->raw( 0, array( 'h' => array() ) )
			->raw( 2000, 'not-an-array' )
			->raw( 3000, array() )
			->raw( 4000, array( 'h' => 'string' ) )
			->raw( 5000, array( 'h' => array( 'k' => 'string' ) ) )
			->raw( 6000, array( 'h' => array( 'k' => array( 'args' => 'string' ) ) ) )
			->raw( 7000, array( '' => array( 'k' => array( 'args' => array() ) ) ) )
			->build();

		$cron     = CronArray::from_option( $raw );
		$problems = implode( "\n", $cron->problems() );

		$this->assertSame( 1, $cron->count() );
		$this->assertSame( 'good', $cron->events()[0]->hook() );
		$this->assertCount( 8, $cron->problems() );
		$this->assertStringContainsString( 'Unexpected key "junk"', $problems );
		$this->assertStringContainsString( 'Invalid timestamp 0', $problems );
		$this->assertStringContainsString( 'Timestamp 2000 holds a string', $problems );
		$this->assertStringContainsString( 'Timestamp 3000 has no hooks', $problems );
		$this->assertStringContainsString( 'Hook "h" at 4000 is a string', $problems );
		$this->assertStringContainsString( 'Event "h" at 5000 is a string', $problems );
		$this->assertStringContainsString( 'arguments that are not an array', $problems );
		$this->assertStringContainsString( 'empty hook name', $problems );
	}

	public function test_key_mismatch_is_kept_but_reported(): void {
		$raw = array(
			1000      => array(
				'h' => array(
					'wrong' => array(
						'schedule' => false,
						'args'     => array( 1 ),
					),
				),
			),
			'version' => 2,
		);

		$cron = CronArray::from_option( $raw );

		$this->assertSame( 1, $cron->count() );
		$this->assertFalse( $cron->events()[0]->has_valid_key() );
		$this->assertStringContainsString( 'cannot remove it', $cron->problems()[0] );
	}

	public function test_recurring_event_without_interval_is_reported(): void {
		$raw = array(
			1000      => array(
				'h' => array(
					md5( serialize( array() ) ) => array(
						'schedule' => 'hourly',
						'args'     => array(),
					),
				),
			),
			'version' => 2,
		);

		$cron = CronArray::from_option( $raw );

		$this->assertSame( 1, $cron->count() );
		$this->assertStringContainsString( 'no stored interval', $cron->problems()[0] );
	}

	public function test_missing_args_key_means_no_arguments(): void {
		$raw  = array(
			1000      => array( 'h' => array( md5( serialize( array() ) ) => array( 'schedule' => false ) ) ),
			'version' => 2,
		);
		$cron = CronArray::from_option( $raw );

		$this->assertSame( array(), $cron->events()[0]->args() );
		$this->assertSame( array(), $cron->problems() );
	}

	public function test_string_timestamps_are_accepted(): void {
		$raw  = array(
			'1000'    => array( 'h' => array( md5( serialize( array() ) ) => array( 'schedule' => false, 'args' => array() ) ) ),
			'version' => 2,
		);
		$cron = CronArray::from_option( $raw );

		$this->assertSame( 1000, $cron->events()[0]->timestamp() );
	}

	public function test_ready_matches_wp_get_ready_cron_jobs(): void {
		$cron = CronArray::from_option(
			CronBuilder::create()->single( 1000, 'a' )->single( 2000, 'b' )->single( 3000, 'c' )->build()
		);

		$this->assertCount( 0, $cron->ready( 999 ) );
		$this->assertCount( 1, $cron->ready( 1000 ) );
		$this->assertCount( 2, $cron->ready( 2500 ) );
		$this->assertCount( 3, $cron->ready( 9999 ) );
	}

	public function test_from_events_sorts(): void {
		$cron = CronArray::from_events( array( new Event( 30, 'z' ), new Event( 10, 'b' ), new Event( 10, 'a' ) ) );

		$this->assertSame( array( 'a', 'b', 'z' ), array_map( static fn( Event $e ) => $e->hook(), $cron->events() ) );
		$this->assertSame( 2, $cron->version() );
	}

	public function test_hooks_are_unique_and_sorted_including_numeric_names(): void {
		$cron = CronArray::from_events( array( new Event( 1, 'b' ), new Event( 2, '123' ), new Event( 3, 'b' ) ) );

		$this->assertSame( array( '123', 'b' ), $cron->hooks() );
	}
}
