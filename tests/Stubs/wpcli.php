<?php
/**
 * Minimal WP-CLI stubs that record output instead of printing it.
 *
 * @package Saizul\CronDoctor
 */

// phpcs:ignoreFile

declare(strict_types=1);

namespace {

	/**
	 * Thrown instead of exiting.
	 */
	class WP_CLI_Stub_Exit extends RuntimeException {
	}

	/**
	 * Recording WP_CLI facade.
	 */
	class WP_CLI {

		/** @var array<int, array{0: string, 1: string}> */
		public static array $output = array();

		/** @var array<int, array{format: string, items: array, fields: array}> */
		public static array $formatted = array();

		/** @var array<string, mixed> */
		public static array $commands = array();

		/** @var array<string, array<int, callable>> */
		public static array $hooks = array();

		/** @var object|null */
		public static $runner = null;

		public static function reset(): void {
			self::$output    = array();
			self::$formatted = array();
			self::$commands  = array();
			self::$hooks     = array();
			self::$runner    = null;
		}

		public static function line( string $message = '' ): void {
			self::$output[] = array( 'line', $message );
		}

		public static function log( string $message ): void {
			self::$output[] = array( 'log', $message );
		}

		public static function success( string $message ): void {
			self::$output[] = array( 'success', $message );
		}

		public static function warning( string $message ): void {
			self::$output[] = array( 'warning', $message );
		}

		public static function error( $message, $exit = true ): void {
			self::$output[] = array( 'error', (string) $message );
			if ( $exit ) {
				throw new WP_CLI_Stub_Exit( (string) $message, 1 );
			}
		}

		public static function halt( int $code ): void {
			throw new WP_CLI_Stub_Exit( 'halt', $code );
		}

		public static function add_command( string $name, $callable ): bool {
			self::$commands[ $name ] = $callable;
			return true;
		}

		public static function add_hook( string $when, callable $callback ): void {
			self::$hooks[ $when ][] = $callback;
		}

		public static function get_runner() {
			return self::$runner ?? (object) array(
				'arguments'  => array(),
				'assoc_args' => array(),
			);
		}

		/**
		 * Messages of one type.
		 *
		 * @param string $type Type.
		 * @return string[]
		 */
		public static function messages( string $type ): array {
			$out = array();
			foreach ( self::$output as $entry ) {
				if ( $type === $entry[0] ) {
					$out[] = $entry[1];
				}
			}
			return $out;
		}
	}
}

namespace WP_CLI\Utils {

	function format_items( $format, $items, $fields ): void {
		\WP_CLI::$formatted[] = array(
			'format' => (string) $format,
			'items'  => (array) $items,
			'fields' => is_string( $fields ) ? explode( ',', $fields ) : (array) $fields,
		);
	}
}
