<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Migration;

use DeepWebSolutions\Framework\Bootstrap\Requirements;
use DeepWebSolutions\Framework\Core\Clock\SystemClock;
use DeepWebSolutions\Framework\Core\ComponentInterface;
use DeepWebSolutions\Framework\Core\Logging\GuardedLogger;
use DeepWebSolutions\Framework\Core\PluginBoot;
use DeepWebSolutions\Framework\Core\Scheduling\ScheduledHook;
use DeepWebSolutions\Framework\Core\Scheduling\SchedulerInterface;
use DeepWebSolutions\Framework\Core\Storage\Lock;
use DeepWebSolutions\Framework\Core\Storage\LockClaim;
use DeepWebSolutions\Framework\Core\Storage\OptionRows;
use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use DeepWebSolutions\Framework\Shared\Exception\RuntimeException;
use Psr\Clock\ClockInterface as PsrClockInterface;
use Psr\Log\LoggerInterface as PsrLoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Brings a plugin's stored data to the revision its code expects, running the pending migration steps in order under a database lock.
 *
 * Plain steps run on wp_loaded of any request, while batched steps run only from the runner's own scheduled hook, so no visitor waits for bulk work.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @phpstan-type ErrorRow array{v: 1, revision: int<0, max>|null, message: string, since: int, at: int}
 */
final class MigrationRunner implements ComponentInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * The seconds that a run's hold on the lock lasts after it is taken or refreshed, after which another run takes the lock over.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     int
	 */
	protected const int LOCK_TTL = 600;

	/**
	 * The clock that the lock and the batch budget read.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     PsrClockInterface
	 */
	protected PsrClockInterface $clock;

	/**
	 * The lock that keeps every other run out while one runs the steps.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     Lock
	 */
	protected Lock $lock;

	/**
	 * The token that names this runner's hold on the lock.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     string
	 */
	protected string $owner;

	/**
	 * The time at which this request's budget ends, set when its first step or batch begins and kept for every later run in the request.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     float|null
	 */
	protected ?float $deadline = null;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string                                                 $plugin_file      The path to the plugin's entry file.
	 * @param   string                                                 $option           The option that stores the data revision, and the prefix of every other option and hook the runner owns.
	 * @param   list<(callable(): void)|BatchedMigrationStepInterface> $steps            The migration steps, append-only, where index 0 brings the data to revision 1.
	 * @param   PsrLoggerInterface                                     $logger           The logger that records failures and warnings.
	 * @param   SchedulerInterface                                     $scheduler        The scheduler that runs the runner's hook in the background.
	 * @param   int                                                    $batch_time_limit Optional. The seconds, from 1, that a request spends on steps and batches before it pauses. Default is 20.
	 * @param   PsrClockInterface|null                                 $clock            Optional. The clock. Default is the system clock.
	 *
	 * @throws  InvalidArgumentException Thrown when the option name cannot name the lock.
	 */
	public function __construct(
		protected string $plugin_file,
		protected string $option,
		protected array $steps,
		protected PsrLoggerInterface $logger,
		protected SchedulerInterface $scheduler,
		protected int $batch_time_limit = 20,
		?PsrClockInterface $clock = null
	) {
		$this->clock  = $clock ?? new SystemClock();
		$this->lock   = new Lock( "{$option}_lock", self::LOCK_TTL, $this->clock );
		$this->owner  = wp_generate_uuid4();
		$this->logger = new GuardedLogger( $logger, $option );
	}

	// endregion

	// region GETTERS

	/**
	 * Returns the stored data revision, where a missing or malformed value reads as 0, which run() re-checks under the lock.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $option The option that stores the data revision.
	 *
	 * @return  int<0, max>
	 */
	public static function get_revision( string $option ): int {
		return \max( 0, \filter_var( get_option( $option, 0 ), \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE ) ?? 0 );
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
		add_action( 'wp_loaded', array( $this, 'run' ) );
		add_action( $this->get_hook(), array( $this, 'run' ) );
		add_action( 'all_admin_notices', array( $this, 'render_notice' ) );
		add_action( "admin_post_{$this->get_hook()}_now", array( $this, 'run_now' ) );
	}

	// endregion

	// region METHODS

	/**
	 * Runs the pending steps under the lock, pausing at a batched step outside the runner's hook or once the request's budget is spent.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  \Throwable Thrown when a step fails in a local or development environment, after its error was recorded.
	 *
	 * @return  void
	 */
	public function run(): void {
		$target = \count( $this->steps );
		$cached = self::get_revision( $this->option );
		if ( $cached === $target ) {
			return;
		}

		// Outside the hook a pending batched step only schedules the hook, so no lock is taken; a stale revision only leaves plain steps to the hook, which runs them too.
		if ( ! doing_action( $this->get_hook() ) && ( $this->steps[ $cached ] ?? null ) instanceof BatchedMigrationStepInterface ) {
			$this->schedule_continuation();
			return;
		}

		try {
			$claim = $this->lock->acquire( $this->owner );
		} catch ( RuntimeException $exception ) {
			$this->logger->warning(
				'Could not take the data migration lock: {error}',
				array(
					'error'     => $exception->getMessage(),
					'exception' => $exception,
				)
			);
			return;
		}

		if ( LockClaim::Busy === $claim ) {
			return;
		}

		$revision = null;
		try {
			try {
				$row = OptionRows::get( $this->option );
			} catch ( RuntimeException $exception ) {
				throw new RuntimeException( "Could not read the stored data revision: {$exception->getMessage()}", 0, $exception ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
			}

			// A malformed revision stops the run, because reading it as 0 would run every step again.
			$stored = \is_null( $row ) ? 0 : \filter_var( $row->raw, \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE );
			if ( \is_null( $stored ) || $stored < 0 ) {
				throw new RuntimeException( "The stored data revision '$row->raw' is not a non-negative integer." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
			}

			$revision = $stored;
			if ( $revision > $target ) {
				$this->checkpoint( $target ); // A re-upgrade then re-runs the newer, convergent steps over the rollback-era data.
				$this->logger->warning(
					'The stored data revision {stored} is above the code\'s revision {target}, so it was written back.',
					array(
						'stored' => $revision,
						'target' => $target,
					)
				);
				$revision = $target;
			} elseif ( LockClaim::TakenOver === $claim && \is_null( $this->get_error() ) ) {
				$this->record_error( 'Step ' . ( $revision + 1 ) . ' did not finish within 10 minutes.', $revision );
				$this->logger->warning( 'Data migration step {step} did not finish within 10 minutes, so it runs again.', array( 'step' => $revision + 1 ) );
			}

			if ( \is_null( $row ) ) {
				add_option(
					"{$this->option}_first_run",
					array(
						'v'  => 1,
						'at' => $this->clock->now()->getTimestamp(),
					),
					'',
					false
				);
			}

			while ( $revision < $target ) {
				if ( ! $this->holds_lock( $revision ) ) { // The previous checkpoint fired option hooks, so another run may have taken the lock over since the last check.
					return;
				}

				$step         = $this->steps[ $revision ];
				$memory_limit = wp_convert_hr_to_bytes( (string) \ini_get( 'memory_limit' ) );
				$spent        = ! \is_null( $this->deadline ) && ( $this->get_time() >= $this->deadline || ( 0 < $memory_limit && \memory_get_usage( true ) > 0.9 * $memory_limit ) );
				if ( $spent || ( $step instanceof BatchedMigrationStepInterface && ! doing_action( $this->get_hook() ) ) ) {
					$this->unlock(); // The lock goes first, so that the continuation can take it.
					$this->schedule_continuation();
					return;
				}

				$this->deadline ??= $this->get_time() + $this->batch_time_limit;

				$finished = true;
				if ( $step instanceof BatchedMigrationStepInterface ) {
					$finished = ! $step->process_batch();
					wp_cache_flush_runtime();
				} else {
					$step();
				}

				if ( ! $this->holds_lock( $revision ) ) { // A run whose lock was taken over must leave the revision and the error row to the run that holds it now.
					return;
				}

				if ( $finished ) {
					$this->checkpoint( $revision + 1 );
					++$revision;
				}

				if ( false !== get_option( "{$this->option}_error" ) ) { // The step or batch succeeded, so a recorded failure is no longer current.
					delete_option( "{$this->option}_error" );
				}
			}
		} catch ( \Throwable $exception ) {
			if ( $this->holds_lock( $revision ?? 0 ) ) {
				$this->record_error( $exception->getMessage(), $revision );
			}

			$this->logger->error(
				'Data migration failed: {message}',
				array(
					'message'   => $exception->getMessage(),
					'exception' => $exception,
				)
			);

			// In production the lock stays until its hold expires, so a failing step runs again 10 minutes later; elsewhere the fixed step runs on the next request.
			if ( PluginBoot::is_development_environment() ) {
				$this->unlock();
				throw $exception;
			}

			return;
		}

		$this->unlock();
	}

	// endregion

	// region HOOKS

	/**
	 * Tells users who can activate plugins, while steps are pending, that the last run failed or that a batched step is running in the background.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  void
	 */
	public function render_notice(): void {
		$revision = self::get_revision( $this->option );
		if ( $revision >= \count( $this->steps ) ) {
			return;
		}

		$name  = get_plugin_data( $this->plugin_file, false )['Name'];
		$error = $this->get_error();
		if ( ! \is_null( $error ) ) {
			Requirements::print_admin_notice(
				\sprintf(
					/* translators: 1: The plugin's name. 2: The error message. */
					esc_html__( '%1$s could not update its data, so features that need the update stay off. Error: %2$s', 'wp-framework' ),
					esc_html( $name ),
					esc_html( $error['message'] )
				)
			);
			return;
		}

		if ( $this->steps[ $revision ] instanceof BatchedMigrationStepInterface ) {
			Requirements::print_admin_notice(
				\sprintf(
					/* translators: 1: The plugin's name. 2: The URL that runs the update now. */
					__( '%1$s is updating its data in the background. <a href="%2$s">Run the update now</a>.', 'wp-framework' ),
					esc_html( $name ),
					esc_url( wp_nonce_url( admin_url( "admin-post.php?action={$this->get_hook()}_now" ), "{$this->get_hook()}_now" ) )
				),
				array( 'type' => 'info' )
			);
		}
	}

	/**
	 * Runs the runner's scheduled hook once in this request for a user who can activate plugins, then returns to the referring page.
	 *
	 * It serves sites whose scheduler does not advance, such as sites with WP-Cron disabled and no system cron.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  void
	 */
	public function run_now(): void {
		check_admin_referer( "{$this->get_hook()}_now" );
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( '', '', 403 );
		}

		/**
		 * Fires the data migration runner's scheduled hook from its Run now link.
		 *
		 * @since   2.0.0
		 * @version 2.0.0
		 */
		do_action( $this->get_hook() );

		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	// endregion

	// region HELPERS

	/**
	 * Refreshes this run's hold on the lock and returns whether the run still holds it, logging a warning when another run took the lock over or the refresh failed.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int $revision The revision this run has reached.
	 *
	 * @return  bool
	 */
	protected function holds_lock( int $revision ): bool {
		try {
			if ( $this->lock->refresh( $this->owner ) ) {
				return true;
			}

			$this->logger->warning( 'Another run took over the data migration lock, so this run stopped at revision {revision}.', array( 'revision' => $revision ) );
		} catch ( RuntimeException $exception ) {
			$this->logger->warning(
				'Could not refresh the data migration lock, so this run stopped at revision {revision}: {error}',
				array(
					'revision'  => $revision,
					'error'     => $exception->getMessage(),
					'exception' => $exception,
				)
			);
		}

		return false;
	}

	/**
	 * Releases this run's hold on the lock, and leaves a lock that another run took over in place.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  void
	 */
	protected function unlock(): void {
		try {
			$this->lock->release( $this->owner );
		} catch ( RuntimeException $exception ) {
			$this->logger->warning(
				'Could not release the data migration lock, which expires in 10 minutes: {error}',
				array(
					'error'     => $exception->getMessage(),
					'exception' => $exception,
				)
			);
		}
	}

	/**
	 * Schedules the runner's next invocation, and logs a scheduler failure, which a later request retries.
	 *
	 * Inside its own hook the running invocation would satisfy enqueue(), so the continuation is a single invocation due a second later, and the queue run dispatching it ends instead of claiming it again at once.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  void
	 */
	protected function schedule_continuation(): void {
		$hook      = new ScheduledHook( $this->get_hook() );
		$scheduled = doing_action( $this->get_hook() ) ? $this->scheduler->schedule_single( $hook, \time() + 1 ) : $this->scheduler->enqueue( $hook );
		if ( true !== $scheduled ) {
			$this->logger->warning(
				'Could not schedule the next data migration run: {error}',
				array(
					'error'     => $scheduled->get_error_message(),
					'exception' => $scheduled->get_error_data(),
				)
			);
		}
	}

	/**
	 * Records a reached revision, and the highest revision ever reached.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int $revision The revision reached.
	 *
	 * @throws  RuntimeException Thrown when the revision is not written.
	 *
	 * @return  void
	 */
	protected function checkpoint( int $revision ): void {
		if ( ! update_option( $this->option, $revision, true ) ) {
			throw new RuntimeException( "Could not record schema revision '$revision'." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		if ( $revision > self::get_revision( "{$this->option}_high_water" ) ) {
			update_option( "{$this->option}_high_water", $revision, false );
		}
	}

	/**
	 * Writes the error row, keeping the time of the first failure in a row of failures.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string   $message  The failure's message.
	 * @param   int|null $revision The revision whose step failed, or null for a failure before any step.
	 *
	 * @return  void
	 */
	protected function record_error( string $message, ?int $revision ): void {
		$now = $this->clock->now()->getTimestamp();
		update_option(
			"{$this->option}_error",
			array(
				'v'        => 1,
				'revision' => $revision,
				'message'  => $message,
				'since'    => $this->get_error()['since'] ?? $now,
				'at'       => $now,
			),
			false
		);
	}

	/**
	 * Returns the error row, or null when it is missing or is not a valid version 1 row.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  ErrorRow|null
	 */
	protected function get_error(): ?array {
		$row = get_option( "{$this->option}_error", null );
		if ( ! \is_array( $row ) || 1 !== ( $row['v'] ?? null ) || ! \array_key_exists( 'revision', $row ) ) {
			return null;
		}

		$revision = $row['revision'];
		$message  = $row['message'] ?? null;
		$since    = $row['since'] ?? null;
		$at       = $row['at'] ?? null;
		if ( ! ( \is_null( $revision ) || ( \is_int( $revision ) && 0 <= $revision ) ) || ! \is_string( $message ) || ! \is_int( $since ) || ! \is_int( $at ) ) {
			return null;
		}

		return array(
			'v'        => 1,
			'revision' => $revision,
			'message'  => $message,
			'since'    => $since,
			'at'       => $at,
		);
	}

	/**
	 * Returns the clock's time in seconds, with microseconds.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  float
	 */
	protected function get_time(): float {
		return (float) $this->clock->now()->format( 'U.u' );
	}

	/**
	 * Returns the runner's scheduled hook.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  non-empty-string
	 */
	protected function get_hook(): string {
		return "{$this->option}_run";
	}

	// endregion
}
