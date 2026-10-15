<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Notices\Internal;

use DeepWebSolutions\Framework\Notices\NoticeIdentity;
use DeepWebSolutions\Framework\Notices\NoticeScope;
use DeepWebSolutions\Framework\Notices\RecipientState;
use DeepWebSolutions\Framework\Notices\RecipientStateStoreInterface;
use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use DeepWebSolutions\Framework\Shared\Exception\LogicException;
use DeepWebSolutions\Framework\Shared\Exception\RuntimeException;

\defined( 'ABSPATH' ) || exit;

/**
 * Keeps recipient states as user meta rows, one per notice a user has interacted with: under a key prefixed for the notice's site, or for a request notice the current site, and under an unprefixed key for network notices.
 *
 * Recording inserts the new row before it deletes the rows it read for the same notice, so two concurrent records never delete each other's row; the newest row wins on read.
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
	 */
	#[\Override]
	public function get( NoticeIdentity $identity, int $user_id ): ?RecipientState {
		$key   = $identity->to_key();
		$state = null;
		foreach ( $this->get_rows( $identity, $user_id ) as $row ) {
			if ( $key === $row['key'] ) {
				$state = RecipientState::tryFrom( $row['state'] );
			}
		}

		return $state;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  RuntimeException Thrown when the row cannot be added.
	 */
	#[\Override]
	public function record( NoticeIdentity $identity, int $user_id, RecipientState $state ): bool {
		$key      = $identity->to_key();
		$notice   = \strstr( $key, '@', true );
		$replaced = array();
		$current  = null;
		foreach ( $this->get_rows( $identity, $user_id ) as $row ) {
			$at = \strrpos( $row['key'], '@' );
			if ( false !== $at && \substr( $row['key'], 0, $at ) === $notice && (int) \substr( $row['key'], $at + 1 ) <= $identity->revision ) {
				$replaced[] = $row;
				$current    = $key === $row['key'] ? $row['state'] : $current;
			}
		}

		if ( $state->value === $current ) {
			return false;
		}

		$meta_key = $this->get_meta_key( $identity );
		$row      = array(
			'v'     => 1,
			'key'   => $key,
			'state' => $state->value,
			'at'    => \time(),
		);
		if ( false === \add_user_meta( $user_id, $meta_key, \wp_slash( $row ) ) ) {
			throw new RuntimeException( "Could not record the state of notice '$key' for user $user_id." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		foreach ( $replaced as $old ) {
			\delete_user_meta( $user_id, $meta_key, \wp_slash( $old ) );
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

		return ( null === $site_id ? '' : $wpdb->get_blog_prefix( $site_id ) ) . "{$this->prefix}_notice_states";
	}

	/**
	 * Returns the user's valid version 1 rows under the identity's key, in the order they were written.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeIdentity $identity The notice identity.
	 * @param   int            $user_id  The user.
	 *
	 * @throws  InvalidArgumentException Thrown when the user id is below 1.
	 *
	 * @return  list<array{v: 1, key: string, state: string, at: int}>
	 */
	protected function get_rows( NoticeIdentity $identity, int $user_id ): array {
		if ( $user_id < 1 ) {
			throw new InvalidArgumentException( "User id $user_id is below 1." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		$rows = \get_user_meta( $user_id, $this->get_meta_key( $identity ), false );

		return \array_values(
			\array_filter(
				\is_array( $rows ) ? $rows : array(),
				static fn ( mixed $row ): bool => \is_array( $row ) && 1 === ( $row['v'] ?? null ) && \is_string( $row['key'] ?? null ) && \is_string( $row['state'] ?? null ) && \is_int( $row['at'] ?? null )
			)
		);
	}

	// endregion
}
