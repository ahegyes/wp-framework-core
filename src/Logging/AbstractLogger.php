<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Logging;

use Psr\Log\AbstractLogger as PsrAbstractLogger;
use Psr\Log\InvalidArgumentException as PsrInvalidArgumentException;
use Psr\Log\LogLevel as PsrLogLevel;

\defined( 'ABSPATH' ) || exit;

/**
 * Ranks, interpolates and formats log records for the framework's loggers, each of which writes them in its own log() method.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
abstract class AbstractLogger extends PsrAbstractLogger {
	// region FIELDS AND CONSTANTS

	/**
	 * The severity of each level, as WooCommerce ranks them.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     array<string, int>
	 */
	protected const array SEVERITIES = array(
		PsrLogLevel::EMERGENCY => 800,
		PsrLogLevel::ALERT     => 700,
		PsrLogLevel::CRITICAL  => 600,
		PsrLogLevel::ERROR     => 500,
		PsrLogLevel::WARNING   => 400,
		PsrLogLevel::NOTICE    => 300,
		PsrLogLevel::INFO      => 200,
		PsrLogLevel::DEBUG     => 100,
	);

	// endregion

	// region METHODS

	/**
	 * Returns the severity of the level.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @phpstan-assert string $level
	 *
	 * @param   mixed $level The level, one of the PsrLogLevel constants.
	 *
	 * @throws  PsrInvalidArgumentException Thrown when the level is not a PSR-3 level.
	 *
	 * @return  int
	 */
	protected static function get_severity( mixed $level ): int {
		$severity = \is_string( $level ) ? ( self::SEVERITIES[ $level ] ?? null ) : null;
		return $severity ?? throw new PsrInvalidArgumentException( "Log level '" . ( \is_string( $level ) ? $level : \get_debug_type( $level ) ) . "' is not a PSR-3 level." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
	}

	/**
	 * Returns the severity of the lowest level logged without a threshold of the logger's own: debug under WP_DEBUG, and warning otherwise.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  int
	 */
	protected static function get_default_threshold(): int {
		return self::SEVERITIES[ \defined( 'WP_DEBUG' ) && WP_DEBUG ? PsrLogLevel::DEBUG : PsrLogLevel::WARNING ];
	}

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
	protected static function interpolate( string $message, array $context ): string {
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

	/**
	 * Returns the record as one entry of the PHP error log: the channel and level, the message, and any Throwable under the context's exception key on the lines after.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string       $channel The name that identifies the plugin.
	 * @param   string       $level   The record's level.
	 * @param   string       $message The message as it is written.
	 * @param   array<mixed> $context The record's context.
	 *
	 * @return  string
	 */
	protected static function format_record( string $channel, string $level, string $message, array $context ): string {
		$exception = $context['exception'] ?? null;
		return "[$channel.$level] $message" . ( $exception instanceof \Throwable ? "\n$exception" : '' );
	}

	// endregion
}
