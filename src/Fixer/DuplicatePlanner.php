<?php
/**
 * Finds duplicate events and decides which copy to keep.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Fixer;

use Saizul\CronDoctor\Cron\CronArray;
use Saizul\CronDoctor\Cron\Event;
use Saizul\CronDoctor\Support\HookPattern;

/**
 * Groups events by hook and arguments.
 *
 * WordPress identifies an event by its timestamp, hook and md5( serialize( $args ) ), so
 * two events with the same hook and arguments can only exist at different timestamps.
 * That happens when a plugin calls wp_schedule_event() without checking
 * wp_next_scheduled() first, or with arguments that differ only between requests.
 *
 * The copy that runs first is kept; for recurring events that is the copy that would
 * also be rescheduled first.
 */
final class DuplicatePlanner {

	/**
	 * Groups with more than one copy.
	 *
	 * @param CronArray $cron    Parsed cron option.
	 * @param string[]  $hooks   Only these hooks or `*` patterns (empty = all).
	 * @param string[]  $exclude Skip these hooks or `*` patterns.
	 * @return array<int, array{keep: Event, remove: Event[]}>
	 */
	public function groups( CronArray $cron, array $hooks = array(), array $exclude = array() ): array {
		$by_signature = array();

		foreach ( $cron->events() as $event ) {
			if ( array() !== $hooks && ! HookPattern::matches_any( $event->hook(), $hooks ) ) {
				continue;
			}
			if ( array() !== $exclude && HookPattern::matches_any( $event->hook(), $exclude ) ) {
				continue;
			}
			$by_signature[ $event->signature() ][] = $event;
		}

		$groups = array();
		foreach ( $by_signature as $events ) {
			if ( count( $events ) < 2 ) {
				continue;
			}
			// Events come sorted by timestamp, so the first one runs first.
			$keep     = array_shift( $events );
			$groups[] = array(
				'keep'   => $keep,
				'remove' => $events,
			);
		}

		usort(
			$groups,
			static function ( array $a, array $b ): int {
				return array( count( $b['remove'] ), $a['keep']->hook() ) <=> array( count( $a['remove'] ), $b['keep']->hook() );
			}
		);

		return $groups;
	}

	/**
	 * Events to remove, oldest first.
	 *
	 * @param CronArray $cron    Parsed cron option.
	 * @param string[]  $hooks   Only these hooks or `*` patterns (empty = all).
	 * @param string[]  $exclude Skip these hooks or `*` patterns.
	 * @return Event[]
	 */
	public function plan( CronArray $cron, array $hooks = array(), array $exclude = array() ): array {
		$remove = array();
		foreach ( $this->groups( $cron, $hooks, $exclude ) as $group ) {
			foreach ( $group['remove'] as $event ) {
				$remove[] = $event;
			}
		}

		usort(
			$remove,
			static function ( Event $a, Event $b ): int {
				return array( $a->timestamp(), $a->hook() ) <=> array( $b->timestamp(), $b->hook() );
			}
		);

		return $remove;
	}
}
