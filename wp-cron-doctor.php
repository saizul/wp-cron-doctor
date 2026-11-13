<?php
/**
 * Plugin Name:       Cron Doctor
 * Plugin URI:        https://github.com/saizul/wp-cron-doctor
 * Description:       Diagnoses WP-Cron and Action Scheduler problems: overdue, duplicate and orphaned events, loopback and system cron checks. Adds Tools > Cron Doctor and records when cron runs. The full toolkit is the "wp cron-doctor" WP-CLI command.
 * Version:           0.1.0
 * Requires at least: 5.7
 * Requires PHP:      7.4
 * Author:            Saizul Amin
 * Author URI:        https://saizul.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       wp-cron-doctor
 *
 * @package Saizul\CronDoctor
 */

defined( 'ABSPATH' ) || exit;

define( 'WP_CRON_DOCTOR_VERSION', '0.1.0' );
define( 'WP_CRON_DOCTOR_FILE', __FILE__ );

spl_autoload_register(
	static function ( string $class_name ): void {
		$map = array(
			'Saizul\\CronDoctor\\WP\\' => __DIR__ . '/includes/',
			'Saizul\\CronDoctor\\'     => __DIR__ . '/src/',
		);
		foreach ( $map as $prefix => $dir ) {
			if ( 0 === strpos( $class_name, $prefix ) ) {
				$file = $dir . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
				return;
			}
		}
	}
);

\Saizul\CronDoctor\WP\Plugin::boot();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/command.php';
}
