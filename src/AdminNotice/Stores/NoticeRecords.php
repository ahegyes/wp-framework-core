<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\AdminNotice\Stores;

use DeepWebSolutions\Framework\Core\AdminNotice\Notice;
use DeepWebSolutions\Framework\Core\AdminNotice\NoticeContext;
use DeepWebSolutions\Framework\Core\AdminNotice\StoredNotice;
use DeepWebSolutions\Framework\Core\Storage\Database;
use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use DeepWebSolutions\Framework\Shared\Exception\LogicException;

\defined( 'ABSPATH' ) || exit;

/**
 * Creates, encodes, decodes, ages and orders the records of the default notice stores, so every store keeps one row format and one order.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @internal
 */
final class NoticeRecords {
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
	 * Returns a new record of the notice in the context, created now under a new generation.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   Notice        $notice  The notice.
	 * @param   NoticeContext $context The context the notice belongs to.
	 *
	 * @throws  InvalidArgumentException Thrown when the notice is not of the context's scope.
	 *
	 * @return  StoredNotice
	 */
	public static function create( Notice $notice, NoticeContext $context ): StoredNotice {
		if ( $notice->scope !== $context->scope ) {
			throw new InvalidArgumentException( "A {$notice->scope->value} notice does not belong in a {$context->scope->value} context." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		return new StoredNotice( $notice, $context, self::now(), \wp_generate_uuid4() );
	}

	/**
	 * Returns the record as a row.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   StoredNotice $record The record.
	 *
	 * @return  array<string, mixed>
	 */
	public static function encode( StoredNotice $record ): array {
		return $record->notice->to_array() + array(
			'gen'        => $record->generation,
			'created_at' => $record->created_at,
		);
	}

	/**
	 * Returns the record a row describes in the context, or null when the row is not a valid record of the context's scope.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed         $row     The row as stored.
	 * @param   NoticeContext $context The context the row was read from.
	 *
	 * @return  StoredNotice|null
	 */
	public static function decode( mixed $row, NoticeContext $context ): ?StoredNotice {
		if ( ! \is_array( $row ) || ! \is_string( $row['gen'] ?? null ) || ! \is_int( $row['created_at'] ?? null ) ) {
			return null;
		}

		$notice = Notice::from_array( $row );

		return null !== $notice && $notice->scope === $context->scope ? new StoredNotice( $notice, $context, $row['created_at'], $row['gen'] ) : null;
	}

	/**
	 * Returns whether the row holds a record of the notice with the given id.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed  $row The row as stored.
	 * @param   string $id  The notice's id.
	 *
	 * @return  bool
	 */
	public static function has_id( mixed $row, string $id ): bool {
		return \is_array( $row ) && ( $row['id'] ?? null ) === $id;
	}

	/**
	 * Returns whether the row holds the record of the given generation.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed  $row        The row as stored.
	 * @param   string $generation The record's generation.
	 *
	 * @return  bool
	 */
	public static function has_generation( mixed $row, string $generation ): bool {
		return \is_array( $row ) && ( $row['gen'] ?? null ) === $generation;
	}

	/**
	 * Returns whether the record's notice has expired.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   StoredNotice $record The record.
	 *
	 * @return  bool
	 */
	public static function is_expired( StoredNotice $record ): bool {
		return null !== $record->notice->expires_at && $record->notice->expires_at <= \time();
	}

	/**
	 * Returns the records oldest first, and by notice id among records saved in the same microsecond.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   StoredNotice[] $records The records.
	 *
	 * @return  list<StoredNotice>
	 */
	public static function sort( array $records ): array {
		\usort( $records, static fn ( StoredNotice $a, StoredNotice $b ): int => array( $a->created_at, $a->notice->id ) <=> array( $b->created_at, $b->notice->id ) );

		return $records;
	}

	/**
	 * Returns the current Unix time in microseconds, which orders records.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  int
	 */
	public static function now(): int {
		return (int) ( \microtime( true ) * 1_000_000 );
	}

	/**
	 * Returns the user meta key that keeps the named rows for the site, under the site's table prefix, because every site of a network shares the user meta table.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int    $site_id The blog id.
	 * @param   string $name    The name of the rows, under the plugin's prefix.
	 *
	 * @throws  LogicException Thrown when WordPress has no database connection.
	 *
	 * @return  string
	 */
	public static function get_user_meta_key( int $site_id, string $name ): string {
		return Database::get_wpdb()->get_blog_prefix( $site_id ) . $name;
	}

	// endregion
}
