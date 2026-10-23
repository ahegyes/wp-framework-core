<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Scheduling\Backends;

use DeepWebSolutions\Framework\Core\Scheduling\Recurrence;
use DeepWebSolutions\Framework\Core\Scheduling\ScheduledHook;

\defined( 'ABSPATH' ) || exit;

/**
 * One scheduler that the framework's Scheduler writes to, reads from and clears, implemented only by the framework and replaced only in tests.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @internal
 *
 * @phpstan-type Census array{pending: int<0, max>, pending_intervals: list<int<0, max>>, running: int<0, max>, running_recurring: int<0, max>}
 */
interface BackendInterface {
	/**
	 * Returns the scheduler's readiness in the current request.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  BackendState
	 */
	public function get_state(): BackendState;

	/**
	 * Returns how many invocations of the hook are pending and running, the interval of each pending recurring occurrence, and how many running occurrences recur.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ScheduledHook $hook The hook to count.
	 *
	 * @return  Census
	 */
	public function census( ScheduledHook $hook ): array;

	/**
	 * Adds an invocation of the hook that runs as soon as possible.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ScheduledHook $hook     The hook to run.
	 * @param   int           $priority The priority from 0 to 255, lower first.
	 * @param   bool          $unique   Whether a pending or running invocation of the hook stops the insert.
	 *
	 * @return  true|\WP_Error
	 */
	public function enqueue( ScheduledHook $hook, int $priority, bool $unique ): true|\WP_Error;

	/**
	 * Adds an invocation of the hook at the given time.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ScheduledHook $hook      The hook to run.
	 * @param   int           $timestamp The Unix time to run at.
	 * @param   int           $priority  The priority from 0 to 255, lower first.
	 *
	 * @return  true|\WP_Error
	 */
	public function schedule_single( ScheduledHook $hook, int $timestamp, int $priority ): true|\WP_Error;

	/**
	 * Adds a chain of the hook whose first occurrence is due now.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ScheduledHook $hook       The hook to run.
	 * @param   Recurrence    $recurrence The interval of the chain.
	 * @param   int           $priority   The priority from 0 to 255, lower first.
	 *
	 * @return  true|\WP_Error
	 */
	public function schedule_recurring( ScheduledHook $hook, Recurrence $recurrence, int $priority ): true|\WP_Error;

	/**
	 * Cancels every pending invocation of the hook with its arguments.
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
	 * Cancels every pending invocation of the hook name, whatever its arguments.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $hook The hook name.
	 *
	 * @return  true|\WP_Error
	 */
	public function unschedule_hook( string $hook ): true|\WP_Error;
}
