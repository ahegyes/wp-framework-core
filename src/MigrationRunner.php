<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework;

use DeepWebSolutions\Framework\Scheduling\ScheduledHook;
use DeepWebSolutions\Framework\Scheduling\SchedulerInterface;
use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use DeepWebSolutions\Framework\Shared\Exception\LogicException;
use DeepWebSolutions\Framework\Shared\Exception\RuntimeException;
use DeepWebSolutions\Framework\Storage\OptionRow;
use DeepWebSolutions\Framework\Storage\OptionRows;
use DeepWebSolutions\Framework\Utilities\SystemClock;
use Psr\Clock\ClockInterface as PsrClockInterface;
use Psr\Log\LoggerInterface as PsrLoggerInterface;

use function DeepWebSolutions\Framework\Shared\interpolate_log_message;

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
	 * The age in seconds after which another run takes over a held lock.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     int
	 */
	protected const int LOCK_TTL = 600;

	/**
	 * The option rows that hold the lock and the stored revision, read past the options cache.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     OptionRows
	 */
	protected OptionRows $rows;

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
	 * The lock row as this run wrote it, or null without a lock.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     OptionRow|null
	 */
	protected ?OptionRow $lock = null;

	/**
	 * Whether this run took its lock over from a run that held it too long.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     bool
	 */
	protected bool $took_over = false;

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
	 * @param   int                                                    $batch_time_limit Optional. The seconds, from 1 to 300, that a request spends on steps and batches before it pauses. Default is 20.
	 * @param   \wpdb|null                                             $wpdb             Optional. The database connection. Default is the global one.
	 * @param   PsrClockInterface|null                                 $clock            Optional. The clock. Default is the system clock.
	 *
	 * @throws  InvalidArgumentException Thrown when the batch time limit is outside 1 to 300 seconds.
	 * @throws  LogicException           Thrown when no database connection is given and the global one is missing.
	 */
	public function __construct(
		protected string $plugin_file,
		protected string $option,
		protected array $steps,
		protected PsrLoggerInterface $logger,
		protected SchedulerInterface $scheduler,
		protected int $batch_time_limit = 20,
		?\wpdb $wpdb = null,
		?PsrClockInterface $clock = null
	) {
		// A budget well under the lock's lifetime keeps a live run, even one with an overlong last batch, from being taken over.
		if ( $batch_time_limit < 1 || $batch_time_limit > 300 ) {
			throw new InvalidArgumentException( "Batch time limit '$batch_time_limit' is outside 1 to 300 seconds." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		$this->rows  = new OptionRows( $wpdb );
		$this->clock = $clock ?? new SystemClock();
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
		return \max( 0, \filter_var( \get_option( $option, 0 ), \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE ) ?? 0 );
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
		\add_action( 'wp_loaded', array( $this, 'run' ) );
		\add_action( $this->get_hook(), array( $this, 'run' ) );
		\add_action( 'all_admin_notices', array( $this, 'render_notice' ) );
		\add_action( "admin_post_{$this->get_hook()}_now", array( $this, 'run_now' ) );
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
	 */
	public function run(): void {
		$target = \count( $this->steps );
		if ( self::get_revision( $this->option ) === $target || ! $this->lock() ) {
			return;
		}

		$revision = null;
		try {
			$stored_row = $this->read_revision();
			$revision   = $stored_row ?? 0;
			if ( $revision > $target ) {
				$this->checkpoint( $target ); // A re-upgrade then re-runs the newer, convergent steps over the rollback-era data.
				$this->log(
					'warning',
					'The stored data revision {stored} is above the code\'s revision {target}, so it was written back.',
					array(
						'stored' => $revision,
						'target' => $target,
					)
				);
				$revision = $target;
			} elseif ( $this->took_over && \is_null( $this->get_error() ) ) {
				$this->record_error( 'Step ' . ( $revision + 1 ) . ' did not finish within 10 minutes.', $revision );
				$this->log( 'warning', 'Data migration step {step} did not finish within 10 minutes, so it runs again.', array( 'step' => $revision + 1 ) );
			}

			if ( \is_null( $stored_row ) ) {
				\add_option(
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
				// The previous checkpoint fired option hooks, so another run may have taken the lock over since the last check.
				if ( ! $this->holds_lock( $revision ) ) {
					return;
				}

				$step = $this->steps[ $revision ];
				if ( $this->is_out_of_budget() || ( $step instanceof BatchedMigrationStepInterface && ! \doing_action( $this->get_hook() ) ) ) {
					$this->pause();
					return;
				}

				$this->deadline ??= $this->get_time() + $this->batch_time_limit;

				$finished = true;
				if ( $step instanceof BatchedMigrationStepInterface ) {
					$finished = ! $step->process_batch();
					\wp_cache_flush_runtime();
				} else {
					$step();
				}

				// A run whose lock was taken over must leave the revision and the error row to the run that holds it now.
				if ( ! $this->holds_lock( $revision ) ) {
					return;
				}

				if ( $finished ) {
					$this->checkpoint( $revision + 1 );
					++$revision;
				}
				$this->clear_error();
			}
		} catch ( \Throwable $exception ) {
			$this->fail( $exception, $revision );
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
	 */
	public function render_notice(): void {
		$revision = self::get_revision( $this->option );
		if ( $revision >= \count( $this->steps ) || ! \current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$name  = \get_plugin_data( $this->plugin_file, false )['Name'];
		$error = $this->get_error();
		if ( ! \is_null( $error ) ) {
			\wp_admin_notice(
				\sprintf(
					/* translators: 1: The plugin's name. 2: The error message. */
					\esc_html__( '%1$s could not update its data, so features that need the update stay off. Error: %2$s', 'wp-framework' ),
					\esc_html( $name ),
					\esc_html( $error['message'] )
				),
				array( 'type' => 'error' )
			);
			return;
		}

		if ( $this->steps[ $revision ] instanceof BatchedMigrationStepInterface ) {
			\wp_admin_notice(
				\sprintf(
					/* translators: 1: The plugin's name. 2: The URL that runs the update now. */
					\__( '%1$s is updating its data in the background. <a href="%2$s">Run the update now</a>.', 'wp-framework' ),
					\esc_html( $name ),
					\esc_url( \wp_nonce_url( \admin_url( "admin-post.php?action={$this->get_hook()}_now" ), "{$this->get_hook()}_now" ) )
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
	 */
	public function run_now(): void {
		\check_admin_referer( "{$this->get_hook()}_now" );
		if ( ! \current_user_can( 'activate_plugins' ) ) {
			\wp_die( '', '', 403 );
		}

		/**
		 * Fires the data migration runner's scheduled hook from its Run now link.
		 *
		 * @since   2.0.0
		 * @version 2.0.0
		 */
		\do_action( $this->get_hook() );

		\wp_safe_redirect( \wp_get_referer() ?: \admin_url() );
		exit;
	}

	// endregion

	// region HELPERS

	/**
	 * Takes the lock row, or takes it over once it is older than the lock's lifetime, and returns whether this run holds it.
	 *
	 * The lock bypasses the options cache, so that concurrent requests see each other's rows.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  bool
	 */
	protected function lock(): bool {
		$now             = $this->clock->now()->getTimestamp();
		$this->took_over = false;

		try {
			$this->lock = $this->rows->insert( $this->get_lock_name(), $now );
			if ( \is_null( $this->lock ) ) {
				// A lock row that holds no timestamp counts as expired, so a corrupt row never blocks the migrations.
				$held = $this->rows->get( $this->get_lock_name() );
				if ( ! \is_null( $held ) && ( \filter_var( $held->raw, \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE ) ?? 0 ) < $now - self::LOCK_TTL ) {
					$this->lock      = $this->rows->replace( $held, $now );
					$this->took_over = ! \is_null( $this->lock );
				}
			}
		} catch ( RuntimeException $exception ) {
			$this->lock = null;
			$this->log( 'warning', 'Could not take the data migration lock: {error}', array( 'error' => $exception->getMessage() ) );
		}

		return ! \is_null( $this->lock );
	}

	/**
	 * Returns whether the lock row still holds this run's timestamp, and logs a warning when another run took it over.
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
			$held = $this->rows->get( $this->get_lock_name() );
		} catch ( RuntimeException ) {
			$held = null;
		}

		if ( ! \is_null( $held ) && $held->raw === $this->lock?->raw ) {
			return true;
		}

		$this->log( 'warning', 'Another run took over the data migration lock, so this run stopped at revision {revision}.', array( 'revision' => $revision ) );

		return false;
	}

	/**
	 * Deletes the lock row only while it still holds this run's timestamp, so that a takeover's lock survives.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	protected function unlock(): void {
		try {
			if ( ! \is_null( $this->lock ) ) {
				$this->rows->delete( $this->lock );
			}
		} catch ( RuntimeException $exception ) {
			$this->log( 'warning', 'Could not release the data migration lock, which expires in 10 minutes: {error}', array( 'error' => $exception->getMessage() ) );
		}

		$this->lock = null;
	}

	/**
	 * Releases the lock, then schedules the runner's hook, so that the continuation can take the lock this run held.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	protected function pause(): void {
		$this->unlock();

		// Inside its own hook the running invocation would satisfy enqueue(), so the continuation is a single invocation due now.
		$hook      = new ScheduledHook( $this->get_hook() );
		$scheduled = \doing_action( $this->get_hook() ) ? $this->scheduler->schedule_single( $hook, \time() ) : $this->scheduler->enqueue( $hook );
		if ( true !== $scheduled ) {
			$this->log( 'warning', 'Could not schedule the next data migration run: {error}', array( 'error' => $scheduled->get_error_message() ) );
		}
	}

	/**
	 * Returns the stored revision read past the options cache, or null when no revision is stored.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  RuntimeException Thrown when the read fails or the stored value is not a non-negative integer, so that neither counts as 0.
	 *
	 * @return  int<0, max>|null
	 */
	protected function read_revision(): ?int {
		try {
			$row = $this->rows->get( $this->option );
		} catch ( RuntimeException $exception ) {
			throw new RuntimeException( "Could not read the stored data revision: {$exception->getMessage()}", 0, $exception ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		if ( \is_null( $row ) ) {
			return null;
		}

		$value    = $row->raw;
		$revision = \filter_var( $value, \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE );
		if ( \is_null( $revision ) || $revision < 0 ) {
			throw new RuntimeException( "The stored data revision '$value' is not a non-negative integer." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		return $revision;
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
	 */
	protected function checkpoint( int $revision ): void {
		if ( ! \update_option( $this->option, $revision, true ) ) {
			throw new RuntimeException( "Could not record schema revision '$revision'." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		if ( $revision > ( \filter_var( \get_option( "{$this->option}_high_water", 0 ), \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE ) ?? 0 ) ) {
			\update_option( "{$this->option}_high_water", $revision, false );
		}
	}

	/**
	 * Records and logs a failure, then releases the lock and rethrows in a local or development environment.
	 *
	 * In production the lock stays for its lifetime, so a failing step runs again every 10 minutes.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   \Throwable $exception The failure.
	 * @param   int|null   $revision  The revision whose step failed, or null for a failure before any step.
	 *
	 * @throws  \Throwable Thrown in a local or development environment.
	 */
	protected function fail( \Throwable $exception, ?int $revision ): void {
		if ( $this->holds_lock( $revision ?? 0 ) ) {
			$this->record_error( $exception->getMessage(), $revision );
		}

		$this->log(
			'error',
			'Data migration failed: {message}',
			array(
				'message'   => $exception->getMessage(),
				'exception' => $exception,
			)
		);

		if ( \in_array( \wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			$this->unlock(); // Releasing the lock lets the fixed step run again on the next request.
			throw $exception;
		}
	}

	/**
	 * Logs a record, through the PHP error log when the logger throws.
	 *
	 * A broken logger must neither hide a failure nor break the request that reports it.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string               $level   The PSR-3 level.
	 * @param   string               $message The message with placeholders.
	 * @param   array<string, mixed> $context The values to interpolate, and an optional throwable under exception.
	 */
	protected function log( string $level, string $message, array $context ): void {
		try {
			$this->logger->log( $level, $message, $context );
		} catch ( \Throwable $logging_failure ) {
			$exception = $context['exception'] ?? null;
			\error_log( "[$this->option.$level] " . interpolate_log_message( $message, $context ) . ( $exception instanceof \Throwable ? "\n$exception" : '' ) . "\nThe logger failed: $logging_failure" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The injected logger failed.
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
	 */
	protected function record_error( string $message, ?int $revision ): void {
		$now = $this->clock->now()->getTimestamp();
		\update_option(
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
	 * Deletes the error row once a step or batch succeeds, since the failure it records is no longer current.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	protected function clear_error(): void {
		if ( false !== \get_option( "{$this->option}_error" ) ) {
			\delete_option( "{$this->option}_error" );
		}
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
		$row = \get_option( "{$this->option}_error", null );
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
	 * Returns whether the request's time or memory budget is spent.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  bool
	 */
	protected function is_out_of_budget(): bool {
		if ( \is_null( $this->deadline ) ) {
			return false;
		}

		$memory_limit = \wp_convert_hr_to_bytes( (string) \ini_get( 'memory_limit' ) );

		return $this->get_time() >= $this->deadline || ( 0 < $memory_limit && \memory_get_usage( true ) > 0.9 * $memory_limit );
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

	/**
	 * Returns the name of the lock row.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  string
	 */
	protected function get_lock_name(): string {
		return "{$this->option}_lock";
	}

	// endregion
}
