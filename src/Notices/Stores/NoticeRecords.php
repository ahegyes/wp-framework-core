<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Notices\Stores;

use DeepWebSolutions\Framework\Notices\Notice;
use DeepWebSolutions\Framework\Notices\NoticeContext;
use DeepWebSolutions\Framework\Notices\StoredNotice;

\defined( 'ABSPATH' ) || exit;

/**
 * Encodes, decodes, ages and orders the records of the default notice stores, so every store keeps one row format and one order.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @internal
 */
final class NoticeRecords {
	// region METHODS

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

	// endregion
}
