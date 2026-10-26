<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Scheduling;

use DeepWebSolutions\Framework\Core\ComponentInterface;
use DeepWebSolutions\Framework\Core\Scheduling\Backends\ActionSchedulerBackend;
use DeepWebSolutions\Framework\Core\Scheduling\Backends\BackendInterface;
use DeepWebSolutions\Framework\Core\Scheduling\Backends\BackendState;
use DeepWebSolutions\Framework\Core\Scheduling\Backends\WpCronBackend;
use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;

\defined( 'ABSPATH' ) || exit;

/**
 * Writes to the first backend that supports writes, Action Scheduler before WP-Cron, and reads and clears every backend whose store is ready.
 *
 * Its hooks must be registered on every request, so that WP-Cron finds the schedules of the recurring events it runs.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @phpstan-import-type Census from BackendInterface
 */
final class Scheduler implements SchedulerInterface, ComponentInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * The backends, in order of preference for writes.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     list<BackendInterface>
	 */
	protected array $backends;

	/**
	 * The WP-Cron backend, which takes every write that Action Scheduler cannot.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     WpCronBackend
	 */
	protected WpCronBackend $wp_cron;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $group The plugin's hook prefix, which names its Action Scheduler group and prefixes its WP-Cron schedules.
	 *
	 * @throws  InvalidArgumentException Thrown when the group is invalid.
	 */
	public function __construct( string $group ) {
		if ( 1 !== \preg_match( '/\A[a-z][a-z0-9_-]{0,189}\z/i', $group ) ) {
			throw new InvalidArgumentException( "Group '$group' is not a letter followed by up to 189 letters, digits, underscores and hyphens." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		$this->wp_cron  = new WpCronBackend( $group );
		$this->backends = array( new ActionSchedulerBackend( $group ), $this->wp_cron );
	}

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function register_hooks(): void {
		$this->wp_cron->register_schedules();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function enqueue( ScheduledHook $hook, int $priority = 10 ): true|\WP_Error {
		$ready = $this->check_ready( __METHOD__ );
		if ( true !== $ready ) {
			return $ready;
		}

		try {
			$census = $this->census( $hook );
			if ( 0 < $census['pending'] + $census['running'] ) {
				return true;
			}

			return $this->get_writable_backend()->enqueue( $hook, $priority, true );
		} catch ( \Throwable $exception ) {
			return self::get_failure( $exception );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  InvalidArgumentException Thrown when the timestamp is below 1.
	 */
	#[\Override]
	public function schedule_single( ScheduledHook $hook, int $timestamp, int $priority = 10 ): true|\WP_Error {
		if ( $timestamp < 1 ) {
			throw new InvalidArgumentException( "Timestamp '$timestamp' is not a positive Unix time." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		$ready = $this->check_ready( __METHOD__ );
		if ( true !== $ready ) {
			return $ready;
		}

		try {
			if ( 0 < $this->census( $hook )['pending'] ) {
				return true;
			}

			return $this->get_writable_backend()->schedule_single( $hook, $timestamp, $priority );
		} catch ( \Throwable $exception ) {
			return self::get_failure( $exception );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function ensure_recurring( ScheduledHook $hook, Recurrence $recurrence, int $priority = 10 ): true|\WP_Error {
		$ready = $this->check_ready( __METHOD__ );
		if ( true !== $ready ) {
			return $ready;
		}

		try {
			$census = $this->census( $hook );
			if ( 0 < $census['running_recurring'] || array( $recurrence->interval ) === $census['pending_intervals'] ) {
				return true;
			}

			$cleared = array() === $census['pending_intervals'] ? true : $this->clear( $hook );
			if ( true !== $cleared ) {
				return $cleared;
			}

			return $this->get_writable_backend()->schedule_recurring( $hook, $recurrence, $priority );
		} catch ( \Throwable $exception ) {
			return self::get_failure( $exception );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function is_scheduled( ScheduledHook $hook ): bool {
		if ( true !== $this->check_ready( __METHOD__ ) ) {
			return false;
		}

		$census = $this->census( $hook );
		return 0 < $census['pending'] + $census['running'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function clear_scheduled_hook( ScheduledHook $hook ): true|\WP_Error {
		$ready = $this->check_ready( __METHOD__ );
		if ( true !== $ready ) {
			return $ready;
		}

		try {
			return $this->clear( $hook );
		} catch ( \Throwable $exception ) {
			return self::get_failure( $exception );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function unschedule_hook( string $hook ): true|\WP_Error {
		$hook  = ( new ScheduledHook( $hook ) )->hook; // Given no hook name, Action Scheduler would cancel the pending actions of every hook.
		$ready = $this->check_ready( __METHOD__ );
		if ( true !== $ready ) {
			return $ready;
		}

		try {
			foreach ( $this->get_readable_backends() as $backend ) {
				$cleared = $backend->unschedule_hook( $hook );
				if ( true !== $cleared ) {
					return $cleared;
				}
			}

			return true;
		} catch ( \Throwable $exception ) {
			return self::get_failure( $exception );
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Returns true when scheduling is ready, and otherwise an error with a developer notice.
	 *
	 * Action Scheduler initializes its store at init priority 1, so routing an earlier call to WP-Cron would split one hook across both schedulers.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $method The public method called, for the developer notice.
	 *
	 * @return  true|\WP_Error
	 */
	protected function check_ready( string $method ): true|\WP_Error {
		$error = match ( true ) {
			0 === did_action( 'init' ) || \array_any( $this->backends, static fn ( BackendInterface $backend ): bool => BackendState::Initializing === $backend->get_state() )
				=> new \WP_Error( 'scheduler_not_ready', 'Scheduling waits until init has fired and Action Scheduler has initialized.' ),
			// Readiness is measured for the site that loaded the plugins, while a switched site may run other plugins or lack Action Scheduler's tables.
			is_multisite() && ms_is_switched()
				=> new \WP_Error( 'scheduler_switched', 'Scheduling is not supported while switched to another site.' ),
			default => true,
		};

		if ( true !== $error ) {
			_doing_it_wrong( esc_html( $method ), esc_html( $error->get_error_message() ), '2.0.0' );
		}

		return $error;
	}

	/**
	 * Returns the first backend that takes writes, which is WP-Cron when Action Scheduler does not.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  BackendInterface
	 */
	protected function get_writable_backend(): BackendInterface {
		return \array_find( $this->backends, static fn ( BackendInterface $backend ): bool => BackendState::Supported === $backend->get_state() ) ?? $this->wp_cron;
	}

	/**
	 * Returns the error that a write returns in place of a throw.
	 *
	 * A throw comes from code the scheduler does not own, such as another plugin's filter callback, and writes report every failure as an error.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   \Throwable $exception The throw.
	 *
	 * @return  \WP_Error
	 */
	protected static function get_failure( \Throwable $exception ): \WP_Error {
		return new \WP_Error( 'scheduler_failed', $exception->getMessage(), $exception );
	}

	/**
	 * Cancels every pending invocation of the hook on every readable backend, then checks that none is left.
	 *
	 * Action Scheduler reports no failed cancellation, so only the check catches one.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ScheduledHook $hook The hook to cancel.
	 *
	 * @return  true|\WP_Error
	 */
	protected function clear( ScheduledHook $hook ): true|\WP_Error {
		foreach ( $this->get_readable_backends() as $backend ) {
			$cleared = $backend->clear_scheduled_hook( $hook );
			if ( true !== $cleared ) {
				return $cleared;
			}
		}

		$census = $this->census( $hook );
		return match ( true ) {
			0 < $census['running_recurring'] => new \WP_Error( 'scheduler_running', "A recurring occurrence of hook '$hook->hook' is running and queues its successor when it completes." ),
			0 < $census['pending']           => new \WP_Error( 'scheduler_failed', "Hook '$hook->hook' still has pending invocations." ),
			default                          => true,
		};
	}

	/**
	 * Returns the hook's invocations summed over every readable backend.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ScheduledHook $hook The hook to count.
	 *
	 * @return  Census
	 */
	protected function census( ScheduledHook $hook ): array {
		$census = array(
			'pending'           => 0,
			'pending_intervals' => array(),
			'running'           => 0,
			'running_recurring' => 0,
		);
		foreach ( $this->get_readable_backends() as $backend ) {
			$counted                      = $backend->census( $hook );
			$census['pending']           += $counted['pending'];
			$census['pending_intervals']  = \array_merge( $census['pending_intervals'], $counted['pending_intervals'] );
			$census['running']           += $counted['running'];
			$census['running_recurring'] += $counted['running_recurring'];
		}

		return $census;
	}

	/**
	 * Returns the backends whose store can be read and cleared, supported or not.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  list<BackendInterface>
	 */
	protected function get_readable_backends(): array {
		return \array_values( \array_filter( $this->backends, static fn ( BackendInterface $backend ): bool => \in_array( $backend->get_state(), array( BackendState::Supported, BackendState::Unsupported ), true ) ) );
	}

	// endregion
}
