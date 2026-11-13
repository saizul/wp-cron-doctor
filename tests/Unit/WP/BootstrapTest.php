<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\WP;

use Saizul\CronDoctor\Heartbeat\Heartbeat;
use Saizul\CronDoctor\Tests\Stubs\WpState;
use Saizul\CronDoctor\WP\Command;
use Saizul\CronDoctor\WP\Plugin;
use WP_CLI;

final class BootstrapTest extends WpTestCase {

	public function test_plugin_boot_registers_hooks_once(): void {
		Plugin::reset();
		Plugin::boot();
		Plugin::boot();

		$this->assertCount( 1, WpState::$actions['plugins_loaded'] );
		$this->assertCount( 1, WpState::$actions['admin_menu'] );
	}

	public function test_command_file_registers_command_and_hooks(): void {
		require dirname( __DIR__, 3 ) . '/command.php';

		$this->assertSame( Command::class, WP_CLI::$commands['cron-doctor'] );
		$this->assertCount( 1, WP_CLI::$hooks['before_wp_load'] );
		$this->assertCount( 1, WP_CLI::$hooks['after_invoke:cron event run'] );

		// The after_invoke hook records a heartbeat only for --due-now runs.
		$after = WP_CLI::$hooks['after_invoke:cron event run'][0];

		WP_CLI::$runner = (object) array( 'arguments' => array( 'cron', 'event', 'run', 'my_hook' ), 'assoc_args' => array() );
		$after();
		$this->assertArrayNotHasKey( Heartbeat::OPTION, WpState::$options );

		WP_CLI::$runner = (object) array( 'arguments' => array( 'cron', 'event', 'run' ), 'assoc_args' => array( 'due-now' => true ) );
		$after();
		$this->assertSame( 'wp-cli', WpState::$options[ Heartbeat::OPTION ]['source'] );

		// before_wp_load ignores other commands.
		WP_CLI::$runner = (object) array( 'arguments' => array( 'plugin', 'list' ), 'assoc_args' => array( 'cron-context' => true ) );
		$before = WP_CLI::$hooks['before_wp_load'][0];
		$before();
		$this->assertFalse( defined( 'DOING_CRON' ) );
	}
}
