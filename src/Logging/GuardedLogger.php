<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Logging;

use Psr\Log\InvalidArgumentException as PsrInvalidArgumentException;
use Psr\Log\LoggerInterface as PsrLoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Passes each record to another logger, and writes it to the PHP error log when that logger throws.
 *
 * A broken logger must neither hide the failure it records nor break the request that records it.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @internal
 */
final class GuardedLogger extends AbstractLogger {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   PsrLoggerInterface $logger  The logger that receives every record.
	 * @param   string             $channel The name that identifies the plugin in a record written to the PHP error log.
	 */
	public function __construct(
		protected PsrLoggerInterface $logger,
		protected string $channel
	) {}

	// endregion

	// region INHERITED METHODS

	/**
	 * Passes the record to the wrapped logger, or writes it raw, with its context as JSON, to the PHP error log when that logger throws.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed              $level   The record's level, one of the PsrLogLevel constants.
	 * @param   string|\Stringable $message The message, with placeholders.
	 * @param   array<mixed>       $context The values to interpolate, and any Throwable under the exception key.
	 *
	 * @throws  PsrInvalidArgumentException Thrown when the level is not a PSR-3 level.
	 */
	#[\Override]
	public function log( mixed $level, string|\Stringable $message, array $context = array() ): void {
		self::get_severity( $level );

		try {
			$this->logger->log( $level, $message, $context );
		} catch ( \Throwable $failure ) {
			// The raw message with its context as JSON keeps the values that no placeholder names.
			$encoded = \wp_json_encode( \array_diff_key( $context, array( 'exception' => true ) ) );
			\error_log( self::format_record( $this->channel, $level, "$message $encoded", $context ) . "\nThe logger failed: $failure" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The wrapped logger failed.
		}
	}

	// endregion
}
