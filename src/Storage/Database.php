<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Storage;

use DeepWebSolutions\Framework\Shared\Exception\RuntimeException;

\defined( 'ABSPATH' ) || exit;

/**
 * Tells which values the storage classes keep, and checks a query for its own error.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @internal
 */
final class Database {
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
	 * @param   mixed $value The value.
	 *
	 * @return  bool
	 */
	public static function is_storable( mixed $value ): bool {
		return \is_null( $value ) || \is_scalar( $value ) || ( \is_array( $value ) && \array_all( $value, self::is_storable( ... ) ) );
	}

	/**
	 * Throws when the connection holds an error, which after the caller's flush() can only come from the caller's own query.
	 *
	 * A WordPress function may return without running its query, and wpdb clears an earlier error only when a query runs.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   \wpdb  $wpdb    The connection, flushed before the query.
	 * @param   string $failure The message that opens the exception.
	 *
	 * @throws  RuntimeException Thrown when the connection holds an error.
	 */
	public static function check_query( \wpdb $wpdb, string $failure ): void {
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( "$failure: $wpdb->last_error" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}
	}

	// endregion
}
