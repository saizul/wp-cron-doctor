<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Cron\CronArray;
use Saizul\CronDoctor\Snapshot;

final class SnapshotTest extends TestCase {

	public function test_from_array(): void {
		$snapshot = Snapshot::from_array(
			array(
				'now'             => '100',
				'cron'            => array( 'version' => 2 ),
				'disable_wp_cron' => true,
				'callbacks'       => array( 'a' => false ),
			)
		);

		$this->assertSame( 100, $snapshot->now );
		$this->assertTrue( $snapshot->disable_wp_cron );
		$this->assertFalse( $snapshot->has_callback( 'a' ) );
		$this->assertTrue( $snapshot->has_callback( 'not-looked-up' ) );
	}

	public function test_accepts_cron_array_object(): void {
		$cron = CronArray::from_option( array() );

		$this->assertSame( $cron, Snapshot::from_array( array( 'now' => 1, 'cron' => $cron ) )->cron );
	}

	public function test_requires_now_and_cron(): void {
		$this->expectException( InvalidArgumentException::class );
		Snapshot::from_array( array( 'now' => 1 ) );
	}

	public function test_rejects_unknown_fields(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown snapshot field "typo"' );
		Snapshot::from_array( array( 'now' => 1, 'cron' => array(), 'typo' => 1 ) );
	}
}
