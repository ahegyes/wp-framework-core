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
	 * On Action Scheduler the running invocation counts too, so a callback continues itself with schedule_single() instead.
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
	 * Only pending invocations count, so a callback can schedule its own continuation with it.
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
	public function schedule_single( ScheduledHook $hook, int $timestamp, int $priority = 10 ): true|\WP_Error;

	/**
	 * Ensures that the hook recurs at the given interval, creating its chain when none exists and replacing duplicate chains, or a chain at another interval, with one chain, which also cancels a pending one-off invocation of the identity.
	 *
	 * On Action Scheduler a running occurrence defers any replacement to a later call, because it queues its successor only after it completes; WP-Cron shows no running state, so it is called outside the hook's own callback.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ScheduledHook $hook       The hook to run.
	 * @param   Recurrence    $recurrence The interval of the chain.
	 * @param   int           $priority   Optional. The priority from 0 to 255, lower first, which only Action Scheduler applies. Default is 10.
	 *
	 * @throws  InvalidArgumentException Thrown when the priority is outside 0 to 255.
	 *
	 * @return  true|\WP_Error
	 */
	public function ensure_recurring( ScheduledHook $hook, Recurrence $recurrence, int $priority = 10 ): true|\WP_Error;

	/**
	 * Returns whether an invocation of the hook is pending, or running on Action Scheduler, and false with a developer notice before scheduling is ready or on a switched site.
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
	 * Cancels every pending invocation of the hook with its arguments.
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
	public function clear_scheduled_hook( ScheduledHook $hook ): true|\WP_Error;

	/**
	 * Cancels every pending invocation of the hook name, whatever its arguments and Action Scheduler group.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $hook The hook name, 1 to 191 bytes without whitespace, other than '0'.
	 *
	 * @throws  InvalidArgumentException Thrown when the hook name is invalid.
	 *
	 * @return  true|\WP_Error
	 */
	public function unschedule_hook( string $hook ): true|\WP_Error;
}
