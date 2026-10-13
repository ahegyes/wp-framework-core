<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Utilities;

use DeepWebSolutions\Framework\ComponentInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Queues admin notices for the current user on the current site and shows them on that user's next admin page.
 *
 * Each user sees only what was queued for them, so rendering checks no capability.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class NoticeQueue implements ComponentInterface {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $key The user option that holds the queue.
	 */
	public function __construct(
		protected string $key
	) {}

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function register_hooks(): void {
		\add_action( 'all_admin_notices', array( $this, 'render_notices' ) );
	}

	// endregion

	// region METHODS

	/**
	 * Queues a notice for the current user on the current site.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $message     The notice's HTML, which wp_admin_notice() passes through kses.
	 * @param   string $type        Optional. The notice's type. Default is 'error'.
	 * @param   bool   $dismissible Optional. Whether the user can dismiss the notice. Default is true.
	 */
	public function add( string $message, string $type = 'error', bool $dismissible = true ): void {
		$user_id = \get_current_user_id();
		$queue   = $this->get_queue( $user_id );
		$queue[] = array(
			'message'     => $message,
			'type'        => $type,
			'dismissible' => $dismissible,
		);

		\update_user_option( $user_id, $this->key, $queue );
	}

	// endregion

	// region HOOKS

	/**
	 * Shows the current user's queued notices, then removes the ones shown.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function render_notices(): void {
		$user_id = \get_current_user_id();
		$queue   = $this->get_queue( $user_id );
		if ( array() === $queue ) {
			return;
		}

		foreach ( $queue as $notice ) {
			\wp_admin_notice(
				$notice['message'],
				array(
					'type'        => $notice['type'],
					'dismissible' => $notice['dismissible'],
				)
			);
		}

		// A notice queued while these rendered sits after them, so it stays for the next page.
		$remaining = \array_slice( $this->get_queue( $user_id ), \count( $queue ) );
		if ( array() === $remaining ) {
			\delete_user_option( $user_id, $this->key );
		} else {
			\update_user_option( $user_id, $this->key, $remaining );
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the notices queued for a user on the current site.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int $user_id The user's ID.
	 *
	 * @return  list<array{message: string, type: string, dismissible: bool}>
	 */
	protected function get_queue( int $user_id ): array {
		$queue = \get_user_option( $this->key, $user_id );

		return \is_array( $queue ) ? $queue : array(); // @phpstan-ignore return.type (Only add() and render_notices() write this option.)
	}

	// endregion
}
