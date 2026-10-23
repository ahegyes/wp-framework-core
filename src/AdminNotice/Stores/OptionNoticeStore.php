<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\AdminNotice\Stores;

use DeepWebSolutions\Framework\Core\AdminNotice\Notice;
use DeepWebSolutions\Framework\Core\AdminNotice\NoticeContext;
use DeepWebSolutions\Framework\Core\AdminNotice\NoticeScope;
use DeepWebSolutions\Framework\Core\AdminNotice\NoticeStoreInterface;
use DeepWebSolutions\Framework\Core\AdminNotice\StoredNotice;
use DeepWebSolutions\Framework\Core\Storage\OptionRows;
use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use DeepWebSolutions\Framework\Shared\Exception\LogicException;

\defined( 'ABSPATH' ) || exit;

/**
 * Keeps site and network notices as one non-autoloaded option per notice: a site notice in its site's options table, and a network notice in the options table of the network's main site.
 *
 * The unique option name makes each save one atomic upsert, which network meta, lacking a unique key, cannot. Consuming a record, and deleting an expired one on read, names the exact row read, so a concurrent replacement survives.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @internal
 */
final class OptionNoticeStore implements NoticeStoreInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * The option rows of every site.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     OptionRows
	 */
	protected OptionRows $rows;

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
		$this->rows = new OptionRows();
	}

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  InvalidArgumentException Thrown when the notice is not of the context's scope.
	 */
	#[\Override]
	public function save( Notice $notice, NoticeContext $context ): StoredNotice {
		$name   = $this->get_option_prefix( $context ) . $notice->id;
		$record = NoticeRecords::create( $notice, $context );
		$this->rows->upsert( $name, NoticeRecords::encode( $record ), $this->get_site_id( $context ) );

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
		$prefix  = $this->get_option_prefix( $context );
		$records = array();
		foreach ( $this->rows->list( $prefix, $this->get_site_id( $context ) ) as $row ) {
			// A record must name its own option, so a plugin whose prefix extends this one's never shares its rows.
			$record = NoticeRecords::decode( $row->value, $context );
			if ( null === $record || $row->name !== $prefix . $record->notice->id ) {
				continue;
			}

			if ( NoticeRecords::is_expired( $record ) ) {
				$this->rows->delete( $row );
				continue;
			}

			$records[] = $record;
		}

		return NoticeRecords::sort( $records );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function consume( StoredNotice $observed ): bool {
		$row = $this->rows->get( $this->get_option_prefix( $observed->context ) . $observed->notice->id, $this->get_site_id( $observed->context ) );

		return null !== $row && NoticeRecords::has_generation( $row->value, $observed->generation ) && $this->rows->delete( $row );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function remove( string $id, NoticeContext $context ): void {
		$this->rows->remove( $this->get_option_prefix( $context ) . $id, $this->get_site_id( $context ) );
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the option-name prefix of the context's scope.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeContext $context The context.
	 *
	 * @throws  InvalidArgumentException Thrown when the context is neither a site's nor a network's.
	 *
	 * @return  string
	 */
	protected function get_option_prefix( NoticeContext $context ): string {
		return match ( $context->scope ) {
			NoticeScope::Site    => "{$this->prefix}_notice_",
			NoticeScope::Network => "{$this->prefix}_network_notice_",
			default              => throw new InvalidArgumentException( "The option store keeps no {$context->scope->value} notices." ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		};
	}

	/**
	 * Returns the blog id whose options table keeps the context's notices.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeContext $context The context.
	 *
	 * @return  int
	 */
	protected function get_site_id( NoticeContext $context ): int {
		return NoticeScope::Network === $context->scope ? \get_main_site_id( $context->owner ) : $context->owner;
	}

	// endregion
}
