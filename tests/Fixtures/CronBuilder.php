<?php
/**
 * Builds raw `cron` option arrays for tests.
 *
 * @package Saizul\CronDoctor
 */

declare(strict_types=1);

namespace Saizul\CronDoctor\Tests\Fixtures;

/**
 * Fluent builder producing the exact structure WordPress stores.
 */
final class CronBuilder {

	/** @var array<int|string, mixed> */
	private array $cron = array();

	/**
	 * New builder.
	 *
	 * @return self
	 */
	public static function create(): self {
		return new self();
	}

	/**
	 * Adds a single event.
	 *
	 * @param int          $timestamp Time.
	 * @param string       $hook      Hook.
	 * @param array<mixed> $args      Arguments.
	 * @return self
	 */
	public function single( int $timestamp, string $hook, array $args = array() ): self {
		$this->cron[ $timestamp ][ $hook ][ md5( serialize( $args ) ) ] = array(
			'schedule' => false,
			'args'     => $args,
		);
		return $this;
	}

	/**
	 * Adds a recurring event.
	 *
	 * @param int          $timestamp Time.
	 * @param string       $hook      Hook.
	 * @param string       $schedule  Recurrence.
	 * @param int          $interval  Interval.
	 * @param array<mixed> $args      Arguments.
	 * @return self
	 */
	public function recurring( int $timestamp, string $hook, string $schedule, int $interval, array $args = array() ): self {
		$this->cron[ $timestamp ][ $hook ][ md5( serialize( $args ) ) ] = array(
			'schedule' => $schedule,
			'args'     => $args,
			'interval' => $interval,
		);
		return $this;
	}

	/**
	 * Sets a raw entry.
	 *
	 * @param int|string $key   Top level key.
	 * @param mixed      $value Value.
	 * @return self
	 */
	public function raw( $key, $value ): self {
		$this->cron[ $key ] = $value;
		return $this;
	}

	/**
	 * The option value, with `version` => 2 unless $legacy.
	 *
	 * @param bool $legacy Leave out the version key.
	 * @return array<int|string, mixed>
	 */
	public function build( bool $legacy = false ): array {
		$cron = $this->cron;
		ksort( $cron );
		if ( ! $legacy ) {
			$cron['version'] = 2;
		}
		return $cron;
	}
}
