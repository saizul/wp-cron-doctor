<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\SystemCron;

use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\SystemCron\Shell;

final class ShellTest extends TestCase {

	public function test_quote(): void {
		$this->assertSame( "''", Shell::quote( '' ) );
		$this->assertSame( '/var/www/html', Shell::quote( '/var/www/html' ) );
		$this->assertSame( 'https://example.com/shop', Shell::quote( 'https://example.com/shop' ) );
		$this->assertSame( "'/var/www/my site'", Shell::quote( '/var/www/my site' ) );
		$this->assertSame( "'it'\\''s'", Shell::quote( "it's" ) );
		$this->assertSame( "'\$(rm -rf /)'", Shell::quote( '$(rm -rf /)' ) );
		$this->assertSame( "'a;b'", Shell::quote( 'a;b' ) );
	}

	public function test_quoted_words_survive_the_shell(): void {
		if ( ! is_executable( '/bin/sh' ) ) {
			$this->markTestSkipped( 'No /bin/sh.' );
		}
		foreach ( array( "it's", 'a b', '$HOME', '`id`', "x\"y", 'semi;colon' ) as $word ) {
			$output = shell_exec( '/bin/sh -c ' . escapeshellarg( 'printf %s ' . Shell::quote( $word ) ) );
			$this->assertSame( $word, $output );
		}
	}

	public function test_option(): void {
		$this->assertSame( '--path=/srv/wp', Shell::option( 'path', '/srv/wp' ) );
		$this->assertSame( "--path='/srv/my wp'", Shell::option( 'path', '/srv/my wp' ) );
	}

	public function test_escapes(): void {
		$this->assertSame( 'date +\\%s', Shell::escape_crontab( 'date +%s' ) );
		$this->assertSame( 'a%%b', Shell::escape_systemd( 'a%b' ) );
	}
}
