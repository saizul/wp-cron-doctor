<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\WP;

use Saizul\CronDoctor\Report\Baseline;
use Saizul\CronDoctor\Tests\Fixtures\CronBuilder;
use Saizul\CronDoctor\Tests\Stubs\FakeWpdb;
use Saizul\CronDoctor\Tests\Stubs\WpState;
use Saizul\CronDoctor\WP\ActionSchedulerSource;
use Saizul\CronDoctor\WP\Command;
use Saizul\CronDoctor\WP\SnapshotFactory;
use WP_CLI;
use WP_CLI_Stub_Exit;

final class BaselineCommandTest extends WpTestCase {

	/** @var string */
	private string $file = '';

	protected function setUp(): void {
		parent::setUp();
		$this->file = (string) tempnam( sys_get_temp_dir(), 'cron-baseline' );
		unlink( $this->file );
	}

	protected function tearDown(): void {
		if ( is_file( $this->file ) ) {
			unlink( $this->file );
		}
		parent::tearDown();
	}

	/**
	 * Runs a subcommand and returns its exit code (0 when it returned normally).
	 *
	 * @param string[] $args
	 */
	private function invoke( string $method, array $args = array(), array $assoc_args = array() ): int {
		$command = new Command(
			new SnapshotFactory(
				null,
				static function (): ActionSchedulerSource {
					return new ActionSchedulerSource( new FakeWpdb() );
				}
			)
		);
		try {
			$command->{$method}( $args, $assoc_args );
		} catch ( WP_CLI_Stub_Exit $exit ) {
			return $exit->getCode();
		}
		return 0;
	}

	/**
	 * @param string[] $orphans
	 */
	private function site( array $orphans ): void {
		$builder = CronBuilder::create()->recurring( time() + 3600, 'wp_version_check', 'twicedaily', 43200 );
		foreach ( $orphans as $hook ) {
			$builder->single( time() + 7200, $hook );
		}
		WpState::$options['cron'] = $builder->build();
		add_action( 'wp_version_check', 'wp_version_check' );
	}

	private const QUIET = array(
		'skip-loopback'         => true,
		'skip-action-scheduler' => true,
	);

	public function test_save_then_report_accepts_known_problems(): void {
		$this->site( array( 'old_plugin_hook' ) );
		$this->assertSame( 1, $this->invoke( 'report', array(), self::QUIET ) );

		$this->assertSame( 0, $this->invoke( 'baseline', array( 'save', $this->file ), self::QUIET ) );
		$this->assertStringContainsString( 'Accepted 1 check(s)', WP_CLI::messages( 'success' )[0] );
		$saved = Baseline::from_json( (string) file_get_contents( $this->file ) );
		$this->assertSame( array( 'orphans' ), $saved->checks() );

		WP_CLI::reset();
		$this->assertSame( 0, $this->invoke( 'report', array(), self::QUIET + array( 'baseline' => $this->file ) ) );
		$rows = WP_CLI::$formatted[0]['items'];
		$row  = array_values( array_filter( $rows, static function ( array $r ): bool { return 'orphans' === $r['check']; } ) )[0];
		$this->assertSame( 'info', $row['status'] );
		$this->assertStringStartsWith( Baseline::ACCEPTED_PREFIX, $row['summary'] );

		WP_CLI::reset();
		$this->assertSame( 0, $this->invoke( 'report', array(), self::QUIET + array( 'baseline' => $this->file, 'format' => 'nagios' ) ) );
		$this->assertStringStartsWith( 'CRON OK', WP_CLI::messages( 'line' )[0] );
	}

	public function test_new_orphan_still_fails(): void {
		$this->site( array( 'old_plugin_hook' ) );
		$this->invoke( 'baseline', array( 'save', $this->file ), self::QUIET );

		$this->site( array( 'old_plugin_hook', 'new_broken_hook' ) );
		WP_CLI::reset();
		$this->assertSame( 1, $this->invoke( 'report', array(), self::QUIET + array( 'baseline' => $this->file, 'problems' => true ) ) );
		$this->assertStringContainsString( 'new since baseline: new_broken_hook', WP_CLI::$formatted[0]['items'][0]['summary'] );
	}

	public function test_resolved_and_expired_notes(): void {
		$this->site( array( 'old_plugin_hook' ) );
		$this->invoke( 'baseline', array( 'save', $this->file ), self::QUIET );

		$this->site( array() );
		WP_CLI::reset();
		$this->assertSame( 0, $this->invoke( 'report', array(), self::QUIET + array( 'baseline' => $this->file ) ) );
		$this->assertStringContainsString( 'Fixed since the baseline: orphans', WP_CLI::messages( 'log' )[1] );

		$data               = json_decode( (string) file_get_contents( $this->file ), true );
		$data['created_at'] = time() - 7200;
		$data['expires_at'] = time() - 60;
		file_put_contents( $this->file, json_encode( $data ) );

		$this->site( array( 'old_plugin_hook' ) );
		WP_CLI::reset();
		$this->assertSame( 1, $this->invoke( 'report', array(), self::QUIET + array( 'baseline' => $this->file ) ) );
		$this->assertStringContainsString( 'expired', WP_CLI::messages( 'log' )[1] );
	}

	public function test_save_with_check_filter_and_expiry(): void {
		$this->site( array( 'old_plugin_hook' ) );

		$this->assertSame( 0, $this->invoke( 'baseline', array( 'save', $this->file ), self::QUIET + array( 'check' => 'duplicates', 'expires' => '7d' ) ) );
		$saved = Baseline::from_json( (string) file_get_contents( $this->file ) );
		$this->assertSame( array(), $saved->checks() );
		$this->assertEqualsWithDelta( time() + 7 * 86400, $saved->expires_at(), 5 );
		$this->assertStringContainsString( 'empty baseline', WP_CLI::messages( 'success' )[0] );
	}

	public function test_show_lists_entries(): void {
		$this->site( array( 'hook_b', 'hook_a' ) );
		$this->invoke( 'baseline', array( 'save', $this->file ), self::QUIET );

		WP_CLI::reset();
		$this->assertSame( 0, $this->invoke( 'baseline', array( 'show', $this->file ) ) );
		$output = WP_CLI::$formatted[0];
		$this->assertSame( array( 'check', 'severity', 'value', 'items' ), $output['fields'] );
		$this->assertSame( 'orphans', $output['items'][0]['check'] );
		$this->assertSame( 'hook_a, hook_b', $output['items'][0]['items'] );
		$this->assertStringContainsString( 'never expires', WP_CLI::messages( 'log' )[0] );
	}

	/**
	 * @dataProvider invalid_calls
	 */
	public function test_invalid_input_exits_unknown( string $method, array $args, array $assoc, string $message ): void {
		if ( 'garbage' === ( $assoc['write'] ?? '' ) ) {
			file_put_contents( $this->file, '{"format":"something-else"}' );
			unset( $assoc['write'] );
		}
		$args  = array_map(
			function ( string $arg ): string {
				return '@file' === $arg ? $this->file : $arg;
			},
			$args
		);
		$assoc = array_map(
			function ( $value ) {
				return '@file' === $value ? $this->file : $value;
			},
			$assoc
		);

		$this->assertSame( 3, $this->invoke( $method, $args, $assoc ) );
		$this->assertStringContainsString( $message, WP_CLI::messages( 'error' )[0] );
	}

	public function invalid_calls(): array {
		return array(
			'missing file'    => array( 'report', array(), array( 'baseline' => '@file' ), 'Cannot read the baseline file' ),
			'invalid file'    => array( 'report', array(), array( 'baseline' => '@file', 'write' => 'garbage' ), 'Not a baseline file' ),
			'no path'         => array( 'baseline', array( 'save' ), array(), 'Missing the baseline file path' ),
			'unknown action'  => array( 'baseline', array( 'drop', '@file' ), array(), 'Unknown action "drop"' ),
			'unknown check'   => array( 'baseline', array( 'save', '@file' ), array( 'check' => 'nope' ), 'Unknown check "nope"' ),
			'zero expiry'     => array( 'baseline', array( 'save', '@file' ), array( 'expires' => '0' ), 'greater than zero' ),
			'bad threshold'   => array( 'baseline', array( 'save', '@file' ), array( 'overdue-warning' => 'later' ), '--overdue-warning' ),
			'show bad format' => array( 'baseline', array( 'show', '@file' ), array( 'write' => 'garbage' ), 'Not a baseline file' ),
		);
	}

	public function test_unwritable_path_exits_unknown(): void {
		$this->site( array() );

		$this->assertSame( 3, $this->invoke( 'baseline', array( 'save', sys_get_temp_dir() . '/missing-dir-' . uniqid() . '/b.json' ), self::QUIET ) );
		$this->assertStringContainsString( 'Could not write', WP_CLI::messages( 'error' )[0] );
	}
}
