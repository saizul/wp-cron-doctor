<?php
/**
 * Builds crontab lines and systemd units.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\SystemCron;

use InvalidArgumentException;

/**
 * Generates a system cron setup that replaces visit-based WP-Cron.
 *
 * Methods:
 * - `wp-cli`: `wp cron event run --due-now`, runs in the CLI with no web server
 *   timeouts. Recommended when WP-CLI is installed on the server.
 * - `curl` / `wget`: an HTTP request to wp-cron.php without the `doing_wp_cron`
 *   argument, which wp-cron.php treats as an external trigger and locks itself.
 */
final class Generator {

	public const METHODS = array( 'wp-cli', 'curl', 'wget' );

	/**
	 * Normalized options.
	 *
	 * @var array{method: string, interval: int, path: string, url: string, urls: string[], wp: string, user: string, network: bool, log: string, lock_dir: string, timeout: int}
	 */
	private array $options;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $options {
	 *     Options.
	 *
	 *     @type string   $method   wp-cli, curl or wget. Default wp-cli.
	 *     @type int      $interval Minutes between runs, 1 to 60. Default 5.
	 *     @type string   $path     Absolute WordPress path (required for wp-cli).
	 *     @type string   $url      Site URL (required).
	 *     @type string[] $urls     All site URLs of a network (curl/wget with network).
	 *     @type string   $wp       WP-CLI binary. Default "wp".
	 *     @type string   $user     System user that owns the files. Default "www-data".
	 *     @type bool     $network  Run every site of a multisite network.
	 *     @type string   $log      File for output. Default /dev/null.
	 *     @type string   $lock_dir Directory for the flock file. Default /tmp.
	 *     @type int      $timeout  HTTP timeout in seconds for curl/wget. Default 300.
	 * }
	 * @throws InvalidArgumentException For invalid options.
	 */
	public function __construct( array $options ) {
		$this->options = self::normalize( $options );
	}

	/**
	 * Validates and fills in options.
	 *
	 * @param array<string, mixed> $options Options.
	 * @return array{method: string, interval: int, path: string, url: string, urls: string[], wp: string, user: string, network: bool, log: string, lock_dir: string, timeout: int}
	 * @throws InvalidArgumentException For invalid options.
	 */
	private static function normalize( array $options ): array {
		$method = (string) ( $options['method'] ?? 'wp-cli' );
		if ( ! in_array( $method, self::METHODS, true ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown method "%s". Use wp-cli, curl or wget.', $method ) );
		}

		$interval = $options['interval'] ?? 5;
		if ( ! is_int( $interval ) && ! ctype_digit( (string) $interval ) ) {
			throw new InvalidArgumentException( 'The interval must be a whole number of minutes.' );
		}
		$interval = (int) $interval;
		if ( $interval < 1 || $interval > 60 ) {
			throw new InvalidArgumentException( 'The interval must be between 1 and 60 minutes.' );
		}

		$url = rtrim( (string) ( $options['url'] ?? '' ), '/' );
		if ( ! self::is_http_url( $url ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid site URL "%s".', $url ) );
		}

		$path = rtrim( (string) ( $options['path'] ?? '' ), '/' );
		if ( 'wp-cli' === $method && ( '' === $path || '/' !== $path[0] ) ) {
			throw new InvalidArgumentException( 'The WordPress path must be absolute for the wp-cli method.' );
		}

		$user = (string) ( $options['user'] ?? 'www-data' );
		if ( ! preg_match( '/^[a-z_][a-z0-9_.-]*\$?$/i', $user ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid system user "%s".', $user ) );
		}

		$urls = array();
		foreach ( (array) ( $options['urls'] ?? array() ) as $site_url ) {
			$site_url = rtrim( (string) $site_url, '/' );
			if ( ! self::is_http_url( $site_url ) ) {
				throw new InvalidArgumentException( sprintf( 'Invalid site URL "%s".', $site_url ) );
			}
			$urls[ $site_url ] = true;
		}
		$urls = array_map( 'strval', array_keys( $urls ) );
		if ( array() === $urls ) {
			$urls = array( $url );
		}

		$wp = (string) ( $options['wp'] ?? 'wp' );
		if ( '' === trim( $wp ) ) {
			$wp = 'wp';
		}

		$timeout = (int) ( $options['timeout'] ?? 300 );

		return array(
			'method'   => $method,
			'interval' => $interval,
			'path'     => $path,
			'url'      => $url,
			'urls'     => $urls,
			'wp'       => $wp,
			'user'     => $user,
			'network'  => ! empty( $options['network'] ),
			'log'      => (string) ( $options['log'] ?? '/dev/null' ),
			'lock_dir' => rtrim( (string) ( $options['lock_dir'] ?? '/tmp' ), '/' ),
			'timeout'  => max( 10, $timeout ),
		);
	}

	/**
	 * Whether the value is an http(s) URL with a host.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_http_url( string $url ): bool {
		if ( '' === $url || preg_match( '/\s/', $url ) ) {
			return false;
		}
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure code.
		$host   = (string) parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure code.

		return in_array( $scheme, array( 'http', 'https' ), true ) && '' !== $host;
	}

	/**
	 * Normalized options.
	 *
	 * @return array<string, mixed>
	 */
	public function options(): array {
		return $this->options;
	}

	/**
	 * Short identifier based on the host, e.g. "example-com".
	 *
	 * @return string
	 */
	public function slug(): string {
		$host = strtolower( (string) parse_url( $this->options['url'], PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure code.
		$path = trim( (string) parse_url( $this->options['url'], PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure code.
		$slug = preg_replace( '/[^a-z0-9]+/', '-', $host . ( '' === $path ? '' : '-' . strtolower( $path ) ) );

		return trim( (string) $slug, '-' );
	}

	/**
	 * Name of the systemd units without suffix.
	 *
	 * @return string
	 */
	public function unit_name(): string {
		return 'wp-cron-' . $this->slug();
	}

	/**
	 * The shell command that runs due events once.
	 *
	 * @return string
	 */
	public function command(): string {
		$o = $this->options;

		if ( 'wp-cli' === $o['method'] ) {
			$wp   = self::binary( $o['wp'] );
			$path = Shell::option( 'path', $o['path'] );

			if ( $o['network'] ) {
				return sprintf(
					'%1$s site list --field=url %2$s --quiet | xargs -r -I {} %1$s cron event run --due-now %2$s --url={} --quiet',
					$wp,
					$path
				);
			}

			return sprintf( '%s cron event run --due-now %s %s --quiet', $wp, $path, Shell::option( 'url', $o['url'] ) );
		}

		$commands = array();
		foreach ( $this->target_urls() as $url ) {
			$target = Shell::quote( $url . '/wp-cron.php' );
			if ( 'curl' === $o['method'] ) {
				$commands[] = sprintf( 'curl -fsS --max-time %d -o /dev/null %s', $o['timeout'], $target );
			} else {
				$commands[] = sprintf( 'wget -q -O /dev/null --timeout=%d %s', $o['timeout'], $target );
			}
		}

		return implode( '; ', $commands );
	}

	/**
	 * Quotes a binary that may contain arguments, e.g. "php /opt/wp-cli.phar".
	 *
	 * @param string $binary Binary and optional arguments.
	 * @return string
	 */
	private static function binary( string $binary ): string {
		$words = preg_split( '/\s+/', trim( $binary ) );
		$words = false === $words ? array( $binary ) : $words;

		return implode( ' ', array_map( array( Shell::class, 'quote' ), $words ) );
	}

	/**
	 * URLs requested by curl/wget.
	 *
	 * @return string[]
	 */
	private function target_urls(): array {
		return $this->options['network'] ? $this->options['urls'] : array( $this->options['url'] );
	}

	/**
	 * Cron schedule expression for the interval.
	 *
	 * @return string
	 */
	public function cron_expression(): string {
		$minutes = $this->options['interval'];
		if ( 60 === $minutes ) {
			return '0 * * * *';
		}
		if ( 1 === $minutes ) {
			return '* * * * *';
		}
		return '*/' . $minutes . ' * * * *';
	}

	/**
	 * The systemd OnCalendar expression for the interval.
	 *
	 * @return string
	 */
	public function calendar_expression(): string {
		$minutes = $this->options['interval'];
		if ( 60 === $minutes ) {
			return '*-*-* *:00:00';
		}
		return '*-*-* *:00/' . $minutes . ':00';
	}

	/**
	 * Crontab entry for the user's crontab (`crontab -u <user> -e`).
	 *
	 * @return string
	 */
	public function crontab(): string {
		$o       = $this->options;
		$lock    = $o['lock_dir'] . '/' . $this->unit_name() . '.lock';
		$command = sprintf( 'flock -n %s sh -c %s', Shell::quote( $lock ), Shell::quote( $this->command() ) );
		$log     = Shell::quote( $o['log'] );
		$line    = sprintf( '%s %s >> %s 2>&1', $this->cron_expression(), $command, $log );
		if ( '/dev/null' === $o['log'] ) {
			$line = sprintf( '%s %s > /dev/null 2>&1', $this->cron_expression(), $command );
		}

		$lines = array(
			sprintf( '# WP-Cron for %s, every %d minute(s). Install with: crontab -u %s -e', $o['url'], $o['interval'], $o['user'] ),
			'# flock skips a run while the previous one is still busy.',
			Shell::escape_crontab( $line ),
		);

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The systemd service unit.
	 *
	 * @return string
	 */
	public function systemd_service(): string {
		$o       = $this->options;
		$command = $this->command();
		$direct  = 'wp-cli' === $o['method'] && ! $o['network'] && 0 === strpos( $o['wp'], '/' );
		$exec    = $direct ? $command : '/bin/sh -c ' . Shell::quote( $command );

		$lines = array(
			'[Unit]',
			'Description=WP-Cron for ' . Shell::escape_systemd( $o['url'] ),
			'After=network-online.target',
			'Wants=network-online.target',
			'',
			'[Service]',
			'Type=oneshot',
			'User=' . $o['user'],
		);

		if ( 'wp-cli' === $o['method'] ) {
			$lines[] = 'WorkingDirectory=' . Shell::escape_systemd( $o['path'] );
		}

		$lines[] = 'ExecStart=' . Shell::escape_systemd( $exec );
		$lines[] = 'Nice=10';
		$lines[] = 'IOSchedulingClass=idle';
		$lines[] = 'TimeoutStartSec=' . max( 60, $o['interval'] * 60 * 3 );

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The systemd timer unit.
	 *
	 * @return string
	 */
	public function systemd_timer(): string {
		$o = $this->options;

		$lines = array(
			'[Unit]',
			sprintf( 'Description=Run WP-Cron for %s every %d minute(s)', Shell::escape_systemd( $o['url'] ), $o['interval'] ),
			'',
			'[Timer]',
			'OnCalendar=' . $this->calendar_expression(),
			'AccuracySec=30s',
			'RandomizedDelaySec=15s',
			'Persistent=true',
			'Unit=' . $this->unit_name() . '.service',
			'',
			'[Install]',
			'WantedBy=timers.target',
		);

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Commands that install the systemd units.
	 *
	 * @return string
	 */
	public function systemd_install(): string {
		$name = $this->unit_name();

		return implode(
			"\n",
			array(
				sprintf( '# Save the units as /etc/systemd/system/%1$s.service and /etc/systemd/system/%1$s.timer, then:', $name ),
				'sudo systemctl daemon-reload',
				sprintf( 'sudo systemctl enable --now %s.timer', $name ),
				sprintf( 'systemctl list-timers %s.timer', $name ),
			)
		) . "\n";
	}

	/**
	 * The wp-config.php line that turns off visit-based cron.
	 *
	 * @return string
	 */
	public static function wp_config_line(): string {
		return "define( 'DISABLE_WP_CRON', true );";
	}

	/**
	 * Everything at once.
	 *
	 * @return array{crontab: string, service: string, timer: string, install: string, wp_config: string, unit: string}
	 */
	public function generate(): array {
		return array(
			'crontab'   => $this->crontab(),
			'service'   => $this->systemd_service(),
			'timer'     => $this->systemd_timer(),
			'install'   => $this->systemd_install(),
			'wp_config' => self::wp_config_line(),
			'unit'      => $this->unit_name(),
		);
	}
}
