<?php
/**
 * The `wp cron-doctor` command.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\WP;

use InvalidArgumentException;
use Saizul\CronDoctor\Cron\CronArray;
use Saizul\CronDoctor\Cron\Event;
use Saizul\CronDoctor\Doctor;
use Saizul\CronDoctor\Fixer\DuplicatePlanner;
use Saizul\CronDoctor\Fixer\OrphanPlanner;
use Saizul\CronDoctor\Fixer\OverdueSelector;
use Saizul\CronDoctor\Report\Baseline;
use Saizul\CronDoctor\Report\Report;
use Saizul\CronDoctor\Report\Severity;
use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\Support\Duration;
use Saizul\CronDoctor\Support\HookPattern;
use Saizul\CronDoctor\SystemCron\Generator;
use WP_CLI;

/**
 * Diagnoses and repairs WP-Cron and Action Scheduler problems.
 *
 * ## EXAMPLES
 *
 *     # Full health report.
 *     $ wp cron-doctor report
 *
 *     # Only problems, as JSON, for a monitoring system.
 *     $ wp cron-doctor report --problems --format=json
 *
 *     # Nagios/Icinga compatible status line and exit code.
 *     $ wp cron-doctor report --format=nagios
 *
 *     # Remove duplicate events (preview first).
 *     $ wp cron-doctor dedupe
 *     $ wp cron-doctor dedupe --yes
 *
 *     # Accept today's known problems, then alert only on new ones.
 *     $ wp cron-doctor baseline save cron-baseline.json
 *     $ wp cron-doctor report --format=nagios --baseline=cron-baseline.json
 *
 *     # Print a crontab line and systemd timer for this site.
 *     $ wp cron-doctor system-cron
 */
class Command {

	/**
	 * Output formats handled by WP-CLI's formatter.
	 */
	private const FORMATS = array( 'table', 'json', 'csv', 'yaml', 'count' );

	/**
	 * Snapshot factory.
	 *
	 * @var SnapshotFactory
	 */
	private SnapshotFactory $factory;

	/**
	 * Event runner.
	 *
	 * @var EventRunner
	 */
	private EventRunner $runner;

	/**
	 * Constructor.
	 *
	 * @param SnapshotFactory|null $factory Snapshot factory.
	 * @param EventRunner|null     $runner  Event runner.
	 */
	public function __construct( ?SnapshotFactory $factory = null, ?EventRunner $runner = null ) {
		$this->factory = $factory ?? new SnapshotFactory();
		$this->runner  = $runner ?? new EventRunner();
	}

	/**
	 * Reports WP-Cron and Action Scheduler health.
	 *
	 * Checks overdue, duplicate and orphaned events, very frequent and unregistered
	 * recurrences, the cron option size and structure, DISABLE_WP_CRON without a working
	 * system cron, ALTERNATE_WP_CRON, a stale doing_cron lock, the loopback request to
	 * wp-cron.php and the Action Scheduler queue.
	 *
	 * Exit codes: 0 ok, 1 warning, 2 critical, 3 the report could not run.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format: table, json, csv, yaml, count or nagios (one status line).
	 * ---
	 * default: table
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma separated fields. Summary fields: check, status, value, summary, advice, items, details.
	 *
	 * [--check=<check>]
	 * : Show the detail rows of one check, e.g. overdue, duplicates, orphans, frequent, unknown-schedules, option-size, integrity, as-failed.
	 *
	 * [--problems]
	 * : Only show warnings and critical findings.
	 *
	 * [--fail-on=<level>]
	 * : Lowest level that produces a non-zero exit code.
	 * ---
	 * default: warning
	 * options:
	 *   - warning
	 *   - critical
	 *   - never
	 * ---
	 *
	 * [--baseline=<file>]
	 * : Baseline file written by `wp cron-doctor baseline save`. Problems recorded there are shown as info and no longer change the exit code, unless they got worse or list new hooks.
	 *
	 * [--skip-loopback]
	 * : Do not send the loopback request.
	 *
	 * [--loopback-timeout=<seconds>]
	 * : Timeout of the loopback request.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--skip-action-scheduler]
	 * : Do not inspect Action Scheduler.
	 *
	 * [--ignore-hook=<hooks>]
	 * : Comma separated hooks (or patterns with *) never reported as orphans.
	 *
	 * [--cron-context]
	 * : Load WordPress as a cron request (defines DOING_CRON) so plugins that attach cron callbacks only during cron runs are seen.
	 *
	 * [--overdue-warning=<duration>]
	 * : Lateness that makes an event overdue. Default 10m.
	 *
	 * [--overdue-critical=<duration>]
	 * : Lateness that is critical. Default 1h.
	 *
	 * [--frequent=<duration>]
	 * : Recurrences shorter than this are reported. Default 5m.
	 *
	 * [--stale-after=<duration>]
	 * : With DISABLE_WP_CRON, a last run older than this is critical. Default 1h.
	 *
	 * [--lock-stale=<duration>]
	 * : A doing_cron lock older than this is reported. Default 10m.
	 *
	 * [--option-size-warning=<size>]
	 * : Cron option size that gives a warning. Default 256K.
	 *
	 * [--option-size-critical=<size>]
	 * : Cron option size that is critical. Default 1M.
	 *
	 * [--events-warning=<count>]
	 * : Number of events that gives a warning. Default 1000.
	 *
	 * [--duplicates-critical=<count>]
	 * : Surplus duplicate events that are critical. Default 50.
	 *
	 * [--loopback-slow=<duration>]
	 * : Loopback requests slower than this give a warning. Default 5s.
	 *
	 * [--as-past-due-warning=<duration>]
	 * : Age of the oldest past-due action that gives a warning. Default 10m.
	 *
	 * [--as-past-due-critical=<duration>]
	 * : Age of the oldest past-due action that is critical. Default 1h.
	 *
	 * [--as-failed-window=<duration>]
	 * : Count failures within this window. Default 7d.
	 *
	 * [--as-failed-warning=<count>]
	 * : Failures in the window that give a warning. Default 1.
	 *
	 * [--as-failed-critical=<count>]
	 * : Failures in the window that are critical. Default 100.
	 *
	 * [--as-stuck-after=<duration>]
	 * : In-progress actions older than this are stuck. Default 1h.
	 *
	 * [--as-pending-warning=<count>]
	 * : Pending actions that give a warning. Default 10000.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp cron-doctor report
	 *     $ wp cron-doctor report --check=overdue
	 *     $ wp cron-doctor report --format=json --fail-on=critical
	 *     $ wp cron-doctor report --overdue-warning=30m --skip-loopback
	 *     $ wp cron-doctor report --baseline=cron-baseline.json
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 * @return void
	 */
	public function report( array $args, array $assoc_args ): void {
		$format  = (string) ( $assoc_args['format'] ?? 'table' );
		$fail_on = (string) ( $assoc_args['fail-on'] ?? Severity::WARNING );

		$check = isset( $assoc_args['check'] ) ? (string) $assoc_args['check'] : '';

		try {
			$thresholds = $this->validate_report_options( $assoc_args, $format, $fail_on, $check );
			$baseline   = isset( $assoc_args['baseline'] ) ? $this->load_baseline( (string) $assoc_args['baseline'] ) : null;
		} catch ( InvalidArgumentException $e ) {
			$this->unknown( $e->getMessage() );
			return;
		}

		$report = $this->diagnose( $thresholds, $assoc_args );
		$notes  = array();
		if ( null !== $baseline ) {
			if ( $baseline->is_expired( $report->generated_at() ) ) {
				$notes[] = sprintf( 'The baseline expired on %s and was not applied.', gmdate( 'Y-m-d H:i:s', (int) $baseline->expires_at() ) );
			} else {
				$resolved = $baseline->resolved( $report );
				if ( array() !== $resolved ) {
					$notes[] = sprintf( 'Fixed since the baseline: %s. Run `wp cron-doctor baseline save` again to drop them.', implode( ', ', $resolved ) );
				}
			}
			$report = $baseline->apply( $report );
		}
		$code = $report->exit_code( $fail_on );

		if ( 'nagios' === $format ) {
			WP_CLI::line( $report->status_line() );
			$this->halt( $code );
			return;
		}

		if ( '' !== $check ) {
			$finding = $report->get( $check );
			$items   = null === $finding ? array() : $finding->items();
			if ( array() === $items ) {
				if ( 'table' === $format ) {
					WP_CLI::log( null === $finding ? 'Check not run.' : $finding->severity() . ': ' . $finding->summary() );
				} else {
					$this->display( $format, array(), $assoc_args['fields'] ?? array( 'check' ) );
				}
			} else {
				$this->display( $format, $items, $assoc_args['fields'] ?? array_keys( $items[0] ) );
			}
			$this->halt( $code );
			return;
		}

		$minimum = empty( $assoc_args['problems'] ) ? Severity::OK : Severity::WARNING;
		$rows    = $report->rows( $minimum );
		$default = in_array( $format, array( 'table', 'csv' ), true )
			? array( 'check', 'status', 'summary' )
			: array( 'check', 'status', 'value', 'summary', 'advice', 'details' );

		$this->display( $format, $rows, $assoc_args['fields'] ?? $default );

		if ( 'table' === $format ) {
			$totals = $report->totals();
			WP_CLI::log(
				sprintf(
					'Overall: %s (%d critical, %d warning, %d info, %d ok). Details: wp cron-doctor report --check=<check>',
					strtoupper( $report->status() ),
					$totals[ Severity::CRITICAL ],
					$totals[ Severity::WARNING ],
					$totals[ Severity::INFO ],
					$totals[ Severity::OK ]
				)
			);
			foreach ( $notes as $note ) {
				WP_CLI::log( $note );
			}
		}

		$this->halt( $code );
	}

	/**
	 * Saves or shows a baseline of accepted problems.
	 *
	 * `save` runs the report and writes its current warnings and critical findings to a
	 * JSON file. `wp cron-doctor report --baseline=<file>` then shows those findings as
	 * info, so a monitoring check only alerts when something new breaks: a check that
	 * was fine fails, a warning becomes critical, or new hooks show up as orphans,
	 * duplicates or failures. `show` lists what a baseline file accepts.
	 *
	 * The file stores hook names and arguments only, no option values.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : What to do.
	 * ---
	 * options:
	 *   - save
	 *   - show
	 * ---
	 *
	 * <file>
	 * : Path of the baseline file.
	 *
	 * [--check=<checks>]
	 * : With save, only accept these comma separated checks.
	 *
	 * [--expires=<duration>]
	 * : With save, stop applying the baseline after this long, e.g. 7d or 12h.
	 *
	 * [--skip-loopback]
	 * : With save, do not send the loopback request.
	 *
	 * [--skip-action-scheduler]
	 * : With save, do not inspect Action Scheduler.
	 *
	 * [--ignore-hook=<hooks>]
	 * : With save, comma separated hooks (or patterns with *) never reported as orphans.
	 *
	 * [--format=<format>]
	 * : Output format of show.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * [--<threshold>=<value>]
	 * : With save, any threshold option of `wp cron-doctor report`, e.g. --overdue-warning=30m. Use the same thresholds as the report that reads the baseline.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp cron-doctor baseline save cron-baseline.json
	 *     $ wp cron-doctor baseline save cron-baseline.json --check=orphans,frequent --expires=30d
	 *     $ wp cron-doctor baseline show cron-baseline.json
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 * @return void
	 * @throws InvalidArgumentException Never; invalid input is reported with exit code 3.
	 */
	public function baseline( array $args, array $assoc_args ): void {
		$action = (string) ( $args[0] ?? '' );
		$file   = (string) ( $args[1] ?? '' );

		try {
			if ( '' === $file ) {
				throw new InvalidArgumentException( 'Missing the baseline file path.' );
			}
			if ( 'show' === $action ) {
				$this->show_baseline( $this->load_baseline( $file ), (string) ( $assoc_args['format'] ?? 'table' ) );
				return;
			}
			if ( 'save' !== $action ) {
				throw new InvalidArgumentException( sprintf( 'Unknown action "%s". Use save or show.', $action ) );
			}

			$only = HookPattern::parse_list( $assoc_args['check'] ?? null );
			foreach ( $only as $check ) {
				if ( ! in_array( $check, Doctor::check_ids(), true ) ) {
					throw new InvalidArgumentException( sprintf( 'Unknown check "%s". Known checks: %s.', $check, implode( ', ', Doctor::check_ids() ) ) );
				}
			}
			$thresholds = Thresholds::from_options( $assoc_args );
			$expires    = null;
			if ( isset( $assoc_args['expires'] ) ) {
				$ttl = Duration::parse( $assoc_args['expires'] );
				if ( $ttl <= 0 ) {
					throw new InvalidArgumentException( 'The --expires duration must be greater than zero.' );
				}
				$expires = time() + $ttl;
			}
		} catch ( InvalidArgumentException $e ) {
			$this->unknown( $e->getMessage() );
			return;
		}

		$report   = $this->diagnose( $thresholds, $assoc_args );
		$baseline = Baseline::from_report( $report, $expires, $only );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- WP-CLI writes a local file chosen by the user; failures are reported below.
		if ( false === @file_put_contents( $file, $baseline->to_json() ) ) {
			$this->unknown( sprintf( 'Could not write the baseline file "%s".', $file ) );
			return;
		}

		$accepted = $baseline->checks();
		if ( array() === $accepted ) {
			WP_CLI::success( sprintf( 'No problems to accept; wrote an empty baseline to %s.', $file ) );
			return;
		}
		WP_CLI::success( sprintf( 'Accepted %d check(s) in %s: %s.', count( $accepted ), $file, implode( ', ', $accepted ) ) );
	}

	/**
	 * Lists the entries of a baseline.
	 *
	 * @param Baseline $baseline Baseline.
	 * @param string   $format   Output format.
	 * @return void
	 * @throws InvalidArgumentException For an invalid format.
	 */
	private function show_baseline( Baseline $baseline, string $format ): void {
		if ( ! in_array( $format, self::FORMATS, true ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid format "%s". Use table, json, csv, yaml or count.', $format ) );
		}

		$data = $baseline->to_array();
		$rows = array();
		foreach ( (array) $data['checks'] as $check => $entry ) {
			$rows[] = array(
				'check'    => (string) $check,
				'severity' => $entry['severity'],
				'value'    => null === $entry['value'] ? '' : $entry['value'],
				'items'    => implode( ', ', $entry['items'] ),
			);
		}

		$this->display( $format, $rows, array( 'check', 'severity', 'value', 'items' ) );

		if ( 'table' === $format ) {
			$expires = $baseline->expires_at();
			WP_CLI::log(
				sprintf(
					'Created %s, %s.',
					gmdate( 'Y-m-d H:i:s', $baseline->created_at() ),
					null === $expires
						? 'never expires'
						: ( $baseline->is_expired( time() ) ? 'expired ' : 'expires ' ) . gmdate( 'Y-m-d H:i:s', $expires )
				)
			);
		}
	}

	/**
	 * Reads a baseline file.
	 *
	 * @param string $file Path.
	 * @return Baseline
	 * @throws InvalidArgumentException When the file is missing or invalid.
	 */
	private function load_baseline( string $file ): Baseline {
		if ( '' === $file || ! is_file( $file ) || ! is_readable( $file ) ) {
			throw new InvalidArgumentException( sprintf( 'Cannot read the baseline file "%s".', $file ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file chosen by the user.
		$json = file_get_contents( $file );
		try {
			return Baseline::from_json( false === $json ? '' : $json );
		} catch ( InvalidArgumentException $e ) {
			throw new InvalidArgumentException( sprintf( 'Invalid baseline file "%s": %s', $file, $e->getMessage() ) );
		}
	}

	/**
	 * Collects a snapshot and runs every check.
	 *
	 * @param Thresholds           $thresholds Limits.
	 * @param array<string, mixed> $assoc_args Options.
	 * @return Report
	 */
	private function diagnose( Thresholds $thresholds, array $assoc_args ): Report {
		$snapshot = $this->factory->create(
			$thresholds,
			array(
				'loopback'         => empty( $assoc_args['skip-loopback'] ),
				'loopback_timeout' => (int) ( $assoc_args['loopback-timeout'] ?? 10 ),
				'action_scheduler' => empty( $assoc_args['skip-action-scheduler'] ),
				'ignored_hooks'    => HookPattern::parse_list( $assoc_args['ignore-hook'] ?? null ),
			)
		);

		return ( new Doctor() )->diagnose( $snapshot, $thresholds );
	}

	/**
	 * Removes duplicate events (same hook and arguments), keeping the copy that runs first.
	 *
	 * Without --yes this only shows what would be removed.
	 *
	 * ## OPTIONS
	 *
	 * [--hook=<hooks>]
	 * : Only these comma separated hooks or patterns (`*` matches any characters).
	 *
	 * [--exclude-hook=<hooks>]
	 * : Never touch these comma separated hooks or patterns.
	 *
	 * [--yes]
	 * : Remove the events.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp cron-doctor dedupe
	 *     $ wp cron-doctor dedupe --hook=woocommerce_cleanup_sessions --yes
	 *     $ wp cron-doctor dedupe --hook='woocommerce_*' --exclude-hook=woocommerce_scheduled_sales
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 * @return void
	 */
	public function dedupe( array $args, array $assoc_args ): void {
		$cron = $this->cron();
		$plan = ( new DuplicatePlanner() )->plan(
			$cron,
			HookPattern::parse_list( $assoc_args['hook'] ?? null ),
			HookPattern::parse_list( $assoc_args['exclude-hook'] ?? null )
		);

		$this->apply_removals( $plan, $assoc_args, 'duplicate' );
	}

	/**
	 * Removes events whose hook has no callback.
	 *
	 * Callbacks that plugins attach only in wp-admin, only during cron requests or only
	 * under other conditions are invisible here; review the list before using --yes.
	 * Without --yes this only shows what would be removed.
	 *
	 * ## OPTIONS
	 *
	 * [--hook=<hooks>]
	 * : Only these comma separated hooks or patterns.
	 *
	 * [--ignore-hook=<hooks>]
	 * : Never remove these comma separated hooks or patterns.
	 *
	 * [--cron-context]
	 * : Load WordPress as a cron request (defines DOING_CRON) before looking for callbacks.
	 *
	 * [--yes]
	 * : Remove the events.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp cron-doctor clean-orphans --cron-context
	 *     $ wp cron-doctor clean-orphans --hook='old_plugin_*' --yes
	 *
	 * @subcommand clean-orphans
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 * @return void
	 */
	public function clean_orphans( array $args, array $assoc_args ): void {
		$snapshot = $this->factory->create(
			new Thresholds(),
			array(
				'loopback'         => false,
				'action_scheduler' => false,
				'ignored_hooks'    => HookPattern::parse_list( $assoc_args['ignore-hook'] ?? null ),
			)
		);

		$plan = ( new OrphanPlanner() )->plan( $snapshot, HookPattern::parse_list( $assoc_args['hook'] ?? null ) );

		$this->apply_removals( $plan, $assoc_args, 'orphaned' );
	}

	/**
	 * Runs overdue events now, oldest first, like wp-cron.php would.
	 *
	 * Recurring events are rescheduled before they run. Hooks run in this WP-CLI process,
	 * so there is no web server timeout.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Maximum events to run (0 = all due events).
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--grace=<duration>]
	 * : Only events at least this late, e.g. 10m.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--hook=<hooks>]
	 * : Only these comma separated hooks or patterns.
	 *
	 * [--exclude-hook=<hooks>]
	 * : Skip these comma separated hooks or patterns.
	 *
	 * [--dry-run]
	 * : Only list the events that would run.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp cron-doctor run-overdue --limit=25
	 *     $ wp cron-doctor run-overdue --grace=1h --exclude-hook='wp_version_check' --dry-run
	 *
	 * @subcommand run-overdue
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 * @return void
	 */
	public function run_overdue( array $args, array $assoc_args ): void {
		$limit = (string) ( $assoc_args['limit'] ?? '10' );
		if ( ! ctype_digit( $limit ) ) {
			WP_CLI::error( '--limit must be a whole number.' );
			return;
		}

		try {
			$grace = Duration::parse( (string) ( $assoc_args['grace'] ?? '0' ) );
		} catch ( InvalidArgumentException $e ) {
			WP_CLI::error( '--grace: ' . $e->getMessage() );
			return;
		}

		$now    = time();
		$events = ( new OverdueSelector() )->select(
			$this->cron(),
			$now,
			(int) $limit,
			$grace,
			HookPattern::parse_list( $assoc_args['hook'] ?? null ),
			HookPattern::parse_list( $assoc_args['exclude-hook'] ?? null )
		);

		$format = (string) ( $assoc_args['format'] ?? 'table' );

		if ( array() === $events ) {
			WP_CLI::success( 'No due events match.' );
			return;
		}

		$rows   = array();
		$failed = 0;
		foreach ( $events as $event ) {
			$row = $event->to_row( $now );
			if ( ! empty( $assoc_args['dry-run'] ) ) {
				$row['result'] = 'would run';
			} else {
				$result         = $this->runner->run( $event );
				$row['result']  = $result['status'];
				$row['seconds'] = $result['seconds'];
				$row['error']   = $result['error'];
				if ( 'error' === $result['status'] ) {
					++$failed;
				}
			}
			$rows[] = $row;
		}

		$fields = ! empty( $assoc_args['dry-run'] )
			? array( 'hook', 'args', 'due', 'late', 'schedule', 'result' )
			: array( 'hook', 'args', 'late', 'result', 'seconds', 'error' );
		$this->display( $format, $rows, $fields );

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			WP_CLI::log( sprintf( 'Dry run: %d event(s) would run.', count( $rows ) ) );
			return;
		}

		if ( $failed > 0 ) {
			WP_CLI::error( sprintf( '%d of %d event(s) failed.', $failed, count( $rows ) ) );
			return;
		}

		WP_CLI::success( sprintf( 'Ran %d event(s).', count( $rows ) ) );
	}

	/**
	 * Prints a crontab entry and a systemd timer that run WP-Cron for this site.
	 *
	 * Add `define( 'DISABLE_WP_CRON', true );` to wp-config.php once the system cron works.
	 *
	 * ## OPTIONS
	 *
	 * [--method=<method>]
	 * : How to trigger cron.
	 * ---
	 * default: wp-cli
	 * options:
	 *   - wp-cli
	 *   - curl
	 *   - wget
	 * ---
	 *
	 * [--interval=<minutes>]
	 * : Minutes between runs (1-60).
	 * ---
	 * default: 5
	 * ---
	 *
	 * [--type=<type>]
	 * : What to print.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - crontab
	 *   - systemd
	 * ---
	 *
	 * [--wp-bin=<path>]
	 * : WP-CLI binary used in the command. Defaults to the running WP-CLI phar, else "wp".
	 *
	 * [--user=<user>]
	 * : System user that runs the job. Defaults to the owner of the WordPress files.
	 *
	 * [--network]
	 * : Run cron for every site of a multisite network.
	 *
	 * [--log=<file>]
	 * : Append output to this file instead of discarding it.
	 *
	 * [--lock-dir=<dir>]
	 * : Directory for the flock lock file.
	 * ---
	 * default: /tmp
	 * ---
	 *
	 * [--format=<format>]
	 * : Plain text or JSON.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp cron-doctor system-cron
	 *     $ wp cron-doctor system-cron --method=curl --interval=1 --type=crontab
	 *     $ wp cron-doctor system-cron --network --user=deploy --wp-bin=/usr/local/bin/wp
	 *
	 * @subcommand system-cron
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 * @return void
	 */
	public function system_cron( array $args, array $assoc_args ): void {
		$method  = (string) ( $assoc_args['method'] ?? 'wp-cli' );
		$network = ! empty( $assoc_args['network'] );

		if ( $network && ! is_multisite() ) {
			WP_CLI::error( '--network needs a multisite installation.' );
			return;
		}

		$options = array(
			'method'   => $method,
			'interval' => $assoc_args['interval'] ?? 5,
			'path'     => untrailingslashit( ABSPATH ),
			'url'      => 'wp-cli' === $method ? home_url() : site_url(),
			'wp'       => (string) ( $assoc_args['wp-bin'] ?? $this->detect_wp_bin() ),
			'user'     => (string) ( $assoc_args['user'] ?? $this->detect_user() ),
			'network'  => $network,
			'log'      => (string) ( $assoc_args['log'] ?? '/dev/null' ),
			'lock_dir' => (string) ( $assoc_args['lock-dir'] ?? '/tmp' ),
		);

		if ( $network && 'wp-cli' !== $method ) {
			$options['urls'] = $this->network_urls();
		}

		try {
			$generator = new Generator( $options );
		} catch ( InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		$output = $generator->generate();
		$type   = (string) ( $assoc_args['type'] ?? 'all' );

		if ( 'json' === ( $assoc_args['format'] ?? 'text' ) ) {
			WP_CLI::line( (string) wp_json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( 'all' === $type || 'crontab' === $type ) {
			WP_CLI::line( '# --- crontab ---' );
			WP_CLI::line( rtrim( $output['crontab'] ) );
			WP_CLI::line( '' );
		}

		if ( 'all' === $type || 'systemd' === $type ) {
			WP_CLI::line( sprintf( '# --- /etc/systemd/system/%s.service ---', $output['unit'] ) );
			WP_CLI::line( rtrim( $output['service'] ) );
			WP_CLI::line( '' );
			WP_CLI::line( sprintf( '# --- /etc/systemd/system/%s.timer ---', $output['unit'] ) );
			WP_CLI::line( rtrim( $output['timer'] ) );
			WP_CLI::line( '' );
			WP_CLI::line( rtrim( $output['install'] ) );
			WP_CLI::line( '' );
		}

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			WP_CLI::line( '# DISABLE_WP_CRON is already set in wp-config.php.' );
		} else {
			WP_CLI::line( '# Then turn off visit-based cron in wp-config.php:' );
			WP_CLI::line( $output['wp_config'] );
		}
	}

	/**
	 * Validates the report options.
	 *
	 * @param array<string, mixed> $assoc_args Options.
	 * @param string               $format     Output format.
	 * @param string               $fail_on    Exit code threshold.
	 * @param string               $check      Check to show, or empty.
	 * @return Thresholds
	 * @throws InvalidArgumentException When an option is invalid.
	 */
	private function validate_report_options( array $assoc_args, string $format, string $fail_on, string $check ): Thresholds {
		$thresholds = Thresholds::from_options( $assoc_args );

		if ( ! in_array( $fail_on, array( Severity::WARNING, Severity::CRITICAL, Report::FAIL_NEVER ), true ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid --fail-on value "%s". Use warning, critical or never.', $fail_on ) );
		}
		if ( 'nagios' !== $format && ! in_array( $format, self::FORMATS, true ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid format "%s". Use table, json, csv, yaml, count or nagios.', $format ) );
		}
		if ( '' !== $check && ! in_array( $check, Doctor::check_ids(), true ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown check "%s". Known checks: %s.', $check, implode( ', ', Doctor::check_ids() ) ) );
		}

		return $thresholds;
	}

	/**
	 * Shows or applies a removal plan.
	 *
	 * @param Event[]              $plan       Events to remove.
	 * @param array<string, mixed> $assoc_args Options.
	 * @param string               $label      What kind of events.
	 * @return void
	 */
	private function apply_removals( array $plan, array $assoc_args, string $label ): void {
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$now    = time();

		if ( array() === $plan ) {
			WP_CLI::success( sprintf( 'No %s events found.', $label ) );
			return;
		}

		$apply  = ! empty( $assoc_args['yes'] );
		$rows   = array();
		$failed = 0;
		foreach ( $plan as $event ) {
			$row = $event->to_row( $now );
			if ( $apply ) {
				$outcome       = $this->runner->unschedule( $event );
				$row['result'] = true === $outcome ? 'removed' : 'error: ' . $outcome;
				if ( true !== $outcome ) {
					++$failed;
				}
			} else {
				$row['result'] = 'would remove';
			}
			$rows[] = $row;
		}

		$this->display( $format, $rows, array( 'hook', 'args', 'due', 'schedule', 'result' ) );

		if ( ! $apply ) {
			WP_CLI::log( sprintf( 'Dry run: %d %s event(s) would be removed. Run again with --yes to remove them.', count( $rows ), $label ) );
			return;
		}

		if ( $failed > 0 ) {
			WP_CLI::error( sprintf( 'Removed %d of %d %s event(s); %d failed.', count( $rows ) - $failed, count( $rows ), $label, $failed ) );
			return;
		}

		WP_CLI::success( sprintf( 'Removed %d %s event(s).', count( $rows ), $label ) );
	}

	/**
	 * Parsed cron option.
	 *
	 * @return CronArray
	 */
	private function cron(): CronArray {
		return CronArray::from_option( get_option( 'cron' ) );
	}

	/**
	 * Formats rows.
	 *
	 * @param string               $format Format.
	 * @param array<int, mixed>    $rows   Rows.
	 * @param string|string[]|null $fields Fields.
	 * @return void
	 */
	private function display( string $format, array $rows, $fields ): void {
		if ( is_string( $fields ) ) {
			$fields = array_filter( array_map( 'trim', explode( ',', $fields ) ) );
		}
		\WP_CLI\Utils\format_items( $format, $rows, array_values( (array) $fields ) );
	}

	/**
	 * Reports that the check could not run (exit code 3).
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function unknown( string $message ): void {
		WP_CLI::error( $message, false );
		$this->halt( Severity::EXIT_UNKNOWN );
	}

	/**
	 * Exits with a code when it is not zero.
	 *
	 * @param int $code Exit code.
	 * @return void
	 */
	private function halt( int $code ): void {
		if ( 0 !== $code ) {
			WP_CLI::halt( $code );
		}
	}

	/**
	 * Path of the running WP-CLI phar, else "wp".
	 *
	 * @return string
	 */
	private function detect_wp_bin(): string {
		if ( class_exists( 'Phar' ) ) {
			$phar = \Phar::running( false );
			if ( '' !== $phar ) {
				return $phar;
			}
		}
		return 'wp';
	}

	/**
	 * Owner of the WordPress files, else www-data.
	 *
	 * @return string
	 */
	private function detect_user(): string {
		if ( function_exists( 'posix_getpwuid' ) ) {
			$owner = @fileowner( ABSPATH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort.
			if ( false !== $owner ) {
				$info = posix_getpwuid( $owner );
				if ( is_array( $info ) && ! empty( $info['name'] ) && 'root' !== $info['name'] ) {
					return (string) $info['name'];
				}
			}
		}
		return 'www-data';
	}

	/**
	 * URLs of all public sites in the network.
	 *
	 * @return string[]
	 */
	private function network_urls(): array {
		$ids = get_sites(
			array(
				'fields'   => 'ids',
				'number'   => 0,
				'archived' => 0,
				'deleted'  => 0,
				'spam'     => 0,
			)
		);

		return array_map(
			static function ( $id ): string {
				return get_site_url( (int) $id );
			},
			(array) $ids
		);
	}
}
