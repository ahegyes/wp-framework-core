<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core;

use DeepWebSolutions\Framework\Bootstrap\Requirements;
use DeepWebSolutions\Framework\Core\Logging\GuardedLogger;
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
	protected(set) string $status = 'pending';

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string             $plugin_file The path to the plugin's entry file.
	 * @param   string             $prefix      The prefix of the plugin's hook names, transients and log channel.
	 * @param   PsrLoggerInterface $logger      The logger that records boot failures.
	 */
	public function __construct(
		protected string $plugin_file,
		protected string $prefix,
		protected PsrLoggerInterface $logger
	) {
		$this->logger = new GuardedLogger( $logger, $prefix );
	}

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
	 * @param   callable(): bool $compose The composition, which builds the plugin, boots its kernel and returns false after reporting an unmet requirement.
	 *
	 * @throws  \Throwable Thrown when the composition fails in a local or development environment, after the failure action fired.
	 *
	 * @return  void
	 */
	public function run( callable $compose ): void {
		try {
			$composed = $compose();
		} catch ( \Throwable $exception ) {
			$this->status = 'failed';

			// A deterministic failure repeats on every request, so each distinct message is logged about once an hour, as best effort.
			$key = "{$this->prefix}_boot_failure_" . \md5( $exception->getMessage() );
			if ( false === get_transient( $key ) ) {
				set_transient( $key, 1, HOUR_IN_SECONDS );
				$this->logger->error(
					'Boot failed; the plugin did not finish starting: {message}',
					array(
						'message'   => $exception->getMessage(),
						'exception' => $exception,
					)
				);
			}

			$plugin_file = $this->plugin_file;
			add_action(
				'all_admin_notices',
				static function () use ( $plugin_file ): void {
					Requirements::print_admin_notice(
						\sprintf(
							/* translators: %s: The plugin's name. */
							esc_html__( '%s could not finish starting, so its features may be unavailable. The error has been logged.', 'wp-framework' ),
							esc_html( get_plugin_data( $plugin_file, false )['Name'] )
						)
					);
				}
			);

			/**
			 * Fires when the plugin's composition throws, before the throwable is rethrown in local and development environments.
			 *
			 * @since   2.0.0
			 * @version 2.0.0
			 *
			 * @param   \Throwable $exception The failure that stopped the composition.
			 */
			do_action( "{$this->prefix}/boot/failed", $exception );
			if ( self::is_development_environment() ) {
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
			do_action( "{$this->prefix}/boot/blocked" );
			return;
		}

		$this->status = 'booted';

		/**
		 * Fires when the plugin's composition completed.
		 *
		 * @since   2.0.0
		 * @version 2.0.0
		 */
		do_action( "{$this->prefix}/boot/succeeded" );
	}

	/**
	 * Returns whether the site runs in a local or development environment, where the framework rethrows the failures it records.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @internal
	 *
	 * @return  bool
	 */
	public static function is_development_environment(): bool {
		return \in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
	}

	// endregion
}
