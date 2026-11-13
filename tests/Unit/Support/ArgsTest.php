<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Support\Args;

final class ArgsTest extends TestCase {

	public function test_key_matches_wordpress_core(): void {
		// wp_schedule_event() uses md5( serialize( $args ) ); for no arguments that is a well-known constant.
		$this->assertSame( '40cd750bba9870f18aada2478b24840a', Args::key( array() ) );
		$this->assertSame( md5( serialize( array( 42, 'x' ) ) ), Args::key( array( 42, 'x' ) ) );
	}

	public function test_key_is_type_sensitive(): void {
		// WordPress treats 1 and "1" as different arguments, so duplicates must too.
		$this->assertNotSame( Args::key( array( 1 ) ), Args::key( array( '1' ) ) );
	}

	public function test_describe(): void {
		$this->assertSame( '[]', Args::describe( array() ) );
		$this->assertSame( '[42,"a/b"]', Args::describe( array( 42, 'a/b' ) ) );
		$this->assertSame( '{"id":5}', Args::describe( array( 'id' => 5 ) ) );
		$this->assertSame( '["ঢাকা"]', Args::describe( array( 'ঢাকা' ) ) );
	}

	public function test_describe_truncates(): void {
		$long = Args::describe( array( str_repeat( 'a', 200 ) ), 20 );
		$this->assertSame( 20, strlen( $long ) );
		$this->assertStringEndsWith( '...', $long );
		$this->assertSame( 204, strlen( Args::describe( array( str_repeat( 'a', 200 ) ), 0 ) ) );
	}

	public function test_describe_survives_invalid_utf8(): void {
		$this->assertIsString( Args::describe( array( "\xB1\x31" ) ) );
	}
}
