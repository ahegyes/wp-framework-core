<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\AdminNotice\Stores;

use DeepWebSolutions\Framework\Core\AdminNotice\NoticeIdentity;
use DeepWebSolutions\Framework\Core\AdminNotice\NoticeScope;
use DeepWebSolutions\Framework\Core\AdminNotice\RecipientState;
use DeepWebSolutions\Framework\Core\AdminNotice\RecipientStateStoreInterface;
use DeepWebSolutions\Framework\Core\Storage\MetaRow;
use DeepWebSolutions\Framework\Core\Storage\MetaRows;
use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use DeepWebSolutions\Framework\Shared\Exception\LogicException;
use DeepWebSolutions\Framework\Shared\Exception\RuntimeException;

\defined( 'ABSPATH' ) || exit;

/**
 * Keeps recipient states as user meta rows, one per notice a user has interacted with, under a per-site key or, for network notices, a network-wide key.
 *
 * Recording adds the new row before it deletes the rows it read for the same notice, so two concurrent records never delete each other's row; the newest row wins on read.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @internal
 */
final class UserMetaRecipientStates implements RecipientStateStoreInterface {
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
		$wpdb = $GLOBALS['wpdb'] ?? null;
		if ( ! $wpdb instanceof \wpdb ) {
			throw new LogicException( 'The notice stores need a wpdb connection.' );
		}

		$this->wpdb = $wpdb;
		$this->rows = new MetaRows( 'user' );
	}

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function get( NoticeIdentity $identity, int $user_id ): ?RecipientState {
		$state = null;
		foreach ( $this->get_rows( $identity, $user_id ) as $row ) {
			$state = RecipientState::tryFrom( $row['state'] );
		}

		return $state;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  RuntimeException Thrown when the row cannot be added or a replaced row cannot be deleted.
	 */
	#[\Override]
	public function record( NoticeIdentity $identity, int $user_id, RecipientState $state ): bool {
		$replaced = array();
		$current  = null;
		foreach ( $this->get_rows( $identity, $user_id ) as $row ) {
			$replaced[] = $row['row'];
			$current    = $row['state'];
		}

		if ( $state->value === $current ) {
			return false;
		}

		$this->rows->add(
			$user_id,
			$this->get_meta_key( $identity ),
			array(
				'v'      => 1,
				'notice' => $identity->to_key(),
				'state'  => $state->value,
				'at'     => \time(),
			)
		);

		foreach ( $replaced as $old ) {
			$this->rows->delete( $old );
		}

		return true;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the user meta key that keeps the user's states for the identity.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeIdentity $identity The notice identity.
	 *
	 * @return  string
	 */
	protected function get_meta_key( NoticeIdentity $identity ): string {
		$wpdb = $this->wpdb;

		$context = $identity->context;
		$site_id = match ( $context->scope ) {
			NoticeScope::Request => \get_current_blog_id(),
			NoticeScope::User    => $context->site_id,
			NoticeScope::Site    => $context->owner,
			NoticeScope::Network => null,
		};

		return null === $site_id ? "{$this->prefix}_network_notice_states" : $wpdb->get_blog_prefix( $site_id ) . "{$this->prefix}_notice_states";
	}

	/**
	 * Returns the user's valid rows for the identity, oldest first.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeIdentity $identity The notice identity.
	 * @param   int            $user_id  The user.
	 *
	 * @throws  InvalidArgumentException Thrown when the user id is below 1.
	 *
	 * @return  list<array{row: MetaRow, state: string}>
	 */
	protected function get_rows( NoticeIdentity $identity, int $user_id ): array {
		if ( $user_id < 1 ) {
			throw new InvalidArgumentException( "User id $user_id is below 1." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		$notice = $identity->to_key();
		$rows   = array();
		foreach ( $this->rows->list( $user_id, $this->get_meta_key( $identity ) ) as $row ) {
			$value = $row->value;
			if ( \is_array( $value ) && 1 === ( $value['v'] ?? null ) && ( $value['notice'] ?? null ) === $notice && \is_string( $value['state'] ?? null ) && \is_int( $value['at'] ?? null ) ) {
				$rows[] = array(
					'row'   => $row,
					'state' => $value['state'],
				);
			}
		}

		return $rows;
	}

	// endregion
}
