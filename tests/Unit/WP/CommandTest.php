<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\WP;

use Saizul\CronDoctor\Cron\CronArray;
use Saizul\CronDoctor\Tests\Fixtures\CronBuilder;
use Saizul\CronDoctor\Tests\Stubs\FakeWpdb;
use Saizul\CronDoctor\Tests\Stubs\WpState;
use Saizul\CronDoctor\WP\ActionSchedulerSource;
use Saizul\CronDoctor\WP\Command;
use Saizul\CronDoctor\WP\SnapshotFactory;
use WP_CLI;
use WP_CLI_Stub_Exit;

final class CommandTest extends WpTestCase {

	private function command(): Command {
		return new Command(
			new SnapshotFactory(
				null,
				static function (): ActionSchedulerSource {
					return new ActionSchedulerSource( new FakeWpdb() );
				}
			)
		);
	}

	/**
	 * Runs a subcommand and returns its exit code (0 when it returned normally).
	 */
	private function invoke( string $method, array $assoc_args = array() ): int {
		try {
			$this->command()->{$method}( array(), $assoc_args );
		} catch ( WP_CLI_Stub_Exit $exit ) {
			return $exit->getCode();
		}
		return 0;
	}

	private function healthy_cron(): void {
		WpState::$options['cron'] = CronBuilder::create()->recurring( time() + 3600, 'wp_version_check', 'twicedaily', 43200 )->build();
		add_action( 'wp_version_check', 'wp_version_check' );
	}

	public function test_report_on_healthy_site_exits_zero(): void {
		$this->healthy_cron();

		$this->assertSame( 0, $this->invoke( 'report', array( 'skip-action-scheduler' => true ) ) );

		$table = WP_CLI::$formatted[0];
		$this->assertSame( 'table', $table['format'] );
		$this->assertSame( array( 'check', 'status', 'summary' ), $table['fields'] );
		$this->assertSame( 'runner', $table['items'][0]['check'] );
		$this->assertStringStartsWith( 'Overall: OK', WP_CLI::messages( 'log' )[0] );
	}

	public function test_report_exit_codes_and_fail_on(): void {
		WpState::$options['cron'] = CronBuilder::create()->single( time() - 86400, 'very_late' )->build();
		add_action( 'very_late', '__return_true' );

		$this->assertSame( 2, $this->invoke( 'report', array( 'skip-loopback' => true ) ) );
		$this->assertSame( 0, $this->invoke( 'report', array( 'skip-loopback' => true, 'fail-on' => 'never' ) ) );
	}

	public function test_report_json_includes_details(): void {
		WpState::$options['cron'] = CronBuilder::create()->single( time() - 1200, 'a' )->single( time() - 600, 'a' )->build();
		add_action( 'a', '__return_true' );

		$code = $this->invoke(
			'report',
			array(
				'format'        => 'json',
				'skip-loopback' => true,
				'problems'      => true,
			)
		);

		$this->assertSame( 1, $code );
		$output = WP_CLI::$formatted[0];
		$this->assertSame( 'json', $output['format'] );
		$this->assertContains( 'details', $output['fields'] );
		$checks = array_column( $output['items'], 'check' );
		$this->assertContains( 'overdue', $checks );
		$this->assertContains( 'duplicates', $checks );
		$this->assertNotContains( 'integrity', $checks, '--problems hides ok rows.' );
		$this->assertSame( array(), WP_CLI::messages( 'log' ), 'No extra text in JSON output.' );
	}

	public function test_report_single_check_items(): void {
		WpState::$options['cron'] = CronBuilder::create()->single( time() - 1200, 'orphaned_hook' )->build();

		$this->invoke(
			'report',
			array(
				'check'         => 'orphans',
				'format'        => 'csv',
				'fields'        => 'hook,events',
				'skip-loopback' => true,
			)
		);

		$output = WP_CLI::$formatted[0];
		$this->assertSame( 'csv', $output['format'] );
		$this->assertSame( array( 'hook', 'events' ), $output['fields'] );
		$this->assertSame( 'orphaned_hook', $output['items'][0]['hook'] );
	}

	public function test_report_single_check_without_items(): void {
		$this->healthy_cron();

		$this->invoke( 'report', array( 'check' => 'duplicates', 'skip-loopback' => true ) );

		$this->assertSame( array(), WP_CLI::$formatted );
		$this->assertSame( 'ok: No duplicate events.', WP_CLI::messages( 'log' )[0] );
	}

	public function test_report_nagios(): void {
		$this->healthy_cron();

		$this->assertSame( 0, $this->invoke( 'report', array( 'format' => 'nagios', 'skip-action-scheduler' => true ) ) );
		$this->assertStringStartsWith( 'CRON OK - all checks passed', WP_CLI::messages( 'line' )[0] );
	}

	/**
	 * @dataProvider invalid_report_options
	 */
	public function test_report_invalid_options_exit_unknown( array $options, string $message ): void {
		$this->assertSame( 3, $this->invoke( 'report', $options ) );
		$this->assertStringContainsString( $message, WP_CLI::messages( 'error' )[0] );
	}

	public function invalid_report_options(): array {
		return array(
			array( array( 'fail-on' => 'info' ), 'Invalid --fail-on' ),
			array( array( 'format' => 'xml' ), 'Invalid format' ),
			array( array( 'check' => 'nope' ), 'Unknown check "nope"' ),
			array( array( 'overdue-warning' => 'later' ), '--overdue-warning' ),
		);
	}

	public function test_dedupe_dry_run_changes_nothing(): void {
		$raw                      = CronBuilder::create()->single( 100, 'a' )->single( 200, 'a' )->single( 300, 'a' )->build();
		WpState::$options['cron'] = $raw;

		$this->assertSame( 0, $this->invoke( 'dedupe' ) );

		$this->assertSame( $raw, WpState::$options['cron'] );
		$this->assertSame( array( 'would remove', 'would remove' ), array_column( WP_CLI::$formatted[0]['items'], 'result' ) );
		$this->assertStringContainsString( 'Dry run: 2 duplicate event(s) would be removed', WP_CLI::messages( 'log' )[0] );
	}

	public function test_dedupe_with_yes_keeps_first_copy(): void {
		WpState::$options['cron'] = CronBuilder::create()
			->single( 100, 'a' )->single( 200, 'a' )
			->single( 100, 'b' )->single( 500, 'b' )
			->build();

		$this->assertSame( 0, $this->invoke( 'dedupe', array( 'yes' => true, 'hook' => 'a' ) ) );

		$cron = CronArray::from_option( WpState::$options['cron'] );
		$this->assertSame( array( 'a@100', 'b@100', 'b@500' ), array_map( static fn( $e ) => $e->hook() . '@' . $e->timestamp(), $cron->events() ) );
		$this->assertSame( array( 'Removed 1 duplicate event(s).' ), WP_CLI::messages( 'success' ) );
	}

	public function test_dedupe_hook_patterns_and_exclude(): void {
		WpState::$options['cron'] = CronBuilder::create()
			->single( 100, 'shop_sync' )->single( 200, 'shop_sync' )
			->single( 100, 'shop_keep' )->single( 300, 'shop_keep' )
			->single( 100, 'other' )->single( 400, 'other' )
			->build();

		$this->assertSame( 0, $this->invoke( 'dedupe', array( 'yes' => true, 'hook' => 'shop_*', 'exclude-hook' => 'shop_keep' ) ) );

		$cron = CronArray::from_option( WpState::$options['cron'] );
		$this->assertSame(
			array( 'other@100', 'shop_keep@100', 'shop_sync@100', 'shop_keep@300', 'other@400' ),
			array_map( static fn( $e ) => $e->hook() . '@' . $e->timestamp(), $cron->events() )
		);
		$this->assertSame( array( 'Removed 1 duplicate event(s).' ), WP_CLI::messages( 'success' ) );
	}

	public function test_dedupe_reports_failures(): void {
		WpState::$options['cron']   = CronBuilder::create()->single( 100, 'a' )->single( 200, 'a' )->build();
		WpState::$unschedule_result = false;

		$this->assertSame( 1, $this->invoke( 'dedupe', array( 'yes' => true ) ) );
		$this->assertStringContainsString( '1 failed', WP_CLI::messages( 'error' )[0] );
	}

	public function test_dedupe_nothing_to_do(): void {
		WpState::$options['cron'] = CronBuilder::create()->single( 100, 'a' )->build();

		$this->invoke( 'dedupe' );

		$this->assertSame( array( 'No duplicate events found.' ), WP_CLI::messages( 'success' ) );
	}

	public function test_clean_orphans(): void {
		WpState::$options['cron'] = CronBuilder::create()
			->single( 100, 'live' )
			->single( 100, 'gone_one' )
			->single( 200, 'gone_two', array( 5 ) )
			->build();
		add_action( 'live', '__return_true' );

		$this->assertSame( 0, $this->invoke( 'clean_orphans' ) );
		$this->assertSame( 3, CronArray::from_option( WpState::$options['cron'] )->count(), 'Dry run by default.' );

		$this->assertSame( 0, $this->invoke( 'clean_orphans', array( 'yes' => true, 'ignore-hook' => 'gone_two' ) ) );
		$this->assertSame( array( 'gone_two', 'live' ), CronArray::from_option( WpState::$options['cron'] )->hooks() );
		$this->assertSame( array(), WpState::$requests, 'No loopback request for clean-orphans.' );
	}

	public function test_run_overdue(): void {
		$now                      = time();
		WpState::$options['cron'] = CronBuilder::create()
			->single( $now - 900, 'first' )
			->single( $now - 600, 'second' )
			->single( $now - 300, 'third' )
			->single( $now + 900, 'future' )
			->build();

		$this->assertSame( 0, $this->invoke( 'run_overdue', array( 'limit' => '2' ) ) );

		$this->assertSame( array( array( 'first', array() ), array( 'second', array() ) ), WpState::$fired );
		$this->assertSame( array( 'ran', 'ran' ), array_column( WP_CLI::$formatted[0]['items'], 'result' ) );
		$this->assertSame( array( 'Ran 2 event(s).' ), WP_CLI::messages( 'success' ) );
		$this->assertSame( array( 'future', 'third' ), CronArray::from_option( WpState::$options['cron'] )->hooks() );
	}

	public function test_run_overdue_dry_run_and_filters(): void {
		$now                      = time();
		WpState::$options['cron'] = CronBuilder::create()
			->single( $now - 7200, 'wc_old' )
			->single( $now - 60, 'wc_new' )
			->single( $now - 7200, 'other' )
			->build();

		$this->invoke(
			'run_overdue',
			array(
				'dry-run' => true,
				'grace'   => '10m',
				'hook'    => 'wc_*',
				'limit'   => '0',
			)
		);

		$this->assertSame( array(), WpState::$fired );
		$this->assertSame( array( 'wc_old' ), array_column( WP_CLI::$formatted[0]['items'], 'hook' ) );
		$this->assertSame( 'would run', WP_CLI::$formatted[0]['items'][0]['result'] );
	}

	public function test_run_overdue_validation_and_empty(): void {
		$this->assertSame( 1, $this->invoke( 'run_overdue', array( 'limit' => '-1' ) ) );
		$this->assertSame( 1, $this->invoke( 'run_overdue', array( 'grace' => 'soon' ) ) );

		WP_CLI::reset();
		$this->assertSame( 0, $this->invoke( 'run_overdue' ) );
		$this->assertSame( array( 'No due events match.' ), WP_CLI::messages( 'success' ) );
	}

	public function test_run_overdue_failures_exit_non_zero(): void {
		WpState::$options['cron'] = CronBuilder::create()->single( time() - 60, 'a' )->build();
		WpState::$unschedule_result = false;

		$this->assertSame( 1, $this->invoke( 'run_overdue' ) );
		$this->assertSame( '1 of 1 event(s) failed.', WP_CLI::messages( 'error' )[0] );
	}

	public function test_system_cron_text(): void {
		$this->invoke( 'system_cron', array( 'wp-bin' => '/usr/local/bin/wp', 'user' => 'deploy' ) );

		$text = implode( "\n", WP_CLI::messages( 'line' ) );
		$this->assertStringContainsString( '# --- crontab ---', $text );
		$this->assertStringContainsString( '/usr/local/bin/wp cron event run --due-now --path=/var/www/example --url=https://example.com --quiet', $text );
		$this->assertStringContainsString( '/etc/systemd/system/wp-cron-example-com.timer', $text );
		$this->assertStringContainsString( 'User=deploy', $text );
		$this->assertStringContainsString( "define( 'DISABLE_WP_CRON', true );", $text );
	}

	public function test_system_cron_types_and_json(): void {
		$this->invoke( 'system_cron', array( 'type' => 'crontab', 'method' => 'curl', 'interval' => '1' ) );
		$text = implode( "\n", WP_CLI::messages( 'line' ) );
		$this->assertStringContainsString( '* * * * * flock', $text );
		$this->assertStringContainsString( 'curl -fsS', $text );
		$this->assertStringNotContainsString( 'systemd', $text );

		WP_CLI::reset();
		$this->invoke( 'system_cron', array( 'format' => 'json', 'wp-bin' => 'wp' ) );
		$json = json_decode( WP_CLI::messages( 'line' )[0], true );
		$this->assertSame( 'wp-cron-example-com', $json['unit'] );
	}

	public function test_system_cron_network(): void {
		$this->assertSame( 1, $this->invoke( 'system_cron', array( 'network' => true ) ) );
		$this->assertStringContainsString( 'multisite', WP_CLI::messages( 'error' )[0] );

		WP_CLI::reset();
		WpState::$multisite = true;
		WpState::$sites     = array(
			1 => 'https://example.com',
			2 => 'https://shop.example.com',
		);
		$this->invoke( 'system_cron', array( 'network' => true, 'method' => 'wget', 'type' => 'crontab' ) );
		$text = implode( "\n", WP_CLI::messages( 'line' ) );
		$this->assertStringContainsString( 'https://shop.example.com/wp-cron.php', $text );
	}

	public function test_system_cron_invalid_interval(): void {
		$this->assertSame( 1, $this->invoke( 'system_cron', array( 'interval' => '90' ) ) );
		$this->assertStringContainsString( 'between 1 and 60', WP_CLI::messages( 'error' )[0] );
	}
}
