<?php
/**
 * WP-CLI package entry point (loaded by Composer's autoloader or by the plugin file).
 *
 * @package Saizul\CronDoctor
 */

if ( ! class_exists( 'WP_CLI' ) || defined( 'WP_CRON_DOCTOR_CLI_LOADED' ) ) {
	return;
}

define( 'WP_CRON_DOCTOR_CLI_LOADED', true );

WP_CLI::add_command( 'cron-doctor', \Saizul\CronDoctor\WP\Command::class );

/*
 * --cron-context: load WordPress as a cron request so plugins that attach cron callbacks
 * only when wp_doing_cron() is true are visible to the orphan check. This only works when
 * the package is installed with `wp package install`, because the hook has to run before
 * WordPress (and therefore any plugin) is loaded.
 */
WP_CLI::add_hook(
	'before_wp_load',
	static function () {
		$runner = WP_CLI::get_runner();
		// The runner exposes these through __get() only, so isset() would be false.
		$args  = (array) $runner->arguments;
		$assoc = (array) $runner->assoc_args;

		if ( isset( $args[0] ) && 'cron-doctor' === $args[0] && ! empty( $assoc['cron-context'] ) && ! defined( 'DOING_CRON' ) ) {
			define( 'DOING_CRON', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant.
		}
	}
);

/*
 * Record a heartbeat when a system cron job runs `wp cron event run --due-now`, so the
 * report can tell that a system cron is active even without the plugin.
 */
WP_CLI::add_hook(
	'after_invoke:cron event run',
	static function () {
		$runner = WP_CLI::get_runner();
		$assoc  = (array) $runner->assoc_args;

		if ( \Saizul\CronDoctor\Heartbeat\Heartbeat::is_due_now_run( $assoc ) && function_exists( 'update_option' ) ) {
			( new \Saizul\CronDoctor\WP\HeartbeatRecorder() )->record( \Saizul\CronDoctor\Heartbeat\Heartbeat::SOURCE_WP_CLI );
		}
	}
);
