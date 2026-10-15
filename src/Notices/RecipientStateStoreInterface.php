<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Notices;

use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use DeepWebSolutions\Framework\Shared\Exception\RuntimeException;

\defined( 'ABSPATH' ) || exit;

/**
 * Keeps what happened between each viewer and each notice identity, apart from the notice's content, so the content outlives a dismissal.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
interface RecipientStateStoreInterface {
	/**
	 * Returns the user's state for the identity, or null when none is recorded.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeIdentity $identity The notice identity, revision included.
	 * @param   int            $user_id  The user.
	 *
	 * @throws  InvalidArgumentException Thrown when the user id is below 1.
	 *
	 * @return  RecipientState|null
	 */
	public function get( NoticeIdentity $identity, int $user_id ): ?RecipientState;

	/**
	 * Records the user's state for the identity, replacing the user's states for that notice at the same or an earlier revision, and returns whether the state changed.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeIdentity $identity The notice identity, revision included.
	 * @param   int            $user_id  The user.
	 * @param   RecipientState $state    The state.
	 *
	 * @throws  InvalidArgumentException Thrown when the user id is below 1.
	 * @throws  RuntimeException         Thrown when the write fails.
	 *
	 * @return  bool
	 */
	public function record( NoticeIdentity $identity, int $user_id, RecipientState $state ): bool;
}
