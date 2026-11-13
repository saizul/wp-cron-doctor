<?php
declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Unit\WP;

use Saizul\CronDoctor\Tests\Stubs\WpState;
use Saizul\CronDoctor\WP\LoopbackProbe;
use WP_Error;

final class LoopbackProbeTest extends WpTestCase {

	public function test_successful_probe_is_a_harmless_post(): void {
		$result = ( new LoopbackProbe() )->run( 7 );

		$this->assertFalse( $result->is_failure() );
		$this->assertSame( 200, $result->status() );
		$this->assertSame( 'https://example.com/wp-cron.php', $result->url() );

		$request = WpState::$requests[0];
		$this->assertSame( 'https://example.com/wp-cron.php', $request['url'] );
		$this->assertSame( 7, $request['args']['timeout'] );
		$this->assertSame( 0, $request['args']['redirection'] );
		$this->assertTrue( $request['args']['blocking'] );
		$this->assertFalse( $request['args']['sslverify'] );
		// A non-empty POST body makes wp-cron.php exit before loading WordPress.
		$this->assertNotEmpty( $request['args']['body'] );
	}

	public function test_honours_cron_request_filter_and_strips_the_key(): void {
		add_filter(
			'cron_request',
			static function ( array $request ): array {
				$request['url'] = 'http://127.0.0.1/wp-cron.php?doing_wp_cron=123&lang=en';
				return $request;
			}
		);

		$this->assertSame( 'http://127.0.0.1/wp-cron.php?lang=en', ( new LoopbackProbe() )->url() );
	}

	public function test_args_filter_and_ssl_filter(): void {
		add_filter( 'https_local_ssl_verify', static fn() => true );
		add_filter(
			'wp_cron_doctor_loopback_args',
			static function ( array $args ): array {
				$args['headers']['X-Test'] = '1';
				return $args;
			}
		);

		( new LoopbackProbe() )->run();

		$this->assertTrue( WpState::$requests[0]['args']['sslverify'] );
		$this->assertSame( '1', WpState::$requests[0]['args']['headers']['X-Test'] );
	}

	public function test_wp_error_is_a_failure(): void {
		WpState::$remote_response = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		$result = ( new LoopbackProbe() )->run();

		$this->assertTrue( $result->is_failure() );
		$this->assertTrue( $result->is_timeout() );
	}

	public function test_redirect_location_is_kept(): void {
		WpState::$remote_response = array(
			'response' => array( 'code' => 301 ),
			'headers'  => array( 'location' => 'https://www.example.com/wp-cron.php' ),
		);

		$result = ( new LoopbackProbe() )->run();

		$this->assertSame( 301, $result->status() );
		$this->assertSame( 'https://www.example.com/wp-cron.php', $result->location() );
	}

	public function test_empty_status_is_a_failure(): void {
		WpState::$remote_response = array( 'response' => array( 'code' => '' ) );

		$this->assertSame( 'Empty response', ( new LoopbackProbe() )->run()->error() );
	}
}
