<?php
/**
 * Removes the last-run record when the plugin is deleted.
 *
 * @package Saizul\CronDoctor
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wp_cron_doctor_last_run' );
