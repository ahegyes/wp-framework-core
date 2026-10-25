<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Scheduling\Backends;

use DeepWebSolutions\Framework\Core\Scheduling\Recurrence;
use DeepWebSolutions\Framework\Core\Scheduling\ScheduledHook;
use DeepWebSolutions\Framework\Core\Storage\Database;

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
	// region FIELDS AND CONSTANTS

	/**
	 * The lowest Action Scheduler version that takes writes, the one that the WooCommerce floor bundles.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     string
	 */
	protected const string MINIMUM_VERSION = '4.1.0';

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $group The Action Scheduler group that every write and every identity query uses.
	 */
	public function __construct(
		protected string $group
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
		return \is_string( $version ) && \version_compare( $version, self::MINIMUM_VERSION, '>=' ) ? BackendState::Supported : BackendState::Unsupported;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function census( ScheduledHook $hook ): array {
		$pending = \as_get_scheduled_actions( $this->get_query( $hook, \ActionScheduler_Store::STATUS_PENDING ), OBJECT );
		$running = \as_get_scheduled_actions( $this->get_query( $hook, \ActionScheduler_Store::STATUS_RUNNING ), OBJECT );

		return array(
			'pending'           => \count( $pending ),
			'pending_intervals' => self::get_intervals( $pending ),
			'running'           => \count( $running ),
			'running_recurring' => \count( self::get_intervals( $running ) ),
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
		return $this->confirm( \as_schedule_recurring_action( \time(), $recurrence->interval, $hook->hook, $hook->args, $this->group, false, $priority ), $hook, false );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function clear_scheduled_hook( ScheduledHook $hook ): true|\WP_Error {
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
	public function unschedule_hook( string $hook ): true|\WP_Error {
		// Action Scheduler reports neither a failed lookup nor a failed cancel, so only the query error shows one.
		$wpdb = Database::get_wpdb();
		$wpdb->flush();

		\as_unschedule_all_actions( $hook );

		return '' === $wpdb->last_error ? true : new \WP_Error( 'scheduler_failed', "Action Scheduler did not unschedule hook '$hook': $wpdb->last_error" );
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
	 * Returns the interval of each action that belongs to a recurring chain, where a recurrence other than a fixed interval reads as 0.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   array<mixed> $actions The actions that Action Scheduler returned.
	 *
	 * @return  list<int<0, max>>
	 */
	protected static function get_intervals( array $actions ): array {
		$intervals = array();
		foreach ( $actions as $action ) {
			$schedule = $action instanceof \ActionScheduler_Action ? $action->get_schedule() : null;
			if ( ! \is_null( $schedule ) && $schedule->is_recurring() ) {
				$intervals[] = $schedule instanceof \ActionScheduler_IntervalSchedule ? \max( 0, \filter_var( $schedule->get_recurrence(), \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE ) ?? 0 ) : 0;
			}
		}

		return $intervals;
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
