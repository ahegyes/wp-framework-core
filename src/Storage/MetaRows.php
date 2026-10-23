<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Storage;

use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use DeepWebSolutions\Framework\Shared\Exception\LogicException;
use DeepWebSolutions\Framework\Shared\Exception\RuntimeException;

\defined( 'ABSPATH' ) || exit;

/**
 * Reads, adds and deletes the metadata rows of one meta type, each row named by its meta id.
 *
 * Deleting by value would also delete every identical row, and for an empty value every row of the key, so a delete names the one row it read. Rows are listed past the meta cache, which keeps no meta ids.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class MetaRows {
	// region FIELDS AND CONSTANTS

	/**
	 * The length of the meta_key column, past which the database truncates a key onto another key's without an error.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     int
	 */
	protected const int MAX_KEY_LENGTH = 255;

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
	 * @param   string $meta_type The meta type, such as user, post, term, comment or blog.
	 *
	 * @throws  LogicException           Thrown when WordPress has no database connection.
	 * @throws  InvalidArgumentException Thrown when WordPress keeps no metadata of the type.
	 */
	public function __construct(
		protected string $meta_type
	) {
		$this->wpdb = Database::get_wpdb();
		$this->get_table();
	}

	// endregion

	// region METHODS

	/**
	 * Returns the object's rows under the key, in the order they were added.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int    $object_id The object's id.
	 * @param   string $key       The meta key, matched byte for byte, as the meta cache matches it.
	 *
	 * @throws  RuntimeException Thrown when the read fails.
	 *
	 * @return  list<MetaRow>
	 */
	public function list( int $object_id, string $key ): array {
		$wpdb  = $this->wpdb;
		$table = $this->get_table();

		$wpdb->flush();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT %i AS id, meta_key, meta_value FROM %i WHERE %i = %d AND meta_key = %s ORDER BY %i', $this->get_id_column(), $table, \sanitize_key( "{$this->meta_type}_id" ), $object_id, $key, $this->get_id_column() ) ?? '', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The meta API returns no meta ids.
		Database::check_query( $wpdb, "Could not list the '$key' meta rows of $this->meta_type $object_id" );

		$list = array();
		foreach ( (array) $rows as $row ) {
			$id  = $row['id'] ?? null;
			$raw = $row['meta_value'] ?? null;

			// The column's collation also matches keys that differ in letter case or accents.
			if ( \is_numeric( $id ) && ( $row['meta_key'] ?? null ) === $key ) {
				$list[] = new MetaRow( $table, (int) $id, $object_id, $key, \is_string( $raw ) ? \maybe_unserialize( $raw ) : null );
			}
		}

		return $list;
	}

	/**
	 * Adds a row to the object under the key, beside any rows already there, and returns it.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int    $object_id The object's id.
	 * @param   string $key       The meta key.
	 * @param   mixed  $value     The value: a scalar, null, or an array of them.
	 *
	 * @throws  InvalidArgumentException Thrown when the key is longer than 255 characters or the value holds anything but scalars, null and arrays.
	 * @throws  RuntimeException         Thrown when the row is not added.
	 *
	 * @return  MetaRow
	 */
	public function add( int $object_id, string $key, mixed $value ): MetaRow {
		if ( \mb_strlen( $key ) > self::MAX_KEY_LENGTH ) {
			throw new InvalidArgumentException( "Meta key '$key' is longer than 255 characters." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		// The meta API unslashes objects in place, which corrupts them and the caller's copy.
		if ( ! Database::is_storable( $value ) ) {
			throw new InvalidArgumentException( "The '$key' meta value holds something other than scalars, null and arrays." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		$table = $this->get_table();
		$id    = \add_metadata( $this->meta_type, $object_id, \wp_slash( $key ), \wp_slash( $value ) );
		if ( false === $id ) {
			throw new RuntimeException( "Could not add a '$key' meta row to $this->meta_type $object_id." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		return new MetaRow( $table, $id, $object_id, $key, $value );
	}

	/**
	 * Deletes exactly the observed row and returns true, or returns false when it is gone.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   MetaRow $observed The row as it was read or added.
	 *
	 * @throws  InvalidArgumentException Thrown when the row belongs to another table, of another meta type or of a site switched away from since.
	 * @throws  RuntimeException         Thrown when the delete fails.
	 *
	 * @return  bool
	 */
	public function delete( MetaRow $observed ): bool {
		$wpdb = $this->wpdb;

		// Meta ids repeat across the per-site tables, so a row read on another site would name an unrelated row here.
		$table = $this->get_table();
		if ( $observed->table !== $table ) {
			throw new InvalidArgumentException( "Meta row $observed->id of '$observed->table' is not a row of '$table'." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		// A gone row and a failed delete both make delete_metadata_by_mid() return false, so only the error tells them apart.
		$wpdb->flush();
		$deleted = \delete_metadata_by_mid( $this->meta_type, $observed->id );
		Database::check_query( $wpdb, "Could not delete meta row $observed->id of '$table'" );

		return $deleted;
	}

	/**
	 * Adds a row to the object under the key, then deletes each of the rows it replaces, and returns the new row.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @internal
	 *
	 * @param   int       $object_id The object's id.
	 * @param   string    $key       The meta key.
	 * @param   mixed     $value     The value: a scalar, null, or an array of them.
	 * @param   MetaRow[] $replaced  The rows the new one replaces, as they were read.
	 *
	 * @throws  InvalidArgumentException Thrown when the key or the value is invalid, or a replaced row belongs to another table.
	 * @throws  RuntimeException         Thrown when the row is not added or a replaced row cannot be deleted.
	 *
	 * @return  MetaRow
	 */
	public function supersede( int $object_id, string $key, mixed $value, array $replaced ): MetaRow {
		$row = $this->add( $object_id, $key, $value );
		foreach ( $replaced as $old ) {
			$this->delete( $old );
		}

		return $row;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the current site's table of the meta type.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  InvalidArgumentException Thrown when WordPress keeps no metadata of the type.
	 *
	 * @return  string
	 */
	protected function get_table(): string {
		$table = \_get_meta_table( $this->meta_type );
		if ( false === $table ) {
			throw new InvalidArgumentException( "WordPress keeps no '$this->meta_type' metadata." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		return $table;
	}

	/**
	 * Returns the meta id column of the meta type's table.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  string
	 */
	protected function get_id_column(): string {
		return 'user' === $this->meta_type ? 'umeta_id' : 'meta_id';
	}

	// endregion
}
