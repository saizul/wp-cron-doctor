<?php
/**
 * Loopback request test.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Check;

use Saizul\CronDoctor\Report\Finding;
use Saizul\CronDoctor\Report\Severity;
use Saizul\CronDoctor\Report\Thresholds;
use Saizul\CronDoctor\Snapshot;

/**
 * Interprets the result of a request from the server to its own wp-cron.php.
 *
 * Visit-based WP-Cron depends on this request (spawn_cron()). When DISABLE_WP_CRON is
 * set a failing loopback does not stop cron, so problems are reported as info only.
 */
final class LoopbackCheck implements Check {

	public const ID = 'loopback';

	/**
	 * Runs the check.
	 *
	 * @param Snapshot   $snapshot   Collected data.
	 * @param Thresholds $thresholds Limits.
	 * @return Finding[]
	 */
	public function run( Snapshot $snapshot, Thresholds $thresholds ): array {
		$result = $snapshot->loopback;

		if ( null === $result || $result->is_skipped() ) {
			$reason = null === $result ? 'not requested' : $result->skip_reason();
			return array( new Finding( self::ID, Severity::INFO, 'Loopback test skipped: ' . $reason . '.' ) );
		}

		$items = array(
			array(
				'url'      => $result->url(),
				'status'   => null === $result->status() ? '' : $result->status(),
				'error'    => $result->error(),
				'seconds'  => round( $result->duration(), 3 ),
				'location' => $result->location(),
			),
		);

		list( $severity, $summary, $advice ) = $this->classify( $snapshot, $thresholds );

		if ( $snapshot->disable_wp_cron && Severity::at_least( $severity, Severity::WARNING ) ) {
			$severity = Severity::INFO;
			$summary .= ' WP-Cron does not depend on it because DISABLE_WP_CRON is set.';
		}

		return array( new Finding( self::ID, $severity, $summary, round( $result->duration(), 3 ), $items, $advice ) );
	}

	/**
	 * Severity, summary and advice for the result.
	 *
	 * @param Snapshot   $snapshot   Collected data.
	 * @param Thresholds $thresholds Limits.
	 * @return array{0: string, 1: string, 2: string}
	 */
	private function classify( Snapshot $snapshot, Thresholds $thresholds ): array {
		$result = $snapshot->loopback;
		$time   = sprintf( '%.2fs', $result->duration() );

		if ( $result->is_failure() ) {
			if ( $result->is_timeout() ) {
				return array( Severity::CRITICAL, sprintf( 'The loopback request to wp-cron.php timed out after %s.', $time ), 'The server cannot reach itself in time. Check firewall rules, DNS for the site domain on the server and PHP worker limits.' );
			}
			return array( Severity::CRITICAL, 'The loopback request to wp-cron.php failed: ' . $result->error(), 'Check that the server resolves its own domain, that outgoing HTTP to itself is allowed and that the TLS certificate is valid.' );
		}

		$status = (int) $result->status();

		if ( $status >= 200 && $status < 300 ) {
			if ( $result->duration() >= $thresholds->loopback_slow ) {
				return array( Severity::WARNING, sprintf( 'wp-cron.php answered with HTTP %d but took %s.', $status, $time ), 'spawn_cron() waits only 0.01 seconds by default, but a slow server delays the start of every cron run.' );
			}
			return array( Severity::OK, sprintf( 'wp-cron.php is reachable (HTTP %d in %s).', $status, $time ), '' );
		}

		if ( $status >= 300 && $status < 400 ) {
			$target = '' === $result->location() ? '' : ' to ' . $result->location();
			return array( Severity::WARNING, sprintf( 'wp-cron.php redirects (HTTP %d%s).', $status, $target ), 'Redirects (http to https, www, trailing slash rules) turn the cron POST into a GET or drop it. Make siteurl match the canonical URL.' );
		}

		if ( 401 === $status || 403 === $status ) {
			return array( Severity::CRITICAL, sprintf( 'wp-cron.php is blocked (HTTP %d).', $status ), 'HTTP authentication, a security plugin or a WAF blocks requests to wp-cron.php from the server itself. Allow the server IP or use a system cron.' );
		}

		if ( 404 === $status ) {
			return array( Severity::CRITICAL, 'wp-cron.php was not found (HTTP 404).', 'Check the siteurl option and rewrite rules that hide wp-cron.php.' );
		}

		if ( 429 === $status ) {
			return array( Severity::CRITICAL, 'wp-cron.php is rate limited (HTTP 429).', 'A rate limiter treats the server as a client. Exclude the server IP or wp-cron.php.' );
		}

		return array( Severity::CRITICAL, sprintf( 'wp-cron.php returned HTTP %d.', $status ), 'Look at the PHP error log for fatal errors during cron requests.' );
	}
}
