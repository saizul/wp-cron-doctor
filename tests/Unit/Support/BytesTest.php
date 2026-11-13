<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Support;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Support\Bytes;

final class BytesTest extends TestCase {

	/**
	 * @dataProvider sizes
	 */
	public function test_parses_sizes( $input, int $expected ): void {
		$this->assertSame( $expected, Bytes::parse( $input ) );
	}

	public function sizes(): array {
		return array(
			array( 512, 512 ),
			array( '512', 512 ),
			array( '512B', 512 ),
			array( '256K', 262144 ),
			array( '256kb', 262144 ),
			array( '256KiB', 262144 ),
			array( '1M', 1048576 ),
			array( '1.5MB', 1572864 ),
			array( '1G', 1073741824 ),
			array( ' 2 M ', 2097152 ),
		);
	}

	/**
	 * @dataProvider invalid
	 */
	public function test_rejects_invalid( $input ): void {
		$this->expectException( InvalidArgumentException::class );
		Bytes::parse( $input );
	}

	public function invalid(): array {
		return array( array( '' ), array( 'abc' ), array( '1T' ), array( -1 ), array( '1.M' ) );
	}

	public function test_human(): void {
		$this->assertSame( '0 B', Bytes::human( 0 ) );
		$this->assertSame( '1023 B', Bytes::human( 1023 ) );
		$this->assertSame( '1.0 KB', Bytes::human( 1024 ) );
		$this->assertSame( '256.0 KB', Bytes::human( 262144 ) );
		$this->assertSame( '1.5 MB', Bytes::human( 1572864 ) );
		$this->assertSame( '2.0 GB', Bytes::human( 2147483648 ) );
		$this->assertSame( '0 B', Bytes::human( -10 ) );
	}
}
