<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Notices\Internal;

use DeepWebSolutions\Framework\Notices\Notice;
use DeepWebSolutions\Framework\Notices\NoticeContext;
use DeepWebSolutions\Framework\Notices\NoticeScope;
use DeepWebSolutions\Framework\Notices\NoticeStoreInterface;
use DeepWebSolutions\Framework\Notices\StoredNotice;
use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use DeepWebSolutions\Framework\Shared\Exception\LogicException;
use DeepWebSolutions\Framework\Shared\Exception\RuntimeException;

\defined( 'ABSPATH' ) || exit;

/**
 * Keeps user notices as user meta rows under a key prefixed for the notice's site, one row per notice.
 *
 * Saving inserts a row before it deletes the rows it replaces, and every delete names the exact row it read, so writes to different notices never collide and a concurrent replacement survives. When two saves of one notice race, both rows remain; reads return the newer and delete the older, and delete both once the newer has expired.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @internal
 */
final class UserNoticeStore implements NoticeStoreInterface {
	// region FIELDS AND CONSTANTS

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
	 * @param   string $prefix The plugin's prefix for storage keys.
	 *
	 * @throws  LogicException Thrown when WordPress has no database connection.
	 */
	public function __construct(
		protected string $prefix
	) {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		if ( ! $wpdb instanceof \wpdb ) {
			throw new LogicException( 'The notice stores need a wpdb connection.' );
		}

		$this->wpdb = $wpdb;
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
	 * @throws  RuntimeException         Thrown when the row cannot be added.
	 */
	#[\Override]
	public function save( Notice $notice, NoticeContext $context ): StoredNotice {
		$key = $this->get_meta_key( $context );
		if ( $notice->scope !== $context->scope ) {
			throw new InvalidArgumentException( "A {$notice->scope->value} notice does not belong in a user context." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		$replaced = \array_filter( $this->get_rows( $context ), static fn ( mixed $row ): bool => \is_array( $row ) && ( $row['id'] ?? null ) === $notice->id );
		$record   = new StoredNotice( $notice, $context, NoticeRecords::now(), \wp_generate_uuid4() );
		if ( false === \add_user_meta( $context->owner, $key, \wp_slash( NoticeRecords::encode( $record ) ) ) ) {
			throw new RuntimeException( "Could not save user notice '$notice->id' for user $context->owner." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		foreach ( $replaced as $row ) {
			\delete_user_meta( $context->owner, $key, \wp_slash( $row ) );
		}

		return $record;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function find( string $id, NoticeContext $context ): ?StoredNotice {
		return \array_find( $this->all( $context ), static fn ( StoredNotice $record ): bool => $record->notice->id === $id );
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
		foreach ( $this->get_rows( $context ) as $row ) {
			$record = NoticeRecords::decode( $row, $context );
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

		$key = $this->get_meta_key( $context );
		foreach ( $stale as $row ) {
			\delete_user_meta( $context->owner, $key, \wp_slash( $row ) );
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
		$key = $this->get_meta_key( $observed->context );
		$row = \array_find( $this->get_rows( $observed->context ), static fn ( mixed $row ): bool => \is_array( $row ) && ( $row['gen'] ?? null ) === $observed->generation );

		return null !== $row && \delete_user_meta( $observed->context->owner, $key, \wp_slash( $row ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function remove( string $id, NoticeContext $context ): void {
		$key = $this->get_meta_key( $context );
		foreach ( $this->get_rows( $context ) as $row ) {
			if ( \is_array( $row ) && ( $row['id'] ?? null ) === $id ) {
				\delete_user_meta( $context->owner, $key, \wp_slash( $row ) );
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
		$wpdb = $this->wpdb;

		if ( NoticeScope::User !== $context->scope ) {
			throw new InvalidArgumentException( "The user store keeps no {$context->scope->value} notices." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		return $wpdb->get_blog_prefix( $context->site_id ) . "{$this->prefix}_notices";
	}

	/**
	 * Returns the user's rows under the context's key, in the order they were written.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeContext $context The context.
	 *
	 * @return  list<mixed>
	 */
	protected function get_rows( NoticeContext $context ): array {
		$rows = \get_user_meta( $context->owner, $this->get_meta_key( $context ), false );

		return \is_array( $rows ) ? \array_values( $rows ) : array();
	}

	// endregion
}
