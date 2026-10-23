<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Storage;

use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use DeepWebSolutions\Framework\Shared\Exception\LogicException;
use DeepWebSolutions\Framework\Shared\Exception\RuntimeException;

\defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes options as rows of a site's options table, with writes that are atomic or conditional on the exact row read.
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

	/**
	 * The database connection.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     \wpdb
	 */
	protected \wpdb $wpdb;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   \wpdb|null $wpdb Optional. The database connection. Default is WordPress's.
	 *
	 * @throws  LogicException Thrown when no connection is given and WordPress has none.
	 */
	public function __construct(
		?\wpdb $wpdb = null
	) {
		$wpdb ??= $GLOBALS['wpdb'] ?? null;
		if ( ! $wpdb instanceof \wpdb ) {
			throw new LogicException( 'The option rows need a wpdb connection.' );
		}

		$this->wpdb = $wpdb;
	}

	// endregion

	// region METHODS

	/**
	 * Returns the option's row, read past the options cache, or null when the site has no such option.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $name    The option name.
	 * @param   int    $site_id Optional. The site whose table to read, where 0 is the current site. Default 0.
	 *
	 * @throws  InvalidArgumentException Thrown when the name or the site is invalid.
	 * @throws  RuntimeException         Thrown when the read fails.
	 *
	 * @return  OptionRow|null
	 */
	public function get( string $name, int $site_id = 0 ): ?OptionRow {
		$wpdb    = $this->wpdb;
		$site_id = $this->get_site_id( $site_id );
		$this->check_name( $name );

		// A row rather than a single value, because wpdb::get_var() returns null for an empty string as it does for a missing row.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $this->get_table( $site_id ), $name ) ?? '', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A cached value may predate another request's write.
		$this->check_query( "Could not read option '$name'" );

		$raw = \is_array( $row ) ? ( $row['option_value'] ?? null ) : null;

		return \is_string( $raw ) ? $this->make_row( $site_id, $name, $raw ) : null;
	}

	/**
	 * Returns the rows whose option names start with the prefix, ordered by name.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $prefix  The prefix, matched byte for byte.
	 * @param   int    $site_id Optional. The site whose table to read, where 0 is the current site. Default 0.
	 *
	 * @throws  InvalidArgumentException Thrown when the prefix or the site is invalid.
	 * @throws  RuntimeException         Thrown when the read fails.
	 *
	 * @return  list<OptionRow>
	 */
	public function list( string $prefix, int $site_id = 0 ): array {
		$wpdb    = $this->wpdb;
		$site_id = $this->get_site_id( $site_id );
		$this->check_name( $prefix );

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s ORDER BY option_name', $this->get_table( $site_id ), $wpdb->esc_like( $prefix ) . '%' ) ?? '', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The options API cannot list options by prefix.
		$this->check_query( "Could not list the options under '$prefix'" );

		$list = array();
		foreach ( (array) $rows as $row ) {
			$name = $row['option_name'] ?? null;
			$raw  = $row['option_value'] ?? null;

			// The column's collation also matches names that differ from the prefix in letter case or accents.
			if ( \is_string( $name ) && \is_string( $raw ) && \str_starts_with( $name, $prefix ) ) {
				$list[] = $this->make_row( $site_id, $name, $raw );
			}
		}

		return $list;
	}

	/**
	 * Writes the option's value in one statement, whether or not the option exists, and returns its row.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $name    The option name.
	 * @param   mixed  $value   The value: a scalar, null, or an array of them.
	 * @param   int    $site_id Optional. The site whose table to write, where 0 is the current site. Default 0.
	 *
	 * @throws  InvalidArgumentException Thrown when the name, the value or the site is invalid.
	 * @throws  RuntimeException         Thrown when the write fails.
	 *
	 * @return  OptionRow
	 */
	public function upsert( string $name, mixed $value, int $site_id = 0 ): OptionRow {
		$wpdb    = $this->wpdb;
		$site_id = $this->get_site_id( $site_id );
		$raw     = $this->serialize( $name, $value );

		// One statement, because add_option() refuses an existing option and update_option() writes nothing once the option disappears after its read.
		$this->write( $wpdb->prepare( 'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)', $this->get_table( $site_id ), $name, $raw, 'off' ) ?? '', "Could not write option '$name'" );
		$this->forget( $site_id, $name );

		return $this->make_row( $site_id, $name, $raw );
	}

	/**
	 * Writes the option only when the site has no option of that name, and returns its row, or null when the option exists.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $name    The option name.
	 * @param   mixed  $value   The value: a scalar, null, or an array of them.
	 * @param   int    $site_id Optional. The site whose table to write, where 0 is the current site. Default 0.
	 *
	 * @throws  InvalidArgumentException Thrown when the name, the value or the site is invalid.
	 * @throws  RuntimeException         Thrown when the write fails.
	 *
	 * @return  OptionRow|null
	 */
	public function insert( string $name, mixed $value, int $site_id = 0 ): ?OptionRow {
		$wpdb    = $this->wpdb;
		$site_id = $this->get_site_id( $site_id );
		$raw     = $this->serialize( $name, $value );

		$inserted = $this->write( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $this->get_table( $site_id ), $name, $raw, 'off' ) ?? '', "Could not insert option '$name'" );
		if ( 0 === $inserted ) {
			return null;
		}

		$this->forget( $site_id, $name );

		return $this->make_row( $site_id, $name, $raw );
	}

	/**
	 * Replaces the observed row's value only while the row still holds it, and returns the new row, or null when the row has changed or gone since.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   OptionRow $observed The row as it was read.
	 * @param   mixed     $value    The new value: a scalar, null, or an array of them, stored other than the observed one.
	 *
	 * @throws  InvalidArgumentException Thrown when the value is invalid or would be stored as the observed one, or the row's name or site is invalid.
	 * @throws  RuntimeException         Thrown when the write fails.
	 *
	 * @return  OptionRow|null
	 */
	public function replace( OptionRow $observed, mixed $value ): ?OptionRow {
		$wpdb    = $this->wpdb;
		$site_id = $this->get_site_id( $observed->site_id );
		$raw     = $this->serialize( $observed->name, $value );

		// The database reports no affected row for an unchanged value, which would read as a lost race.
		if ( $raw === $observed->raw ) {
			throw new InvalidArgumentException( "The new value of option '$observed->name' is stored as the observed one." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		$replaced = $this->write( $wpdb->prepare( 'UPDATE %i SET option_value = %s, autoload = %s WHERE option_name = %s AND CAST( option_value AS BINARY ) = CAST( %s AS BINARY )', $this->get_table( $site_id ), $raw, 'off', $observed->name, $observed->raw ) ?? '', "Could not replace option '$observed->name'" );
		if ( 0 === $replaced ) {
			return null;
		}

		$this->forget( $site_id, $observed->name );

		return $this->make_row( $site_id, $observed->name, $raw );
	}

	/**
	 * Deletes the observed row only while it still holds the observed value, and returns whether it did.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   OptionRow $observed The row as it was read.
	 *
	 * @throws  InvalidArgumentException Thrown when the row's site is invalid.
	 * @throws  RuntimeException         Thrown when the delete fails.
	 *
	 * @return  bool
	 */
	public function delete( OptionRow $observed ): bool {
		$wpdb    = $this->wpdb;
		$site_id = $this->get_site_id( $observed->site_id );

		$deleted = $this->write( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND CAST( option_value AS BINARY ) = CAST( %s AS BINARY )', $this->get_table( $site_id ), $observed->name, $observed->raw ) ?? '', "Could not delete option '$observed->name'" );
		if ( 0 === $deleted ) {
			return false;
		}

		$this->forget( $site_id, $observed->name );

		return true;
	}

	/**
	 * Deletes the option whatever it holds, through delete_option() and so on WordPress's own connection, which fires the option hooks and clears the option's caches.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $name    The option name.
	 * @param   int    $site_id Optional. The site whose option to delete, where 0 is the current site. Default 0.
	 *
	 * @throws  InvalidArgumentException Thrown when the name or the site is invalid.
	 * @throws  RuntimeException         Thrown when the delete fails.
	 */
	public function remove( string $name, int $site_id = 0 ): void {
		$this->check_name( $name );
		$this->on_site(
			$this->get_site_id( $site_id ),
			static function () use ( $name ): void {
				$wpdb = $GLOBALS['wpdb'] ?? null;

				// An absent option and a failed delete both make delete_option() return false, so only the error tells them apart.
				\delete_option( $name );
				if ( $wpdb instanceof \wpdb && '' !== $wpdb->last_error ) {
					throw new RuntimeException( "Could not remove option '$name': $wpdb->last_error" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
				}
			}
		);
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the blog id of the site to address, which is the current site for 0.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int $site_id The site, where 0 is the current site.
	 *
	 * @throws  InvalidArgumentException Thrown when the site is another site on a single site.
	 *
	 * @return  int
	 */
	protected function get_site_id( int $site_id ): int {
		$current = \get_current_blog_id();
		if ( 0 === $site_id || $current === $site_id ) {
			return $current;
		}

		// On a single site, get_blog_prefix() ignores the site and would address this site's table.
		if ( ! \is_multisite() ) {
			throw new InvalidArgumentException( "Site $site_id is not a site of this installation." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		return $site_id;
	}

	/**
	 * Returns the name of the site's options table.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int $site_id The blog id.
	 *
	 * @return  string
	 */
	protected function get_table( int $site_id ): string {
		return $this->wpdb->get_blog_prefix( $site_id ) . 'options';
	}

	/**
	 * Throws when the options API or the option_name column would not keep the name exactly as given.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $name The option name or prefix.
	 *
	 * @throws  InvalidArgumentException Thrown when the name is empty, surrounded by whitespace or longer than 191 characters.
	 */
	protected function check_name( string $name ): void {
		if ( '' === $name || \trim( $name ) !== $name || \mb_strlen( $name ) > self::MAX_NAME_LENGTH ) {
			throw new InvalidArgumentException( "Option name '$name' is empty, surrounded by whitespace or longer than 191 characters." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}
	}

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
	protected function serialize( string $name, mixed $value ): string {
		$this->check_name( $name );

		// A stored object's class name changes with each plugin's scoping prefix, so it would not read back.
		if ( ! self::is_storable( $value ) ) {
			throw new InvalidArgumentException( "The value of option '$name' holds something other than scalars, null and arrays." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		$raw = \maybe_serialize( $value );

		return \is_scalar( $raw ) ? (string) $raw : '';
	}

	/**
	 * Returns a row of the site from its stored value.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int    $site_id The blog id.
	 * @param   string $name    The option name.
	 * @param   string $raw     The value as stored.
	 *
	 * @return  OptionRow
	 */
	protected function make_row( int $site_id, string $name, string $raw ): OptionRow {
		return new OptionRow( $site_id, $name, \maybe_unserialize( $raw ), $raw );
	}

	/**
	 * Runs a write and returns the number of rows it changed.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $query   The prepared statement.
	 * @param   string $failure The message that opens the exception when the write fails.
	 *
	 * @throws  RuntimeException Thrown when the write fails.
	 *
	 * @return  int
	 */
	protected function write( string $query, string $failure ): int {
		$changed = $this->wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared by the caller; the options API cannot write conditionally.
		if ( false === $changed ) {
			throw new RuntimeException( "$failure: {$this->wpdb->last_error}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		return \is_int( $changed ) ? $changed : 0;
	}

	/**
	 * Throws when the last query failed.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $failure The message that opens the exception.
	 *
	 * @throws  RuntimeException Thrown when the last query failed.
	 */
	protected function check_query( string $failure ): void {
		if ( '' !== $this->wpdb->last_error ) {
			throw new RuntimeException( "$failure: {$this->wpdb->last_error}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}
	}

	/**
	 * Clears the option's cache entries on the site, and drops the cached lists that name it, so that get_option() reads the row as it now is.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int    $site_id The blog id.
	 * @param   string $name    The option name.
	 */
	protected function forget( int $site_id, string $name ): void {
		$this->on_site(
			$site_id,
			static function () use ( $name ): void {
				\wp_cache_delete( $name, 'options' );
				foreach ( array( 'notoptions', 'alloptions' ) as $list ) {
					// Writing a cached list back could restore another request's stale entries, so a list that names the option is dropped.
					$names = \wp_cache_get( $list, 'options' );
					if ( \is_array( $names ) && \array_key_exists( $name, $names ) ) {
						\wp_cache_delete( $list, 'options' );
					}
				}
			}
		);
	}

	/**
	 * Runs an operation on the site, switching to it when it is not the current one.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int      $site_id   The blog id.
	 * @param   \Closure $operation The operation.
	 */
	protected function on_site( int $site_id, \Closure $operation ): void {
		$switch = \get_current_blog_id() !== $site_id;
		if ( $switch ) {
			\switch_to_blog( $site_id );
		}

		try {
			$operation();
		} finally {
			if ( $switch ) {
				\restore_current_blog();
			}
		}
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

	// endregion
}
