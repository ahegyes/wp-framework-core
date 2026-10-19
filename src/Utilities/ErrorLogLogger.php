<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Utilities;

use Psr\Log\AbstractLogger as PsrAbstractLogger;
use Psr\Log\InvalidArgumentException as PsrInvalidArgumentException;
use Psr\Log\LogLevel as PsrLogLevel;

\defined( 'ABSPATH' ) || exit;

/**
 * Writes log records to the PHP error log and to Query Monitor.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class ErrorLogLogger extends PsrAbstractLogger {
	// region FIELDS AND CONSTANTS

	/**
	 * The levels written only under WP_DEBUG.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     list<string>
	 */
	protected const array DEBUG_LEVELS = array( PsrLogLevel::NOTICE, PsrLogLevel::INFO, PsrLogLevel::DEBUG );

	/**
	 * The levels written in every environment.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     list<string>
	 */
	protected const array SEVERE_LEVELS = array( PsrLogLevel::EMERGENCY, PsrLogLevel::ALERT, PsrLogLevel::CRITICAL, PsrLogLevel::ERROR, PsrLogLevel::WARNING );

	// endregion

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
		if ( \in_array( $level, self::DEBUG_LEVELS, true ) ) {
			if ( ! ( \defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
				return;
			}
		} elseif ( ! \in_array( $level, self::SEVERE_LEVELS, true ) ) {
			throw new PsrInvalidArgumentException( "Log level '" . ( \is_string( $level ) ? $level : \get_debug_type( $level ) ) . "' is not a PSR-3 level." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		$record    = "[$this->channel.$level] " . $this->interpolate( (string) $message, $context );
		$exception = $context['exception'] ?? null;
		if ( $exception instanceof \Throwable ) {
			$record .= "\n$exception";
		}

		\error_log( $record ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Writing to the error log is this logger's purpose.
		\do_action( "qm/$level", $message, $context );
	}

	// endregion

	// region HELPERS

	/**
	 * Replaces each {key} placeholder in the message with its scalar, null or Stringable context value.
	 *
	 * Only the values the message uses are cast, and a placeholder whose value throws while casting stays as it is, because PSR-3 forbids a context value from making logging fail.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string       $message The message with placeholders.
	 * @param   array<mixed> $context The values to interpolate.
	 *
	 * @return  string
	 */
	protected function interpolate( string $message, array $context ): string {
		$replacements = array();
		foreach ( $context as $key => $value ) {
			$placeholder = '{' . $key . '}';
			if ( ! \str_contains( $message, $placeholder ) || ! ( \is_null( $value ) || \is_scalar( $value ) || $value instanceof \Stringable ) ) {
				continue;
			}

			try {
				$replacements[ $placeholder ] = (string) $value;
			} catch ( \Throwable ) {
				continue;
			}
		}

		return \strtr( $message, $replacements );
	}

	// endregion
}
