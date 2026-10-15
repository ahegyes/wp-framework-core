<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Scheduling;

use DeepWebSolutions\Framework\ComponentInterface;
use DeepWebSolutions\Framework\Scheduling\Backends\ActionSchedulerBackend;
use DeepWebSolutions\Framework\Scheduling\Backends\BackendInterface;
use DeepWebSolutions\Framework\Scheduling\Backends\BackendState;
use DeepWebSolutions\Framework\Scheduling\Backends\WpCronBackend;
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

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string                 $group    The plugin's hook prefix, which names its Action Scheduler group and prefixes its WP-Cron schedules.
	 * @param   list<BackendInterface> $backends Optional. The backends in order of preference for writes. Default is Action Scheduler, then WP-Cron.
	 *
	 * @throws  InvalidArgumentException Thrown when the group is not a letter followed by up to 189 letters, digits, underscores and hyphens.
	 */
	public function __construct(
		string $group,
		array $backends = array()
	) {
		if ( 1 !== \preg_match( '/\A[a-z][a-z0-9_-]{0,189}\z/i', $group ) ) {
			throw new InvalidArgumentException( "Group '$group' is not a letter followed by up to 189 letters, digits, underscores and hyphens." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		$this->backends = array() === $backends ? array( new ActionSchedulerBackend( $group ), new WpCronBackend( $group ) ) : $backends;
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
		foreach ( $this->backends as $backend ) {
			if ( $backend instanceof ComponentInterface ) {
				$backend->register_hooks();
			}
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function enqueue( ScheduledHook $hook, int $priority = 10 ): true|\WP_Error {
		self::check_priority( $priority );

		return $this->write(
			__METHOD__,
			function () use ( $hook, $priority ): true|\WP_Error {
				// Inside the hook's own callback the running invocation is the one asking for a continuation, so it neither counts nor makes the insert unique.
				$outside = ! \doing_action( $hook->hook );
				$census  = $this->census( $hook );
				if ( 0 < $census['pending'] || ( $outside && 0 < $census['running'] ) ) {
					return true;
				}

				return $this->insert( static fn ( BackendInterface $backend ): true|\WP_Error => $backend->enqueue( $hook, $priority, $outside ) );
			}
		);
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
	public function schedule_at( ScheduledHook $hook, int $timestamp, int $priority = 10 ): true|\WP_Error {
		self::check_priority( $priority );
		if ( $timestamp < 1 ) {
			throw new InvalidArgumentException( "Timestamp '$timestamp' is not a positive Unix time." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		return $this->write(
			__METHOD__,
			fn (): true|\WP_Error => 0 < $this->census( $hook )['pending']
				? true
				: $this->insert( static fn ( BackendInterface $backend ): true|\WP_Error => $backend->schedule_single( $hook, $timestamp, $priority ) )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function ensure_recurring( ScheduledHook $hook, Recurrence $recurrence, int $priority = 10 ): true|\WP_Error {
		self::check_priority( $priority );

		return $this->write(
			__METHOD__,
			function () use ( $hook, $recurrence, $priority ): true|\WP_Error {
				$census = $this->census( $hook );
				if ( 1 === $census['pending_recurring'] || 0 < $census['running_recurring'] ) {
					return true;
				}

				$cleared = 0 < $census['pending_recurring'] ? $this->clear( $hook ) : true;

				return true === $cleared
					? $this->insert( static fn ( BackendInterface $backend ): true|\WP_Error => $backend->schedule_recurring( $hook, $recurrence, $priority ) )
					: $cleared;
			}
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function is_scheduled( ScheduledHook $hook ): bool {
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
	public function unschedule( ScheduledHook $hook ): true|\WP_Error {
		return $this->write( __METHOD__, fn (): true|\WP_Error => $this->clear( $hook ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function unschedule_all( string $hook ): true|\WP_Error {
		// Given no hook name, Action Scheduler would cancel the pending actions of every hook.
		$hook = ( new ScheduledHook( $hook ) )->hook;

		return $this->write(
			__METHOD__,
			function () use ( $hook ): true|\WP_Error {
				foreach ( $this->get_readable_backends() as $backend ) {
					$cleared = $backend->unschedule_all( $hook );
					if ( true !== $cleared ) {
						return $cleared;
					}
				}

				return true;
			}
		);
	}

	// endregion

	// region HELPERS

	/**
	 * Runs a write once init has fired and every loaded scheduler has initialized, outside a switched site, returning a throw as an error.
	 *
	 * Action Scheduler initializes its store at init priority 1, so routing an earlier write to WP-Cron would split one hook across both schedulers.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string                       $method    The public method that writes, for the developer notice.
	 * @param   \Closure(): (true|\WP_Error) $operation The write.
	 *
	 * @return  true|\WP_Error
	 */
	protected function write( string $method, \Closure $operation ): true|\WP_Error {
		if ( 0 === \did_action( 'init' ) || \array_any( $this->backends, static fn ( BackendInterface $backend ): bool => BackendState::Initializing === $backend->get_state() ) ) {
			$message = 'Scheduling writes wait until init has fired and Action Scheduler has initialized.';
			\_doing_it_wrong( \esc_html( $method ), \esc_html( $message ), '2.0.0' );

			return new \WP_Error( 'scheduler_not_ready', $message );
		}

		// Readiness is measured for the site that loaded the plugins, while a switched site may run other plugins or lack Action Scheduler's tables.
		if ( \is_multisite() && \ms_is_switched() ) {
			return new \WP_Error( 'scheduler_switched', 'Scheduling writes are not supported while switched to another site.' );
		}

		try {
			return $operation();
		} catch ( \Throwable $exception ) {
			return new \WP_Error( 'scheduler_failed', $exception->getMessage(), $exception );
		}
	}

	/**
	 * Runs an insert on the first backend that takes writes, or returns an error when none does.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   \Closure(BackendInterface): (true|\WP_Error) $insert The insert.
	 *
	 * @return  true|\WP_Error
	 */
	protected function insert( \Closure $insert ): true|\WP_Error {
		foreach ( $this->backends as $backend ) {
			if ( BackendState::Supported === $backend->get_state() ) {
				return $insert( $backend );
			}
		}

		return new \WP_Error( 'scheduler_unavailable', 'No scheduler takes writes.' );
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
			$cleared = $backend->unschedule( $hook );
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
			'pending_recurring' => 0,
			'running'           => 0,
			'running_recurring' => 0,
		);
		foreach ( $this->get_readable_backends() as $backend ) {
			$counted                      = $backend->census( $hook );
			$census['pending']           += $counted['pending'];
			$census['pending_recurring'] += $counted['pending_recurring'];
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

	/**
	 * Throws when a priority falls outside the range that Action Scheduler stores.
	 *
	 * Action Scheduler would clamp it silently.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int $priority The priority to check.
	 *
	 * @throws  InvalidArgumentException Thrown when the priority is outside 0 to 255.
	 */
	protected static function check_priority( int $priority ): void {
		if ( $priority < 0 || $priority > 255 ) {
			throw new InvalidArgumentException( "Priority '$priority' is outside 0 to 255." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}
	}

	// endregion
}
