<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Scheduling;

use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;

\defined( 'ABSPATH' ) || exit;

/**
 * Runs a plugin's hooks in the background, on Action Scheduler when it is available and on WP-Cron otherwise.
 *
 * Deduplication is best effort on both schedulers, so a hook's callbacks tolerate a duplicate or a missing invocation.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
interface SchedulerInterface {
	/**
	 * Ensures that an invocation of the hook is pending or running, adding one that runs as soon as possible when none is.
	 *
	 * Inside the hook's own callback only a pending invocation counts, so the callback can schedule its continuation.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ScheduledHook $hook     The hook to run.
	 * @param   int           $priority Optional. The priority from 0 to 255, lower first, which only Action Scheduler applies. Default is 10.
	 *
	 * @throws  InvalidArgumentException Thrown when the priority is outside 0 to 255.
	 *
	 * @return  true|\WP_Error
	 */
	public function enqueue( ScheduledHook $hook, int $priority = 10 ): true|\WP_Error;

	/**
	 * Ensures that an invocation of the hook is pending, adding one at the given time when none is, and never moves an existing one.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ScheduledHook $hook      The hook to run.
	 * @param   int           $timestamp The Unix time to run at, where a past time runs at the next dispatch.
	 * @param   int           $priority  Optional. The priority from 0 to 255, lower first, which only Action Scheduler applies. Default is 10.
	 *
	 * @throws  InvalidArgumentException Thrown when the timestamp is below 1 or the priority is outside 0 to 255.
	 *
	 * @return  true|\WP_Error
	 */
	public function schedule_at( ScheduledHook $hook, int $timestamp, int $priority = 10 ): true|\WP_Error;

	/**
	 * Ensures that the hook recurs, creating its chain when none exists and collapsing duplicate chains onto one, while an existing chain keeps its interval.
	 *
	 * A running occurrence counts as a chain and defers the collapse, because Action Scheduler queues its successor only after it completes.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ScheduledHook $hook       The hook to run.
	 * @param   Recurrence    $recurrence The interval of a new chain.
	 * @param   int           $priority   Optional. The priority from 0 to 255, lower first, which only Action Scheduler applies. Default is 10.
	 *
	 * @throws  InvalidArgumentException Thrown when the priority is outside 0 to 255.
	 *
	 * @return  true|\WP_Error
	 */
	public function ensure_recurring( ScheduledHook $hook, Recurrence $recurrence, int $priority = 10 ): true|\WP_Error;

	/**
	 * Returns whether an invocation of the hook is pending, or running on Action Scheduler.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ScheduledHook $hook The hook to look for.
	 *
	 * @return  bool
	 */
	public function is_scheduled( ScheduledHook $hook ): bool;

	/**
	 * Cancels every pending invocation of the hook.
	 *
	 * It returns a scheduler_running error while a recurring occurrence runs on Action Scheduler, because that occurrence queues its successor when it completes.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ScheduledHook $hook The hook to cancel.
	 *
	 * @return  true|\WP_Error
	 */
	public function unschedule( ScheduledHook $hook ): true|\WP_Error;

	/**
	 * Cancels every pending invocation of the hook name, whatever its arguments and Action Scheduler group.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $hook The hook name, 1 to 191 bytes without whitespace, other than '0'.
	 *
	 * @throws  InvalidArgumentException Thrown when the hook name is empty, '0', longer than 191 bytes or holds whitespace.
	 *
	 * @return  true|\WP_Error
	 */
	public function unschedule_all( string $hook ): true|\WP_Error;
}
