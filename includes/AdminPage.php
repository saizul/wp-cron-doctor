<?php
/**
 * Tools > Cron Doctor.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\WP;

use InvalidArgumentException;
use Saizul\CronDoctor\Doctor;
use Saizul\CronDoctor\Report\Finding;
use Saizul\CronDoctor\Report\Report;
use Saizul\CronDoctor\Report\Severity;
use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\SystemCron\Generator;

/**
 * Read-only report page. Repairs are left to WP-CLI on purpose.
 */
final class AdminPage {

	public const SLUG          = 'wp-cron-doctor';
	public const NONCE_ACTION  = 'wp_cron_doctor_loopback';
	public const MAX_DETAIL    = 50;
	private const BADGE_COLORS = array(
		Severity::OK       => '#00a32a',
		Severity::INFO     => '#2271b1',
		Severity::WARNING  => '#dba617',
		Severity::CRITICAL => '#d63638',
	);

	/**
	 * Snapshot factory.
	 *
	 * @var SnapshotFactory|null
	 */
	private ?SnapshotFactory $factory;

	/**
	 * Constructor.
	 *
	 * @param SnapshotFactory|null $factory Snapshot factory (created on demand when null).
	 */
	public function __construct( ?SnapshotFactory $factory = null ) {
		$this->factory = $factory;
	}

	/**
	 * Capability needed to see the page.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability required for Tools > Cron Doctor.
		 *
		 * @param string $capability Capability. Default manage_options.
		 */
		return (string) apply_filters( 'wp_cron_doctor_capability', 'manage_options' );
	}

	/**
	 * Adds the menu entry.
	 *
	 * @return void
	 */
	public function register(): void {
		add_management_page(
			__( 'Cron Doctor', 'wp-cron-doctor' ),
			__( 'Cron Doctor', 'wp-cron-doctor' ),
			self::capability(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Whether a valid loopback request was made from the page.
	 *
	 * @return bool
	 */
	private function loopback_requested(): bool {
		if ( ! isset( $_GET['loopback'], $_GET['_wpnonce'] ) ) {
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );

		return false !== wp_verify_nonce( $nonce, self::NONCE_ACTION );
	}

	/**
	 * Prints the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'wp-cron-doctor' ) );
		}

		$loopback   = $this->loopback_requested();
		$thresholds = new Thresholds();
		$factory    = $this->factory ?? new SnapshotFactory();
		$snapshot   = $factory->create( $thresholds, array( 'loopback' => $loopback ) );
		$report     = ( new Doctor() )->diagnose( $snapshot, $thresholds );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Cron Doctor', 'wp-cron-doctor' ) . '</h1>';
		echo '<p>' . esc_html__( 'Health of WP-Cron and Action Scheduler on this site. This page only reads data; use the wp cron-doctor WP-CLI command to repair problems.', 'wp-cron-doctor' ) . '</p>';

		$this->render_overall( $report );
		$this->render_loopback_form( $loopback );
		$this->render_table( $report );
		$this->render_system_cron();

		echo '</div>';
	}

	/**
	 * Overall status line.
	 *
	 * @param Report $report Report.
	 * @return void
	 */
	private function render_overall( Report $report ): void {
		$totals = $report->totals();

		printf(
			'<p><strong>%1$s</strong> %2$s &nbsp; %3$s</p>',
			esc_html__( 'Overall:', 'wp-cron-doctor' ),
			$this->badge( $report->status() ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
			esc_html(
				sprintf(
					/* translators: 1: critical count, 2: warning count, 3: info count, 4: ok count */
					__( '%1$d critical, %2$d warning, %3$d info, %4$d ok', 'wp-cron-doctor' ),
					$totals[ Severity::CRITICAL ],
					$totals[ Severity::WARNING ],
					$totals[ Severity::INFO ],
					$totals[ Severity::OK ]
				)
			)
		);
	}

	/**
	 * Button that runs the loopback test.
	 *
	 * @param bool $done Whether the test ran in this request.
	 * @return void
	 */
	private function render_loopback_form( bool $done ): void {
		echo '<form method="get" action="' . esc_url( admin_url( 'tools.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input type="hidden" name="loopback" value="1" />';
		wp_nonce_field( self::NONCE_ACTION, '_wpnonce', false );
		submit_button(
			$done ? __( 'Run the loopback test again', 'wp-cron-doctor' ) : __( 'Include the loopback test', 'wp-cron-doctor' ),
			'secondary',
			'',
			false
		);
		echo '</form><br />';
	}

	/**
	 * Findings table.
	 *
	 * @param Report $report Report.
	 * @return void
	 */
	private function render_table( Report $report ): void {
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Check', 'wp-cron-doctor' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'wp-cron-doctor' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Summary', 'wp-cron-doctor' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $report->findings() as $finding ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( $finding->check() ) . '</code></td>';
			echo '<td>' . $this->badge( $finding->severity() ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
			echo '<td>' . esc_html( $finding->summary() );
			if ( '' !== $finding->advice() && $finding->is_problem() ) {
				echo '<br /><em>' . esc_html( $finding->advice() ) . '</em>';
			}
			$this->render_details( $finding );
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Collapsible detail rows.
	 *
	 * @param Finding $finding Finding.
	 * @return void
	 */
	private function render_details( Finding $finding ): void {
		$items = $finding->items();
		if ( array() === $items || ! $finding->is_problem() ) {
			return;
		}

		$shown = array_slice( $items, 0, self::MAX_DETAIL );
		$keys  = array_keys( $shown[0] );

		echo '<details><summary>';
		/* translators: %d: number of rows */
		echo esc_html( sprintf( _n( '%d detail row', '%d detail rows', count( $items ), 'wp-cron-doctor' ), count( $items ) ) );
		echo '</summary><table class="widefat"><thead><tr>';
		foreach ( $keys as $key ) {
			echo '<th scope="col">' . esc_html( (string) $key ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $shown as $row ) {
			echo '<tr>';
			foreach ( $keys as $key ) {
				$value = $row[ $key ] ?? '';
				echo '<td>' . esc_html( is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		if ( count( $items ) > self::MAX_DETAIL ) {
			echo '<p>' . esc_html__( 'More rows are available with: wp cron-doctor report --check=', 'wp-cron-doctor' ) . esc_html( $finding->check() ) . '</p>';
		}
		echo '</details>';
	}

	/**
	 * Suggested system cron entry.
	 *
	 * @return void
	 */
	private function render_system_cron(): void {
		try {
			$generator = new Generator(
				array(
					'method'   => 'curl',
					'interval' => 5,
					'url'      => site_url(),
				)
			);
		} catch ( InvalidArgumentException $e ) {
			return;
		}

		echo '<h2>' . esc_html__( 'System cron', 'wp-cron-doctor' ) . '</h2>';
		echo '<p>' . esc_html__( 'A server cron job is more reliable than visit-based WP-Cron. Add this to the crontab of the user that owns the site, then add the line below it to wp-config.php. "wp cron-doctor system-cron" prints a WP-CLI based entry and a systemd timer.', 'wp-cron-doctor' ) . '</p>';
		echo '<pre style="white-space:pre-wrap;background:#fff;padding:1em;border:1px solid #c3c4c7;">' . esc_html( $generator->crontab() . "\n" . Generator::wp_config_line() ) . '</pre>';
	}

	/**
	 * Status badge.
	 *
	 * @param string $severity Severity.
	 * @return string Escaped HTML.
	 */
	private function badge( string $severity ): string {
		$color = self::BADGE_COLORS[ $severity ] ?? '#50575e';

		return sprintf(
			'<span style="display:inline-block;padding:1px 8px;border-radius:10px;color:#fff;background:%1$s;font-weight:600;">%2$s</span>',
			esc_attr( $color ),
			esc_html( strtoupper( $severity ) )
		);
	}
}
