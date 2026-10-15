<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Scheduling;

use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;

\defined( 'ABSPATH' ) || exit;

/**
 * The identity of a scheduled invocation, either one-off or recurring but never both: a hook name and the positional arguments its callbacks receive.
 *
 * Arguments are limited to integers, strings, booleans and null, which both Action Scheduler and WP-Cron store and pass back unchanged.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final readonly class ScheduledHook {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string                     $hook The hook name, 1 to 191 bytes without whitespace, other than '0'.
	 * @param   list<int|string|bool|null> $args Optional. The arguments, whose JSON must fit 8,000 bytes. Default is an empty list.
	 *
	 * @throws  InvalidArgumentException Thrown when the hook name or the arguments do not fit both schedulers.
	 */
	public function __construct(
		public string $hook,
		public array $args = array()
	) {
		// Action Scheduler reads a hook name of '0' as no hook, so its queries and cancellations would match every hook.
		if ( '' === $hook || '0' === $hook || \strlen( $hook ) > 191 || 1 === \preg_match( '/\s/', $hook ) ) {
			throw new InvalidArgumentException( "Hook '$hook' is not 1 to 191 bytes without whitespace, other than '0'." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		if ( ! \array_is_list( $args ) || ! \array_all( $args, static fn ( mixed $arg ): bool => \is_int( $arg ) || \is_string( $arg ) || \is_bool( $arg ) || \is_null( $arg ) ) ) {
			throw new InvalidArgumentException( "The arguments of hook '$hook' are not a list of integers, strings, booleans and nulls." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		try {
			$json = \json_encode( $args, \JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() replaces invalid UTF-8 instead of failing.
		} catch ( \JsonException $exception ) {
			throw new InvalidArgumentException( "The arguments of hook '$hook' do not encode as JSON: {$exception->getMessage()}", 0, $exception ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		if ( \strlen( $json ) > 8000 ) {
			throw new InvalidArgumentException( "The arguments of hook '$hook' exceed 8,000 bytes of JSON." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}
	}

	// endregion
}
