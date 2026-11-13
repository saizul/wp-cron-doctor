<?php
/**
 * In-memory state behind the WordPress function stubs.
 *
 * @package Saizul\CronDoctor
 */

declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Stubs;

/**
 * Global state that tests reset in setUp().
 */
final class WpState {

	/** @var array<string, mixed> */
	public static array $options = array();

	/** @var array<string, mixed> */
	public static array $transients = array();

	/** @var array<string, array<int, callable>> */
	public static array $actions = array();

	/** @var array<string, array<int, callable>> */
	public static array $filters = array();

	/** @var array<string, array{interval: int, display: string}> */
	public static array $schedules = array();

	/** @var array<int, array{0: string, 1: array<int, mixed>}> */
	public static array $fired = array();

	/** @var array<int, array{url: string, args: array<string, mixed>}> */
	public static array $requests = array();

	/** @var mixed Next wp_remote_post() result. */
	public static $remote_response = null;

	/** @var bool */
	public static bool $doing_cron = false;

	/** @var bool */
	public static bool $can = true;

	/** @var bool */
	public static bool $nonce_valid = true;

	/** @var array<int, array<string, mixed>> */
	public static array $menu_pages = array();

	/** @var string */
	public static string $site_url = 'https://example.com';

	/** @var string */
	public static string $home_url = 'https://example.com';

	/** @var bool */
	public static bool $multisite = false;

	/** @var array<int, string> */
	public static array $sites = array();

	/** @var mixed Forced result of wp_unschedule_event(), null = emulate. */
	public static $unschedule_result = null;

	/** @var mixed Forced result of wp_reschedule_event(), null = emulate. */
	public static $reschedule_result = null;

	/**
	 * Resets everything.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$options           = array();
		self::$transients        = array();
		self::$actions           = array();
		self::$filters           = array();
		self::$fired             = array();
		self::$requests          = array();
		self::$remote_response   = null;
		self::$doing_cron        = false;
		self::$can               = true;
		self::$nonce_valid       = true;
		self::$menu_pages        = array();
		self::$site_url          = 'https://example.com';
		self::$home_url          = 'https://example.com';
		self::$multisite         = false;
		self::$sites             = array();
		self::$unschedule_result = null;
		self::$reschedule_result = null;
		self::$schedules         = array(
			'hourly'     => array(
				'interval' => 3600,
				'display'  => 'Once Hourly',
			),
			'twicedaily' => array(
				'interval' => 43200,
				'display'  => 'Twice Daily',
			),
			'daily'      => array(
				'interval' => 86400,
				'display'  => 'Once Daily',
			),
			'weekly'     => array(
				'interval' => 604800,
				'display'  => 'Once Weekly',
			),
		);
		$_GET = array();
	}
}
