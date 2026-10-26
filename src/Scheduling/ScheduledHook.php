<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Scheduling;

use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use DeepWebSolutions\Framework\Shared\ValueObject\AbstractValueObject;

\defined( 'ABSPATH' ) || exit;

/**
 * The identity of a scheduled invocation, either one-off or recurring but never both: a hook name and the positional arguments its callbacks receive.
 *
 * Arguments are limited to integers, strings, booleans and null, which both Action Scheduler and WP-Cron store and pass back unchanged, except a string holding invalid UTF-8, which Action Scheduler stores with those bytes replaced and WP-Cron refuses.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final readonly class ScheduledHook extends AbstractValueObject {
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

		// Action Scheduler refuses arguments whose wp_json_encode() output exceeds 8,000 bytes.
		if ( \strlen( (string) wp_json_encode( $args ) ) > 8000 ) {
			throw new InvalidArgumentException( "The arguments of hook '$hook' exceed 8,000 bytes of JSON." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}
	}

	// endregion
}
