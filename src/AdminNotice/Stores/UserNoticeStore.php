<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\AdminNotice\Stores;

use DeepWebSolutions\Framework\Core\AdminNotice\Notice;
use DeepWebSolutions\Framework\Core\AdminNotice\NoticeContext;
use DeepWebSolutions\Framework\Core\AdminNotice\NoticeScope;
use DeepWebSolutions\Framework\Core\AdminNotice\NoticeStoreInterface;
use DeepWebSolutions\Framework\Core\AdminNotice\StoredNotice;
use DeepWebSolutions\Framework\Core\Storage\MetaRow;
use DeepWebSolutions\Framework\Core\Storage\MetaRows;
use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use DeepWebSolutions\Framework\Shared\Exception\LogicException;
use DeepWebSolutions\Framework\Shared\Exception\RuntimeException;

\defined( 'ABSPATH' ) || exit;

/**
 * Keeps user notices as user meta rows under a key prefixed for the notice's site, one row per notice.
 *
 * Saving adds a row before it deletes the rows it replaces, and every delete names the exact row it read, so writes to different notices never collide and a concurrent replacement survives. When two saves of one notice race, both rows remain until a read keeps the newer and deletes the older.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @internal
 */
final class UserNoticeStore implements NoticeStoreInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * The user meta rows.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     MetaRows
	 */
	protected MetaRows $rows;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $prefix The plugin's prefix for storage keys.
	 *
	 * @throws  LogicException Thrown when WordPress has no database connection.
	 */
	public function __construct(
		protected string $prefix
	) {
		$this->rows = new MetaRows( 'user' );
	}

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  InvalidArgumentException Thrown when the notice is not a user notice.
	 * @throws  RuntimeException         Thrown when the row cannot be added or a replaced row cannot be deleted.
	 */
	#[\Override]
	public function save( Notice $notice, NoticeContext $context ): StoredNotice {
		$key      = $this->get_meta_key( $context );
		$record   = NoticeRecords::create( $notice, $context );
		$replaced = \array_filter( $this->rows->list( $context->owner, $key ), static fn ( MetaRow $row ): bool => NoticeRecords::has_id( $row->value, $notice->id ) );
		$this->rows->supersede( $context->owner, $key, NoticeRecords::encode( $record ), $replaced );

		return $record;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function all( NoticeContext $context ): array {
		$newest = array();
		$stale  = array();
		foreach ( $this->rows->list( $context->owner, $this->get_meta_key( $context ) ) as $row ) {
			$record = NoticeRecords::decode( $row->value, $context );
			if ( null === $record ) {
				continue;
			}

			$id      = $record->notice->id;
			$current = $newest[ $id ] ?? null;
			if ( null !== $current && $current['record']->created_at >= $record->created_at ) {
				$stale[] = $row;
				continue;
			}

			if ( null !== $current ) {
				$stale[] = $current['row'];
			}
			$newest[ $id ] = array(
				'record' => $record,
				'row'    => $row,
			);
		}

		// Expiry applies to the newest record only, so an older row of the same notice never takes its place.
		foreach ( $newest as $id => $current ) {
			if ( NoticeRecords::is_expired( $current['record'] ) ) {
				$stale[] = $current['row'];
				unset( $newest[ $id ] );
			}
		}

		foreach ( $stale as $row ) {
			$this->rows->delete( $row );
		}

		return NoticeRecords::sort( \array_column( $newest, 'record' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function consume( StoredNotice $observed ): bool {
		$row = \array_find( $this->rows->list( $observed->context->owner, $this->get_meta_key( $observed->context ) ), static fn ( MetaRow $row ): bool => NoticeRecords::has_generation( $row->value, $observed->generation ) );

		return null !== $row && $this->rows->delete( $row );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function remove( string $id, NoticeContext $context ): void {
		foreach ( $this->rows->list( $context->owner, $this->get_meta_key( $context ) ) as $row ) {
			if ( NoticeRecords::has_id( $row->value, $id ) ) {
				$this->rows->delete( $row );
			}
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the user meta key of the context's site.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeContext $context The context.
	 *
	 * @throws  InvalidArgumentException Thrown when the context is not a user's.
	 *
	 * @return  string
	 */
	protected function get_meta_key( NoticeContext $context ): string {
		if ( NoticeScope::User !== $context->scope ) {
			throw new InvalidArgumentException( "The user store keeps no {$context->scope->value} notices." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		return NoticeRecords::get_user_meta_key( $context->site_id, "{$this->prefix}_notices" );
	}

	// endregion
}
