<?php
/**
 * Sends the loopback request.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\WP;

use Saizul\CronDoctor\Loopback\LoopbackResult;

/**
 * Requests wp-cron.php from the server itself, the way spawn_cron() does, without
 * running any event.
 *
 * The request is a POST with a non-empty body: wp-cron.php stops with `die()` before
 * loading WordPress when $_POST is not empty, so the probe proves the URL is reachable
 * while doing no work. Redirects are not followed, so a redirect is reported instead of
 * being turned into a GET that would run cron.
 */
final class LoopbackProbe {

	/**
	 * Runs the probe.
	 *
	 * @param int $timeout Seconds.
	 * @return LoopbackResult
	 */
	public function run( int $timeout = 10 ): LoopbackResult {
		$url = $this->url();

		/**
		 * Filters the loopback probe request arguments.
		 *
		 * @param array  $args Arguments for wp_remote_post().
		 * @param string $url  Requested URL.
		 */
		$args = apply_filters(
			'wp_cron_doctor_loopback_args',
			array(
				'timeout'     => max( 1, $timeout ),
				'blocking'    => true,
				'redirection' => 0,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, applied like spawn_cron() does.
				'body'        => array( 'wp_cron_doctor_probe' => '1' ),
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			),
			$url
		);

		$start    = microtime( true );
		$response = wp_remote_post( $url, $args );
		$duration = microtime( true ) - $start;

		if ( is_wp_error( $response ) ) {
			return LoopbackResult::failure( $url, $response->get_error_message(), $duration );
		}

		$status   = (int) wp_remote_retrieve_response_code( $response );
		$location = (string) wp_remote_retrieve_header( $response, 'location' );

		if ( 0 === $status ) {
			return LoopbackResult::failure( $url, 'Empty response', $duration );
		}

		return LoopbackResult::response( $url, $status, $duration, $location );
	}

	/**
	 * The cron URL, honouring the `cron_request` filter used by spawn_cron().
	 *
	 * @return string
	 */
	public function url(): string {
		$default = site_url( 'wp-cron.php' );

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, applied like spawn_cron() does.
		/** This filter is documented in wp-includes/cron.php */
		$request = apply_filters(
			'cron_request',
			array(
				'url'  => $default,
				'key'  => '',
				'args' => array(
					'timeout'   => 0.01,
					'blocking'  => false,
					'sslverify' => false,
				),
			),
			''
		);
		// phpcs:enable

		$url = ( is_array( $request ) && ! empty( $request['url'] ) && is_string( $request['url'] ) ) ? $request['url'] : $default;

		return remove_query_arg( 'doing_wp_cron', $url );
	}
}
