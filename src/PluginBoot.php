<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework;

use Psr\Log\LoggerInterface as PsrLoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Runs a plugin's composition inside the boot error boundary and announces its outcome.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class PluginBoot {
	// region FIELDS AND CONSTANTS

	/**
	 * The outcome of the composition, where blocked means that it reported an unmet requirement.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     'pending'|'blocked'|'failed'|'booted'
	 */
	public protected(set) string $status = 'pending';

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string             $plugin_file The path to the plugin's entry file.
	 * @param   string             $hook_prefix The prefix of the plugin's hook names.
	 * @param   PsrLoggerInterface $logger      The logger that records boot failures.
	 */
	public function __construct(
		protected string $plugin_file,
		protected string $hook_prefix,
		protected PsrLoggerInterface $logger
	) {}

	// endregion

	// region METHODS

	/**
	 * Runs the composition and announces success only when all of it completed.
	 *
	 * The rethrow comes last, so the failure action fires in every environment.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   \Closure(): bool $compose The composition, which builds the plugin, boots its kernel and returns false after reporting an unmet requirement.
	 *
	 * @throws  \Throwable Thrown when the composition fails in a local or development environment, after the failure action fired.
	 */
	public function run( \Closure $compose ): void {
		try {
			$composed = $compose();
		} catch ( \Throwable $exception ) {
			$this->status = 'failed';
			$this->log_failure( $exception );
			\add_action( 'all_admin_notices', array( $this, 'render_failure_notice' ) );

			/**
			 * Fires when the plugin's composition throws, before the throwable is rethrown in local and development environments.
			 *
			 * @since   2.0.0
			 * @version 2.0.0
			 *
			 * @param   \Throwable $exception The failure that stopped the composition.
			 */
			\do_action( "{$this->hook_prefix}_initialization_failure", $exception );
			if ( \in_array( \wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
				throw $exception;
			}

			return;
		}

		if ( ! $composed ) {
			$this->status = 'blocked';

			/**
			 * Fires when the plugin's composition stops at an unmet requirement.
			 *
			 * @since   2.0.0
			 * @version 2.0.0
			 */
			\do_action( "{$this->hook_prefix}_initialization_blocked" );
			return;
		}

		$this->status = 'booted';

		/**
		 * Fires when the plugin's composition completed.
		 *
		 * @since   2.0.0
		 * @version 2.0.0
		 */
		\do_action( "{$this->hook_prefix}_initialized" );
	}

	// endregion

	// region HOOKS

	/**
	 * Tells users who can activate plugins that the plugin could not finish starting.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function render_failure_notice(): void {
		if ( ! \current_user_can( 'activate_plugins' ) ) {
			return;
		}

		\wp_admin_notice(
			\sprintf(
				/* translators: %s: The plugin's name. */
				\esc_html__( '%s could not finish starting, so its features may be unavailable. The error has been logged.', 'wp-framework' ),
				\esc_html( \get_plugin_data( $this->plugin_file, false )['Name'] )
			),
			array( 'type' => 'error' )
		);
	}

	// endregion

	// region HELPERS

	/**
	 * Logs each distinct failure message at most once an hour.
	 *
	 * A deterministic failure repeats on every request, so logging each one would flood the log.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   \Throwable $exception The failure to log.
	 */
	protected function log_failure( \Throwable $exception ): void {
		$key = "{$this->hook_prefix}_boot_failure_" . \md5( $exception->getMessage() );
		if ( false !== \get_transient( $key ) ) {
			return;
		}

		\set_transient( $key, 1, HOUR_IN_SECONDS );
		$this->logger->error(
			'Boot failed; the plugin did not finish starting: {message}',
			array(
				'message'   => $exception->getMessage(),
				'exception' => $exception,
			)
		);
	}

	// endregion
}
