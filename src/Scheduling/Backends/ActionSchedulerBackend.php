<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Scheduling\Backends;

use DeepWebSolutions\Framework\Scheduling\Recurrence;
use DeepWebSolutions\Framework\Scheduling\ScheduledHook;

\defined( 'ABSPATH' ) || exit;

/**
 * Schedules hooks on Action Scheduler, in the plugin's own group, when a supported version has initialized its store.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @internal
 *
 * @phpstan-import-type Census from BackendInterface
 */
final class ActionSchedulerBackend implements BackendInterface {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $group           The Action Scheduler group that every write and every identity query uses.
	 * @param   string $minimum_version Optional. The lowest Action Scheduler version that takes writes. Default is 4.1.0.
	 */
	public function __construct(
		protected string $group,
		protected string $minimum_version = '4.1.0'
	) {}

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function get_state(): BackendState {
		if ( ! \function_exists( 'as_enqueue_async_action' ) ) {
			return BackendState::Absent;
		}

		if ( 0 === \did_action( 'action_scheduler_init' ) ) {
			return BackendState::Initializing;
		}

		$version = \ActionScheduler_Versions::instance()->latest_version();

		return \is_string( $version ) && \version_compare( $version, $this->minimum_version, '>=' ) ? BackendState::Supported : BackendState::Unsupported;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function census( ScheduledHook $hook ): array {
		$running = \as_get_scheduled_actions( $this->get_query( $hook, \ActionScheduler_Store::STATUS_RUNNING ), OBJECT );

		return array(
			'pending'           => \count( \as_get_scheduled_actions( $this->get_query( $hook, \ActionScheduler_Store::STATUS_PENDING ), 'ids' ) ),
			'running'           => \count( $running ),
			'running_recurring' => \count( \array_filter( $running, static fn ( mixed $action ): bool => $action instanceof \ActionScheduler_Action && $action->get_schedule()->is_recurring() ) ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function enqueue( ScheduledHook $hook, int $priority, bool $unique ): true|\WP_Error {
		return $this->confirm( \as_enqueue_async_action( $hook->hook, $hook->args, $this->group, $unique, $priority ), $hook, $unique );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function schedule_single( ScheduledHook $hook, int $timestamp, int $priority ): true|\WP_Error {
		return $this->confirm( \as_schedule_single_action( $timestamp, $hook->hook, $hook->args, $this->group, false, $priority ), $hook, false );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function schedule_recurring( ScheduledHook $hook, Recurrence $recurrence, int $priority ): true|\WP_Error {
		return $this->confirm( \as_schedule_recurring_action( \time(), $recurrence->seconds, $hook->hook, $hook->args, $this->group, true, $priority ), $hook, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function unschedule( ScheduledHook $hook ): true|\WP_Error {
		\as_unschedule_all_actions( $hook->hook, $hook->args, $this->group );

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function unschedule_all( string $hook ): true|\WP_Error {
		\as_unschedule_all_actions( $hook );

		return true;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the query for the hook's invocations in the given status.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ScheduledHook $hook   The hook to look for.
	 * @param   string        $status The Action Scheduler status.
	 *
	 * @return  array<string, mixed>
	 */
	protected function get_query( ScheduledHook $hook, string $status ): array {
		return array(
			'hook'     => $hook->hook,
			'args'     => $hook->args,
			'group'    => $this->group,
			'status'   => $status,
			'per_page' => -1,
			'orderby'  => 'none',
		);
	}

	/**
	 * Returns true for a stored action, or for a unique insert that a pending or running invocation of the hook refused, and an error otherwise.
	 *
	 * Action Scheduler returns 0 both for a refused duplicate and for a failed insert.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int           $action_id The ID that Action Scheduler returned.
	 * @param   ScheduledHook $hook      The hook that was inserted.
	 * @param   bool          $unique    Whether the insert was unique.
	 *
	 * @return  true|\WP_Error
	 */
	protected function confirm( int $action_id, ScheduledHook $hook, bool $unique ): true|\WP_Error {
		if ( 0 < $action_id || ( $unique && \as_has_scheduled_action( $hook->hook, $hook->args, $this->group ) ) ) {
			return true;
		}

		return new \WP_Error( 'scheduler_failed', "Action Scheduler did not store hook '$hook->hook'." );
	}

	// endregion
}
