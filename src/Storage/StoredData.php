<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Storage;

use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;

\defined( 'ABSPATH' ) || exit;

/**
 * Defines the data the framework's stores keep, which is scalars, null and arrays of them.
 *
 * A stored object's class name changes with each plugin's scoping prefix, so it would not read back.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class StoredData {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	protected function __construct() {}

	// endregion

	// region METHODS

	/**
	 * Returns whether the value holds only scalars, null and arrays of them.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @phpstan-assert-if-true scalar|array<mixed>|null $value
	 *
	 * @param   mixed $value The value.
	 *
	 * @return  bool
	 */
	public static function is_storable_data( mixed $value ): bool {
		return \is_null( $value ) || \is_scalar( $value ) || ( \is_array( $value ) && \array_all( $value, self::is_storable_data( ... ) ) );
	}

	/**
	 * Throws unless every value holds only scalars, null and arrays of them, naming the first key whose value does not.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   array<array-key, mixed> $values The values, keyed by their keys.
	 *
	 * @throws  InvalidArgumentException Thrown when a value holds anything but scalars, null and arrays.
	 *
	 * @return  void
	 */
	public static function check_values( array $values ): void {
		foreach ( $values as $key => $value ) {
			if ( ! self::is_storable_data( $value ) ) {
				throw new InvalidArgumentException( "The value of key '$key' holds something other than scalars, null and arrays." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
			}
		}
	}

	// endregion
}
