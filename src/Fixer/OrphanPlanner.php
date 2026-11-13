<?php
/**
 * Finds events whose hook has no callback.
 *
 * @package Saizul\CronDoctor
 */

namespace Saizul\CronDoctor\Fixer;

use Saizul\CronDoctor\Cron\Event;
use Saizul\CronDoctor\Snapshot;
use Saizul\CronDoctor\Support\HookPattern;

/**
 * An event is orphaned when nothing is hooked to its hook name, typically because the
 * plugin that scheduled it was deactivated or deleted without clearing its events.
 * WP-Cron still loads and reschedules such events forever; running them does nothing.
 */
final class OrphanPlanner {

	/**
	 * Orphaned hook names with their events.
	 *
	 * @param Snapshot $snapshot Collected data (uses callbacks and ignored_hooks).
	 * @param string[] $only     Only consider these hooks or patterns (empty = all).
	 * @return array<string, Event[]>
	 */
	public function orphans( Snapshot $snapshot, array $only = array() ): array {
		$orphans = array();

		foreach ( $snapshot->cron->events() as $event ) {
			$hook = $event->hook();
			if ( $snapshot->has_callback( $hook ) ) {
				continue;
			}
			if ( HookPattern::matches_any( $hook, $snapshot->ignored_hooks ) ) {
				continue;
			}
			if ( array() !== $only && ! HookPattern::matches_any( $hook, $only ) ) {
				continue;
			}
			$orphans[ $hook ][] = $event;
		}

		ksort( $orphans, SORT_STRING );

		return $orphans;
	}

	/**
	 * Events to remove.
	 *
	 * @param Snapshot $snapshot Collected data.
	 * @param string[] $only     Only these hooks or patterns.
	 * @return Event[]
	 */
	public function plan( Snapshot $snapshot, array $only = array() ): array {
		$events = array();
		foreach ( $this->orphans( $snapshot, $only ) as $hook_events ) {
			foreach ( $hook_events as $event ) {
				$events[] = $event;
			}
		}
		return $events;
	}
}
