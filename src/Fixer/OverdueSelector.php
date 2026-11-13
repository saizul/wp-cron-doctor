<?php
/**
 * Chooses overdue events to run.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Fixer;

use InvalidArgumentException;
use Saizul\CronDoctor\Cron\CronArray;
use Saizul\CronDoctor\Cron\Event;
use Saizul\CronDoctor\Support\HookPattern;

/**
 * Selects due events, oldest first, the same order wp-cron.php uses.
 */
final class OverdueSelector {

	/**
	 * Selects events.
	 *
	 * @param CronArray $cron    Parsed cron option.
	 * @param int       $now     Current time.
	 * @param int       $limit   Maximum events (0 = no limit).
	 * @param int       $grace   Only events at least this many seconds late.
	 * @param string[]  $only    Only these hooks or patterns (empty = all).
	 * @param string[]  $exclude Never these hooks or patterns.
	 * @return Event[]
	 * @throws InvalidArgumentException For a negative limit or grace.
	 */
	public function select( CronArray $cron, int $now, int $limit = 10, int $grace = 0, array $only = array(), array $exclude = array() ): array {
		if ( $limit < 0 ) {
			throw new InvalidArgumentException( 'The limit cannot be negative.' );
		}
		if ( $grace < 0 ) {
			throw new InvalidArgumentException( 'The grace period cannot be negative.' );
		}

		$selected = array();
		foreach ( $cron->ready( $now ) as $event ) {
			if ( $event->lateness( $now ) < $grace ) {
				continue;
			}
			if ( array() !== $only && ! HookPattern::matches_any( $event->hook(), $only ) ) {
				continue;
			}
			if ( HookPattern::matches_any( $event->hook(), $exclude ) ) {
				continue;
			}
			$selected[] = $event;
			if ( $limit > 0 && count( $selected ) >= $limit ) {
				break;
			}
		}

		return $selected;
	}
}
