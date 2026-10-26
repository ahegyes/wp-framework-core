<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Logging;

use Psr\Log\InvalidArgumentException as PsrInvalidArgumentException;

\defined( 'ABSPATH' ) || exit;

/**
 * Writes log records to the PHP error log and to Query Monitor.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class ErrorLogLogger extends AbstractLogger {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $channel The name that identifies the plugin in every record.
	 */
	public function __construct(
		protected string $channel
	) {}

	// endregion

	// region INHERITED METHODS

	/**
	 * Writes a record to the PHP error log and fires Query Monitor's action for its level.
	 *
	 * Records below warning are dropped unless WP_DEBUG is on.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed              $level   The record's level, one of the PsrLogLevel constants.
	 * @param   string|\Stringable $message The message, with placeholders.
	 * @param   array<mixed>       $context The values to interpolate, and any Throwable to write after the message under the exception key.
	 *
	 * @throws  PsrInvalidArgumentException Thrown when the level is not a PSR-3 level.
	 */
	#[\Override]
	public function log( mixed $level, string|\Stringable $message, array $context = array() ): void {
		$severity = self::get_severity( $level );
		if ( $severity < self::get_default_threshold() ) {
			return;
		}

		\error_log( self::format_record( $this->channel, $level, self::interpolate( (string) $message, $context ), $context ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Writing to the error log is this logger's purpose.
		do_action( "qm/$level", $message, $context );
	}

	// endregion
}
