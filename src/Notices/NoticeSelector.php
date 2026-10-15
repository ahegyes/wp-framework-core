<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Notices;

use Psr\Log\LoggerInterface as PsrLoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Selects the notices the current user sees on the current admin surface and screen, and acknowledges the one-shots shown.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @internal
 */
final class NoticeSelector {
	// region FIELDS AND CONSTANTS

	/**
	 * The scopes in the order their notices show.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     list<NoticeScope>
	 */
	protected const array ORDER = array( NoticeScope::Network, NoticeScope::Site, NoticeScope::User, NoticeScope::Request );

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string                                             $prefix The plugin's prefix for hook names.
	 * @param   PsrLoggerInterface                                 $logger The logger that records store failures.
	 * @param   array<value-of<NoticeScope>, NoticeStoreInterface> $stores The content store of each scope.
	 * @param   RecipientStateStoreInterface                       $states The recipient-state store.
	 */
	public function __construct(
		protected string $prefix,
		protected PsrLoggerInterface $logger,
		protected array $stores,
		protected RecipientStateStoreInterface $states
	) {}

	// endregion

	// region METHODS

	/**
	 * Returns the notices the current user sees here, in scope order and oldest first within a scope.
	 *
	 * A scope whose store fails is logged and left out.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  list<StoredNotice>
	 */
	public function select(): array {
		$user_id = \get_current_user_id();
		if ( 0 === $user_id ) {
			return array();
		}

		$selected = array();
		foreach ( self::ORDER as $scope ) {
			if ( ! self::shows_here( $scope ) ) {
				continue;
			}

			try {
				foreach ( $this->stores[ $scope->value ]->all( NoticeContext::current( $scope ) ) as $record ) {
					if ( $this->is_visible( $record, $user_id ) ) {
						$selected[] = $record;
					}
				}
			} catch ( \Throwable $exception ) {
				$this->log( 'Could not select the {scope} notices: {message}', $scope, $exception );
			}
		}

		return $selected;
	}

	/**
	 * Records that the current user was shown a one-shot notice, consuming it unless other users still need to see it.
	 *
	 * A failed write is logged, so the notice may show again.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   StoredNotice $record The notice as it was selected.
	 */
	public function acknowledge( StoredNotice $record ): void {
		$notice = $record->notice;
		if ( $notice->persistent ) {
			return;
		}

		try {
			if ( NoticeScope::Request === $notice->scope || NoticeScope::User === $notice->scope ) {
				$this->stores[ $notice->scope->value ]->consume( $record );
			} else {
				$this->states->record( new NoticeIdentity( $record->context, $notice->id, $notice->revision ), \get_current_user_id(), RecipientState::Delivered );
			}
		} catch ( \Throwable $exception ) {
			$this->log( 'Could not acknowledge a {scope} notice: {message}', $notice->scope, $exception );
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Returns whether the scope's notices show on the current admin surface.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeScope $scope The scope.
	 *
	 * @return  bool
	 */
	protected static function shows_here( NoticeScope $scope ): bool {
		return match ( true ) {
			\is_network_admin() => NoticeScope::Request === $scope || NoticeScope::Network === $scope,
			\is_user_admin()    => NoticeScope::Request === $scope,
			default             => \is_blog_admin(),
		};
	}

	/**
	 * Returns whether the notice shows to the user on the current screen.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   StoredNotice $record  The notice.
	 * @param   int          $user_id The current user.
	 *
	 * @return  bool
	 */
	protected function is_visible( StoredNotice $record, int $user_id ): bool {
		$notice = $record->notice;
		if ( array() !== $notice->screens && ! \in_array( \get_current_screen()?->id, $notice->screens, true ) ) {
			return false;
		}

		if ( null !== $notice->capability && ! \current_user_can( $notice->capability ) ) {
			return false;
		}

		$state = $this->states->get( new NoticeIdentity( $record->context, $notice->id, $notice->revision ), $user_id );
		if ( RecipientState::Dismissed === $state || ( RecipientState::Delivered === $state && ! $notice->persistent ) ) {
			return false;
		}

		/**
		 * Filters whether a notice shows to the current user, once its screens, its capability and the user's state allow it.
		 *
		 * @since   2.0.0
		 * @version 2.0.0
		 *
		 * @param   bool   $visible  Whether the notice shows; only true shows it.
		 * @param   string $id       The notice's id.
		 * @param   string $scope    The notice's scope.
		 * @param   int    $revision The notice's revision.
		 */
		return true === \apply_filters( "{$this->prefix}_notice_visible", true, $notice->id, $notice->scope->value, $notice->revision );
	}

	/**
	 * Logs a store failure as an error.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string      $message   The message, with {scope} and {message} placeholders.
	 * @param   NoticeScope $scope     The scope whose store failed.
	 * @param   \Throwable  $exception The failure.
	 */
	protected function log( string $message, NoticeScope $scope, \Throwable $exception ): void {
		$this->logger->error(
			$message,
			array(
				'scope'     => $scope->value,
				'message'   => $exception->getMessage(),
				'exception' => $exception,
			)
		);
	}

	// endregion
}
