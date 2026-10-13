<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Utilities;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

use function DeepWebSolutions\Framework\Shared\interpolate_log_message;

\defined( 'ABSPATH' ) || exit;

/**
 * Writes log records to the PHP error log and to Query Monitor.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class ErrorLogLogger extends AbstractLogger {
	// region FIELDS AND CONSTANTS

	/**
	 * The levels written only under WP_DEBUG.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     list<string>
	 */
	protected const array DEBUG_LEVELS = array( LogLevel::NOTICE, LogLevel::INFO, LogLevel::DEBUG );

	/**
	 * The levels written in every environment.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     list<string>
	 */
	protected const array SEVERE_LEVELS = array( LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR, LogLevel::WARNING );

	// endregion

	// region INHERITED METHODS

	/**
	 * Writes a record to the PHP error log and fires Query Monitor's action for its level.
	 *
	 * Records below warning are written only under WP_DEBUG.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed              $level   The record's level, one of the LogLevel constants.
	 * @param   string|\Stringable $message The message, with placeholders.
	 * @param   array<mixed>       $context The values to interpolate.
	 *
	 * @throws  InvalidArgumentException Thrown when the level is not a PSR-3 level.
	 */
	#[\Override]
	public function log( mixed $level, string|\Stringable $message, array $context = array() ): void {
		if ( \in_array( $level, self::DEBUG_LEVELS, true ) ) {
			if ( ! ( \defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
				return;
			}
		} elseif ( ! \in_array( $level, self::SEVERE_LEVELS, true ) ) {
			throw new InvalidArgumentException( "Log level '" . ( \is_string( $level ) ? $level : \get_debug_type( $level ) ) . "' is not a PSR-3 level." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		\error_log( "[$level] " . interpolate_log_message( (string) $message, $context ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Writing to the error log is this logger's purpose.
		\do_action( "qm/$level", $message, $context );
	}

	// endregion
}
