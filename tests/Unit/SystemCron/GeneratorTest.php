<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\SystemCron;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\CronDoctor\SystemCron\Generator;

final class GeneratorTest extends TestCase {

	private function generator( array $options = array() ): Generator {
		return new Generator(
			$options + array(
				'path' => '/var/www/example',
				'url'  => 'https://example.com/',
				'wp'   => '/usr/local/bin/wp',
			)
		);
	}

	public function test_defaults(): void {
		$options = $this->generator()->options();

		$this->assertSame( 'wp-cli', $options['method'] );
		$this->assertSame( 5, $options['interval'] );
		$this->assertSame( 'https://example.com', $options['url'] );
		$this->assertSame( 'www-data', $options['user'] );
		$this->assertSame( array( 'https://example.com' ), $options['urls'] );
	}

	public function test_wp_cli_command(): void {
		$this->assertSame(
			'/usr/local/bin/wp cron event run --due-now --path=/var/www/example --url=https://example.com --quiet',
			$this->generator()->command()
		);
	}

	public function test_wp_binary_with_interpreter_and_spaces(): void {
		$command = $this->generator(
			array(
				'wp'   => 'php /opt/wp cli/wp-cli.phar',
				'path' => '/srv/my site',
			)
		)->command();

		$this->assertStringStartsWith( "php /opt/wp cli/wp-cli.phar cron event run", str_replace( "'", '', $command ) );
		$this->assertStringContainsString( "--path='/srv/my site'", $command );
	}

	public function test_network_wp_cli_command(): void {
		$command = $this->generator( array( 'network' => true ) )->command();

		$this->assertSame(
			'/usr/local/bin/wp site list --field=url --path=/var/www/example --quiet | xargs -r -I {} /usr/local/bin/wp cron event run --due-now --path=/var/www/example --url={} --quiet',
			$command
		);
	}

	public function test_curl_and_wget_commands(): void {
		$this->assertSame(
			'curl -fsS --max-time 300 -o /dev/null https://example.com/wp-cron.php',
			$this->generator( array( 'method' => 'curl' ) )->command()
		);
		$this->assertSame(
			'wget -q -O /dev/null --timeout=60 https://example.com/wp-cron.php',
			$this->generator( array( 'method' => 'wget', 'timeout' => 60 ) )->command()
		);
	}

	public function test_curl_never_sends_doing_wp_cron(): void {
		// Without the argument wp-cron.php treats the request as an external trigger and takes the lock itself.
		$this->assertStringNotContainsString( 'doing_wp_cron', $this->generator( array( 'method' => 'curl' ) )->crontab() );
	}

	public function test_network_curl_requests_every_site(): void {
		$command = $this->generator(
			array(
				'method'  => 'curl',
				'network' => true,
				'urls'    => array( 'https://a.example.com/', 'https://example.com/b', 'https://a.example.com' ),
			)
		)->command();

		$this->assertSame(
			'curl -fsS --max-time 300 -o /dev/null https://a.example.com/wp-cron.php; curl -fsS --max-time 300 -o /dev/null https://example.com/b/wp-cron.php',
			$command
		);
	}

	/**
	 * @dataProvider intervals
	 */
	public function test_expressions( int $minutes, string $cron, string $calendar ): void {
		$generator = $this->generator( array( 'interval' => $minutes ) );

		$this->assertSame( $cron, $generator->cron_expression() );
		$this->assertSame( $calendar, $generator->calendar_expression() );
	}

	public function intervals(): array {
		return array(
			array( 1, '* * * * *', '*-*-* *:00/1:00' ),
			array( 5, '*/5 * * * *', '*-*-* *:00/5:00' ),
			array( 15, '*/15 * * * *', '*-*-* *:00/15:00' ),
			array( 60, '0 * * * *', '*-*-* *:00:00' ),
		);
	}

	public function test_crontab(): void {
		$crontab = $this->generator()->crontab();
		$lines   = explode( "\n", trim( $crontab ) );

		$this->assertCount( 3, $lines );
		$this->assertStringStartsWith( '# WP-Cron for https://example.com, every 5 minute(s). Install with: crontab -u www-data -e', $lines[0] );
		$this->assertSame(
			"*/5 * * * * flock -n /tmp/wp-cron-example-com.lock sh -c '/usr/local/bin/wp cron event run --due-now --path=/var/www/example --url=https://example.com --quiet' > /dev/null 2>&1",
			$lines[2]
		);
	}

	public function test_crontab_with_log_and_percent_escaping(): void {
		$line = explode( "\n", trim( $this->generator( array( 'log' => '/var/log/wp cron.log', 'url' => 'https://example.com/%7Euser' ) )->crontab() ) )[2];

		$this->assertStringEndsWith( ">> '/var/log/wp cron.log' 2>&1", $line );
		$this->assertStringContainsString( '\\%7Euser', $line );
	}

	public function test_systemd_units(): void {
		$generator = $this->generator( array( 'user' => 'deploy', 'interval' => 10 ) );
		$service   = $generator->systemd_service();
		$timer     = $generator->systemd_timer();

		$this->assertSame( 'wp-cron-example-com', $generator->unit_name() );
		$this->assertStringContainsString( "Type=oneshot\nUser=deploy\nWorkingDirectory=/var/www/example\n", $service );
		$this->assertStringContainsString( 'ExecStart=/usr/local/bin/wp cron event run --due-now', $service );
		$this->assertStringContainsString( 'TimeoutStartSec=1800', $service );
		$this->assertStringContainsString( 'OnCalendar=*-*-* *:00/10:00', $timer );
		$this->assertStringContainsString( 'Persistent=true', $timer );
		$this->assertStringContainsString( 'Unit=wp-cron-example-com.service', $timer );
		$this->assertStringContainsString( 'WantedBy=timers.target', $timer );
		$this->assertStringContainsString( 'systemctl enable --now wp-cron-example-com.timer', $generator->systemd_install() );
	}

	public function test_systemd_uses_a_shell_when_needed(): void {
		$relative = $this->generator( array( 'wp' => 'wp' ) )->systemd_service();
		$this->assertStringContainsString( "ExecStart=/bin/sh -c 'wp cron event run", $relative );

		$curl = $this->generator( array( 'method' => 'curl', 'url' => 'https://example.com/%41' ) )->systemd_service();
		$this->assertStringContainsString( "ExecStart=/bin/sh -c 'curl", $curl );
		$this->assertStringContainsString( '%%41', $curl );
		$this->assertStringNotContainsString( 'WorkingDirectory', $curl );
	}

	public function test_slug_includes_subdirectory(): void {
		$this->assertSame( 'example-com-shop-en', $this->generator( array( 'url' => 'https://Example.com/shop/en/' ) )->slug() );
	}

	public function test_generate_bundle(): void {
		$bundle = $this->generator()->generate();

		$this->assertSame( array( 'crontab', 'service', 'timer', 'install', 'wp_config', 'unit' ), array_keys( $bundle ) );
		$this->assertSame( "define( 'DISABLE_WP_CRON', true );", $bundle['wp_config'] );
	}

	/**
	 * @dataProvider invalid
	 */
	public function test_invalid_options( array $options, string $message ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );
		$this->generator( $options );
	}

	public function invalid(): array {
		return array(
			'method'        => array( array( 'method' => 'ftp' ), 'Unknown method' ),
			'interval 0'    => array( array( 'interval' => 0 ), 'between 1 and 60' ),
			'interval 61'   => array( array( 'interval' => '61' ), 'between 1 and 60' ),
			'interval text' => array( array( 'interval' => 'often' ), 'whole number' ),
			'url scheme'    => array( array( 'url' => 'ftp://example.com' ), 'Invalid site URL' ),
			'url empty'     => array( array( 'url' => '' ), 'Invalid site URL' ),
			'url spaces'    => array( array( 'url' => 'https://exa mple.com' ), 'Invalid site URL' ),
			'relative path' => array( array( 'path' => 'www/site' ), 'must be absolute' ),
			'user'          => array( array( 'user' => 'bad user;' ), 'Invalid system user' ),
			'network url'   => array( array( 'urls' => array( 'nope' ) ), 'Invalid site URL "nope"' ),
		);
	}

	public function test_relative_path_is_fine_for_http_methods(): void {
		$this->assertSame( '', ( new Generator( array( 'method' => 'curl', 'url' => 'https://example.com' ) ) )->options()['path'] );
	}
}
