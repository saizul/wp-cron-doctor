<?php
/**
 * Plugin mode bootstrap.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\WP;

/**
 * Wires the heartbeat recorder and the admin page.
 */
final class Plugin {

	/**
	 * Version.
	 */
	public const VERSION = '0.1.0';

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		$recorder = new HeartbeatRecorder();
		add_action( 'plugins_loaded', array( $recorder, 'record_current_request' ), 1 );

		$page = new AdminPage();
		add_action( 'admin_menu', array( $page, 'register' ) );
	}

	/**
	 * Allows boot() to run again (tests only).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$booted = false;
	}
}
