<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\AdminNotice;

use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use DeepWebSolutions\Framework\Shared\Exception\RuntimeException;

\defined( 'ABSPATH' ) || exit;

/**
 * Keeps the content of the notices of one or more scopes, one record per notice id in each context.
 *
 * A store skips a record it cannot decode and leaves it in place.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
interface NoticeStoreInterface {
	/**
	 * Saves a notice and returns its record, which replaces any record of the same id in the context only once the new one is written, so a failed write keeps the previous one.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   Notice        $notice  The notice.
	 * @param   NoticeContext $context The context the notice belongs to, of the notice's scope.
	 *
	 * @throws  InvalidArgumentException Thrown when the context is not of the notice's scope or the store does not keep that scope.
	 * @throws  RuntimeException         Thrown when the write fails.
	 *
	 * @return  StoredNotice
	 */
	public function save( Notice $notice, NoticeContext $context ): StoredNotice;

	/**
	 * Returns the record of the notice with the given id in the context, or null when there is none or the notice has expired.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string        $id      The notice's id.
	 * @param   NoticeContext $context The context.
	 *
	 * @throws  InvalidArgumentException Thrown when the store does not keep the context's scope.
	 * @throws  RuntimeException         Thrown when the store cannot be read.
	 *
	 * @return  StoredNotice|null
	 */
	public function find( string $id, NoticeContext $context ): ?StoredNotice;

	/**
	 * Returns the records of the notices in the context, oldest first and by id among equals, leaving out expired notices.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeContext $context The context.
	 *
	 * @throws  InvalidArgumentException Thrown when the store does not keep the context's scope.
	 * @throws  RuntimeException         Thrown when the store cannot be read.
	 *
	 * @return  list<StoredNotice>
	 */
	public function all( NoticeContext $context ): array;

	/**
	 * Deletes exactly the observed record and returns true, or returns false when the record has been replaced or deleted since, so a notice saved in between survives.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   StoredNotice $observed The record as it was read.
	 *
	 * @throws  InvalidArgumentException Thrown when the store does not keep the record's scope.
	 * @throws  RuntimeException         Thrown when the store fails to read or delete the record.
	 *
	 * @return  bool
	 */
	public function consume( StoredNotice $observed ): bool;

	/**
	 * Deletes every record of the notice with the given id in the context, whatever it holds.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string        $id      The notice's id.
	 * @param   NoticeContext $context The context.
	 *
	 * @throws  InvalidArgumentException Thrown when the store does not keep the context's scope.
	 * @throws  RuntimeException         Thrown when the delete fails.
	 */
	public function remove( string $id, NoticeContext $context ): void;
}
