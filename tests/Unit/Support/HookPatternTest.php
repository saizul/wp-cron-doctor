<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\Support\HookPattern;

final class HookPatternTest extends TestCase {

	public function test_parse_list(): void {
		$this->assertSame( array(), HookPattern::parse_list( null ) );
		$this->assertSame( array(), HookPattern::parse_list( true ) );
		$this->assertSame( array(), HookPattern::parse_list( '' ) );
		$this->assertSame( array( 'a', 'b_*' ), HookPattern::parse_list( ' a, b_* ,,a ' ) );
		$this->assertSame( array( 'x', 'y' ), HookPattern::parse_list( array( 'x', ' y ', '' ) ) );
	}

	public function test_exact_match(): void {
		$this->assertTrue( HookPattern::matches( 'wp_version_check', 'wp_version_check' ) );
		$this->assertFalse( HookPattern::matches( 'wp_version_check', 'wp_version' ) );
		$this->assertFalse( HookPattern::matches( 'WP_VERSION_CHECK', 'wp_version_check' ) );
	}

	public function test_wildcards(): void {
		$this->assertTrue( HookPattern::matches( 'woocommerce_cleanup_sessions', 'woocommerce_*' ) );
		$this->assertTrue( HookPattern::matches( 'my.plugin.hook', '*.plugin.*' ) );
		$this->assertTrue( HookPattern::matches( 'anything', '*' ) );
		$this->assertFalse( HookPattern::matches( 'xwoocommerce_a', 'woocommerce_*' ) );
		// Regex characters in the pattern are literal.
		$this->assertFalse( HookPattern::matches( 'abc', 'a.c' ) );
		$this->assertTrue( HookPattern::matches( 'a.c', 'a.c' ) );
	}

	public function test_matches_any(): void {
		$this->assertTrue( HookPattern::matches_any( 'foo_bar', array( 'x', 'foo_*' ) ) );
		$this->assertFalse( HookPattern::matches_any( 'foo_bar', array() ) );
	}
}
