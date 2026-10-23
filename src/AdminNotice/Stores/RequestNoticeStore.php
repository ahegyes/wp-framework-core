<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\AdminNotice\Stores;

use DeepWebSolutions\Framework\Core\AdminNotice\Notice;
use DeepWebSolutions\Framework\Core\AdminNotice\NoticeContext;
use DeepWebSolutions\Framework\Core\AdminNotice\NoticeScope;
use DeepWebSolutions\Framework\Core\AdminNotice\NoticeStoreInterface;
use DeepWebSolutions\Framework\Core\AdminNotice\StoredNotice;
use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;

\defined( 'ABSPATH' ) || exit;

/**
 * Keeps request notices in memory for the rest of the request, deleting an expired one when it reads it.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @internal
 */
final class RequestNoticeStore implements NoticeStoreInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * The records, by notice id.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     array<string, StoredNotice>
	 */
	protected array $records = array();

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  InvalidArgumentException Thrown when the notice is not a request notice.
	 */
	#[\Override]
	public function save( Notice $notice, NoticeContext $context ): StoredNotice {
		$this->check_scope( $context );
		$this->records[ $notice->id ] = NoticeRecords::create( $notice, $context );

		return $this->records[ $notice->id ];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function all( NoticeContext $context ): array {
		$this->check_scope( $context );
		foreach ( $this->records as $record ) {
			if ( NoticeRecords::is_expired( $record ) ) {
				$this->consume( $record );
			}
		}

		return NoticeRecords::sort( $this->records );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function consume( StoredNotice $observed ): bool {
		$this->check_scope( $observed->context );
		if ( ( $this->records[ $observed->notice->id ] ?? null )?->generation !== $observed->generation ) {
			return false;
		}

		unset( $this->records[ $observed->notice->id ] );

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function remove( string $id, NoticeContext $context ): void {
		$this->check_scope( $context );
		unset( $this->records[ $id ] );
	}

	// endregion

	// region HELPERS

	/**
	 * Throws unless the context is the request's.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeContext $context The context.
	 *
	 * @throws  InvalidArgumentException Thrown when the context is of another scope.
	 */
	protected function check_scope( NoticeContext $context ): void {
		if ( NoticeScope::Request !== $context->scope ) {
			throw new InvalidArgumentException( "The request store keeps no {$context->scope->value} notices." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}
	}

	// endregion
}
