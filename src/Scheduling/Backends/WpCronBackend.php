<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Scheduling\Backends;

use DeepWebSolutions\Framework\Core\Scheduling\Recurrence;
use DeepWebSolutions\Framework\Core\Scheduling\ScheduledHook;

\defined( 'ABSPATH' ) || exit;

/**
 * Schedules hooks on WP-Cron, which is always ready and has no running state, because it removes a due event before running its hook.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @internal
 *
 * @phpstan-import-type Census from BackendInterface
 */
final class WpCronBackend implements BackendInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * The intervals scheduled in this request, keyed by schedule name, which the stored events may not hold yet.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     array<string, int>
	 */
	protected array $intervals = array();

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $group The prefix of the schedule names this backend adds.
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
		return BackendState::Supported;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function census( ScheduledHook $hook ): array {
		$key       = \md5( \serialize( $hook->args ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WP-Cron keys its events by their serialized arguments.
		$pending   = 0;
		$intervals = array();
		foreach ( $this->get_cron() as $hooks ) {
			$events = \is_array( $hooks ) ? ( $hooks[ $hook->hook ] ?? null ) : null;
			$event  = \is_array( $events ) ? ( $events[ $key ] ?? null ) : null;
			if ( ! \is_array( $event ) ) {
				continue;
			}

			++$pending;
			if ( false !== ( $event['schedule'] ?? false ) ) {
				$intervals[] = \max( 0, \filter_var( $event['interval'] ?? 0, \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE ) ?? 0 );
			}
		}

		return array(
			'pending'           => $pending,
			'pending_intervals' => $intervals,
			'running'           => 0,
			'running_recurring' => 0,
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
		return self::to_result( wp_schedule_single_event( \time(), $hook->hook, $hook->args, true ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function schedule_single( ScheduledHook $hook, int $timestamp, int $priority ): true|\WP_Error {
		return self::to_result( wp_schedule_single_event( $timestamp, $hook->hook, $hook->args, true ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function schedule_recurring( ScheduledHook $hook, Recurrence $recurrence, int $priority ): true|\WP_Error {
		$schedule = "{$this->group}_every_{$recurrence->interval}s";

		// WP-Cron rejects a schedule name that the cron_schedules filter does not return yet, so the interval is added before the write, whether or not this request registered the filter.
		$this->register_schedules();
		$this->intervals[ $schedule ] = $recurrence->interval;

		return self::to_result( wp_schedule_event( \time(), $schedule, $hook->hook, $hook->args, true ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function clear_scheduled_hook( ScheduledHook $hook ): true|\WP_Error {
		return self::to_result( wp_clear_scheduled_hook( $hook->hook, $hook->args, true ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function unschedule_hook( string $hook ): true|\WP_Error {
		return self::to_result( wp_unschedule_hook( $hook, true ) );
	}

	// endregion

	// region METHODS

	/**
	 * Adds this backend's schedules to WP-Cron's through the cron_schedules filter.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function register_schedules(): void {
		add_filter( 'cron_schedules', array( $this, 'add_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Recurrence sets the intervals, with a minimum of 60 seconds.
	}

	// endregion

	// region HOOKS

	/**
	 * Adds this backend's schedules, from the stored events and this request's writes, so that WP-Cron can validate and reschedule their events.
	 *
	 * Rebuilding them from the stored events keeps a recurring event alive in a request that never scheduled it, because WP-Cron drops an event whose schedule it cannot find.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   array<string, array{interval: int, display: string}> $schedules The registered schedules.
	 *
	 * @return  array<string, array{interval: int, display: string}>
	 */
	public function add_schedules( array $schedules ): array {
		$intervals = $this->intervals;
		$pattern   = '/\A' . \preg_quote( $this->group, '/' ) . '_every_([1-9]\d*)s\z/';
		foreach ( $this->get_cron() as $hooks ) {
			foreach ( \is_array( $hooks ) ? $hooks : array() as $events ) {
				foreach ( \is_array( $events ) ? $events : array() as $event ) {
					$schedule = \is_array( $event ) ? ( $event['schedule'] ?? null ) : null;
					if ( \is_string( $schedule ) && 1 === \preg_match( $pattern, $schedule, $matches ) ) {
						$intervals[ $schedule ] = (int) $matches[1];
					}
				}
			}
		}

		foreach ( $intervals as $schedule => $seconds ) {
			$schedules[ $schedule ] ??= array(
				'interval' => $seconds,
				'display'  => "$this->group: every $seconds seconds",
			);
		}

		return $schedules;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the stored events, keyed by timestamp, then hook name, then argument key.
	 *
	 * The option is read directly, because WordPress keeps _get_cron_array() private.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  array<mixed>
	 */
	protected function get_cron(): array {
		$cron = get_option( 'cron', array() );

		return \is_array( $cron ) ? $cron : array();
	}

	/**
	 * Returns a WP-Cron function's result as true or its error, where a duplicate event counts as success.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed $result The result of a WP-Cron function called with its WP_Error flag.
	 *
	 * @return  true|\WP_Error
	 */
	protected static function to_result( mixed $result ): true|\WP_Error {
		return is_wp_error( $result ) && 'duplicate_event' !== $result->get_error_code() ? $result : true;
	}

	// endregion
}
