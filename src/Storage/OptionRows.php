<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Storage;

use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use DeepWebSolutions\Framework\Shared\Exception\RuntimeException;

\defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes options as rows of the current site's options table, with writes that are atomic or conditional on the exact row read.
 *
 * The options API decides each write from a possibly cached read, so it cannot write conditionally. Conditional writes compare bytes, not the column's case-insensitive collation, and every write that changes a row clears the option's cache entries, so get_option() reads it.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class OptionRows {
	// region FIELDS AND CONSTANTS

	/**
	 * The length of the option_name column, past which the database truncates a name onto another option's without an error.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     int
	 */
	protected const int MAX_NAME_LENGTH = 191;

	// endregion

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
	 * Returns the option's row, read past the options cache, or null when the site has no such option.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @global  \wpdb $wpdb The WordPress database connection.
	 *
	 * @param   string $name The option name.
	 *
	 * @throws  InvalidArgumentException Thrown when the name is invalid.
	 * @throws  RuntimeException         Thrown when the read fails.
	 *
	 * @return  OptionRow|null
	 */
	public static function get( string $name ): ?OptionRow {
		global $wpdb;

		self::check_name( $name );

		// A row rather than a single value, because wpdb::get_var() returns null for an empty string as it does for a missing row.
		$wpdb->flush();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) ?? '', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A cached value may predate another request's write.
		if ( '' !== $wpdb->last_error ) { // wpdb::get_row() returns null for a failed query as for a missing row, so the error left after the flush tells them apart.
			throw new RuntimeException( "Could not read option '$name': $wpdb->last_error" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		$raw = \is_array( $row ) ? ( $row['option_value'] ?? null ) : null;
		return \is_string( $raw ) ? self::make_row( $name, $raw ) : null;
	}

	/**
	 * Writes the option only when the site has no option of that name, and returns its row, or null when the option exists.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @global  \wpdb $wpdb The WordPress database connection.
	 *
	 * @param   string $name  The option name.
	 * @param   mixed  $value The value: a scalar, null, or an array of them.
	 *
	 * @throws  InvalidArgumentException Thrown when the name or the value is invalid.
	 * @throws  RuntimeException         Thrown when the write fails.
	 *
	 * @return  OptionRow|null
	 */
	public static function insert( string $name, mixed $value ): ?OptionRow {
		global $wpdb;

		$raw = self::serialize( $name, $value );

		$inserted = self::write( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, $name, $raw, 'off' ) ?? '', "Could not insert option '$name'" );
		if ( 0 === $inserted ) {
			return null;
		}

		self::forget( $name );
		return self::make_row( $name, $raw );
	}

	/**
	 * Replaces the observed row's value only while the row still holds it, and returns the new row, or null when the row has changed or gone since.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @global  \wpdb $wpdb The WordPress database connection.
	 *
	 * @param   OptionRow $observed The row as it was read.
	 * @param   mixed     $value    The new value: a scalar, null, or an array of them, stored other than the observed one.
	 *
	 * @throws  InvalidArgumentException Thrown when the value is invalid or would be stored as the observed one, or the row's name is invalid.
	 * @throws  RuntimeException         Thrown when the write fails.
	 *
	 * @return  OptionRow|null
	 */
	public static function replace( OptionRow $observed, mixed $value ): ?OptionRow {
		global $wpdb;

		$raw = self::serialize( $observed->name, $value );
		if ( $raw === $observed->raw ) { // The database reports no affected row for an unchanged value, which would read as a lost race.
			throw new InvalidArgumentException( "The new value of option '$observed->name' is stored as the observed one." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		$replaced = self::write( $wpdb->prepare( 'UPDATE %i SET option_value = %s, autoload = %s WHERE option_name = %s AND CAST( option_value AS BINARY ) = CAST( %s AS BINARY )', $wpdb->options, $raw, 'off', $observed->name, $observed->raw ) ?? '', "Could not replace option '$observed->name'" );
		if ( 0 === $replaced ) {
			return null;
		}

		self::forget( $observed->name );
		return self::make_row( $observed->name, $raw );
	}

	/**
	 * Deletes the observed row only while it still holds the observed value, and returns whether it did.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @global  \wpdb $wpdb The WordPress database connection.
	 *
	 * @param   OptionRow $observed The row as it was read.
	 *
	 * @throws  RuntimeException Thrown when the delete fails.
	 *
	 * @return  bool
	 */
	public static function delete( OptionRow $observed ): bool {
		global $wpdb;

		$deleted = self::write( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND CAST( option_value AS BINARY ) = CAST( %s AS BINARY )', $wpdb->options, $observed->name, $observed->raw ) ?? '', "Could not delete option '$observed->name'" );
		if ( 0 === $deleted ) {
			return false;
		}

		self::forget( $observed->name );
		return true;
	}

	/**
	 * Throws when the options API or the option_name column would not keep the name exactly as given.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @internal
	 *
	 * @param   string $name       The option name or prefix.
	 * @param   int    $max_length Optional. The most characters the name may hold. Default is the 191 of the option_name column.
	 *
	 * @throws  InvalidArgumentException Thrown when the name is empty, surrounded by whitespace or longer than the maximum.
	 *
	 * @return  void
	 */
	public static function check_name( string $name, int $max_length = self::MAX_NAME_LENGTH ): void {
		if ( '' === $name || \trim( $name ) !== $name || \mb_strlen( $name ) > $max_length ) {
			throw new InvalidArgumentException( "Option name '$name' is empty, surrounded by whitespace or longer than $max_length characters." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the value as the options table stores it.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $name  The option name.
	 * @param   mixed  $value The value.
	 *
	 * @throws  InvalidArgumentException Thrown when the name is invalid or the value holds anything but scalars, null and arrays.
	 *
	 * @return  string
	 */
	protected static function serialize( string $name, mixed $value ): string {
		if ( ! self::is_storable( $value ) ) { // A stored object's class name changes with each plugin's scoping prefix, so it would not read back.
			throw new InvalidArgumentException( "The value of option '$name' holds something other than scalars, null and arrays." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		self::check_name( $name );

		$raw = maybe_serialize( $value );
		return \is_scalar( $raw ) ? (string) $raw : '';
	}

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
	protected static function is_storable( mixed $value ): bool {
		return \is_null( $value ) || \is_scalar( $value ) || ( \is_array( $value ) && \array_all( $value, self::is_storable( ... ) ) );
	}

	/**
	 * Returns a row from its stored value.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $name The option name.
	 * @param   string $raw  The value as stored.
	 *
	 * @return  OptionRow
	 */
	protected static function make_row( string $name, string $raw ): OptionRow {
		return new OptionRow( $name, maybe_unserialize( $raw ), $raw );
	}

	/**
	 * Runs a write and returns the number of rows it changed.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @global  \wpdb $wpdb The WordPress database connection.
	 *
	 * @param   string $query   The prepared statement.
	 * @param   string $failure The message that opens the exception when the write fails.
	 *
	 * @throws  RuntimeException Thrown when the write fails.
	 *
	 * @return  int
	 */
	protected static function write( string $query, string $failure ): int {
		global $wpdb;

		$changed = $wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared by the caller; the options API cannot write conditionally.
		if ( false === $changed ) {
			throw new RuntimeException( "$failure: $wpdb->last_error" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		return \is_int( $changed ) ? $changed : 0;
	}

	/**
	 * Clears the option's cache entries, and drops the cached lists that name it, so that get_option() reads the row as it now is.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $name The option name.
	 *
	 * @return  void
	 */
	protected static function forget( string $name ): void {
		wp_cache_delete( $name, 'options' );
		foreach ( array( 'notoptions', 'alloptions' ) as $list ) {
			// Writing a cached list back could restore another request's stale entries, so a list that names the option is dropped.
			$names = wp_cache_get( $list, 'options' );
			if ( \is_array( $names ) && \array_key_exists( $name, $names ) ) {
				wp_cache_delete( $list, 'options' );
			}
		}
	}

	// endregion
}
