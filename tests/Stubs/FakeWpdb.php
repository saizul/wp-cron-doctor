<?php
/**
 * Scriptable stand-in for wpdb.
 *
 * @package Saizul\CronDoctor
 */

declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Stubs;

/**
 * Answers queries by matching SQL fragments; records every query.
 */
final class FakeWpdb {

	/** @var string */
	public $prefix = 'wp_';

	/** @var array<int, string> */
	public array $queries = array();

	/** @var array<string, mixed> SQL fragment => answer. */
	public array $answers = array();

	/**
	 * Very small prepare(): quotes %s, casts %d.
	 */
	public function prepare( string $sql, ...$args ): string {
		$index = 0;
		return (string) preg_replace_callback(
			'/%[sd]/',
			static function ( array $match ) use ( &$index, $args ) {
				$value = $args[ $index++ ] ?? '';
				return '%d' === $match[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$sql
		);
	}

	private function answer( string $sql ) {
		$this->queries[] = $sql;
		foreach ( $this->answers as $fragment => $answer ) {
			if ( false !== strpos( $sql, $fragment ) ) {
				return $answer;
			}
		}
		return null;
	}

	public function get_var( string $sql ) {
		return $this->answer( $sql );
	}

	public function get_row( string $sql, $output = 'OBJECT' ) {
		return $this->answer( $sql );
	}

	public function get_results( string $sql, $output = 'OBJECT' ) {
		return $this->answer( $sql );
	}
}
