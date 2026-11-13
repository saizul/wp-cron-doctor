<?php
/**
 * Outcome of the loopback request test.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Loopback;

/**
 * Immutable result of a request from the site to its own wp-cron.php.
 */
final class LoopbackResult {

	/**
	 * Requested URL.
	 *
	 * @var string
	 */
	private string $url;

	/**
	 * HTTP status code, null when no response was received.
	 *
	 * @var int|null
	 */
	private ?int $status;

	/**
	 * Transport error message.
	 *
	 * @var string
	 */
	private string $error;

	/**
	 * Request duration in seconds.
	 *
	 * @var float
	 */
	private float $duration;

	/**
	 * Redirect target, if any.
	 *
	 * @var string
	 */
	private string $location;

	/**
	 * Reason the test was skipped, empty when it ran.
	 *
	 * @var string
	 */
	private string $skipped;

	/**
	 * Use the named constructors.
	 *
	 * @param string   $url      URL.
	 * @param int|null $status   Status code.
	 * @param string   $error    Error message.
	 * @param float    $duration Seconds.
	 * @param string   $location Redirect target.
	 * @param string   $skipped  Skip reason.
	 */
	private function __construct( string $url, ?int $status, string $error, float $duration, string $location, string $skipped ) {
		$this->url      = $url;
		$this->status   = $status;
		$this->error    = $error;
		$this->duration = max( 0.0, $duration );
		$this->location = $location;
		$this->skipped  = $skipped;
	}

	/**
	 * A response was received.
	 *
	 * @param string $url      URL.
	 * @param int    $status   Status code.
	 * @param float  $duration Seconds.
	 * @param string $location Location header for redirects.
	 * @return self
	 */
	public static function response( string $url, int $status, float $duration, string $location = '' ): self {
		return new self( $url, $status, '', $duration, $location, '' );
	}

	/**
	 * The request failed (DNS, connection refused, timeout, TLS...).
	 *
	 * @param string $url      URL.
	 * @param string $error    Error message.
	 * @param float  $duration Seconds.
	 * @return self
	 */
	public static function failure( string $url, string $error, float $duration ): self {
		return new self( $url, null, '' === $error ? 'Unknown error' : $error, $duration, '', '' );
	}

	/**
	 * The test did not run.
	 *
	 * @param string $reason Why.
	 * @return self
	 */
	public static function skipped( string $reason ): self {
		return new self( '', null, '', 0.0, '', $reason );
	}

	/**
	 * URL.
	 *
	 * @return string
	 */
	public function url(): string {
		return $this->url;
	}

	/**
	 * Status code.
	 *
	 * @return int|null
	 */
	public function status(): ?int {
		return $this->status;
	}

	/**
	 * Error message.
	 *
	 * @return string
	 */
	public function error(): string {
		return $this->error;
	}

	/**
	 * Duration in seconds.
	 *
	 * @return float
	 */
	public function duration(): float {
		return $this->duration;
	}

	/**
	 * Redirect target.
	 *
	 * @return string
	 */
	public function location(): string {
		return $this->location;
	}

	/**
	 * Skip reason.
	 *
	 * @return string
	 */
	public function skip_reason(): string {
		return $this->skipped;
	}

	/**
	 * Whether the test was skipped.
	 *
	 * @return bool
	 */
	public function is_skipped(): bool {
		return '' !== $this->skipped;
	}

	/**
	 * Whether the request failed before a response arrived.
	 *
	 * @return bool
	 */
	public function is_failure(): bool {
		return ! $this->is_skipped() && null === $this->status;
	}

	/**
	 * Whether the error looks like a timeout.
	 *
	 * @return bool
	 */
	public function is_timeout(): bool {
		return $this->is_failure() && 1 === preg_match( '/time(d)?\s*out|timeout|operation timed/i', $this->error );
	}
}
