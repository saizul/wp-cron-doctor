<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Support;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Support\Duration;

final class DurationTest extends TestCase {

	/**
	 * @dataProvider valid_durations
	 */
	public function test_parses_valid_durations( $input, int $expected ): void {
		$this->assertSame( $expected, Duration::parse( $input ) );
	}

	public function valid_durations(): array {
		return array(
			'int'            => array( 90, 90 ),
			'zero'           => array( '0', 0 ),
			'bare seconds'   => array( '90', 90 ),
			'seconds'        => array( '45s', 45 ),
			'minutes'        => array( '15m', 900 ),
			'hours'          => array( '2h', 7200 ),
			'days'           => array( '1d', 86400 ),
			'weeks'          => array( '1w', 604800 ),
			'combined'       => array( '1h30m', 5400 ),
			'spaces + upper' => array( ' 1H 30M 5S ', 5405 ),
		);
	}

	/**
	 * @dataProvider invalid_durations
	 */
	public function test_rejects_invalid_durations( $input ): void {
		$this->expectException( InvalidArgumentException::class );
		Duration::parse( $input );
	}

	public function invalid_durations(): array {
		return array(
			'empty'     => array( '' ),
			'negative'  => array( -5 ),
			'neg str'   => array( '-5m' ),
			'unit only' => array( 'm' ),
			'unknown'   => array( '5y' ),
			'decimal'   => array( '1.5h' ),
			'words'     => array( 'ten minutes' ),
		);
	}

	public function test_human_formats_with_two_units_by_default(): void {
		$this->assertSame( '0s', Duration::human( 0 ) );
		$this->assertSame( '45s', Duration::human( 45 ) );
		$this->assertSame( '1m 30s', Duration::human( 90 ) );
		$this->assertSame( '2h 5m', Duration::human( 7500 ) );
		$this->assertSame( '1d 1h', Duration::human( 90061 ) );
		$this->assertSame( '1w 3d', Duration::human( 864000 ) );
	}

	public function test_human_respects_parts_and_sign_and_rounding(): void {
		$this->assertSame( '1h', Duration::human( 3725, 1 ) );
		$this->assertSame( '1h 2m 5s', Duration::human( 3725, 3 ) );
		$this->assertSame( '-1m', Duration::human( -60 ) );
		$this->assertSame( '2s', Duration::human( 1.6 ) );
	}

	public function test_human_skips_empty_units(): void {
		$this->assertSame( '1h 5s', Duration::human( 3605 ) );
	}
}
