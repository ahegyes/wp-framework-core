<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Notices;

use DeepWebSolutions\Framework\ComponentInterface;
use DeepWebSolutions\Framework\Notices\Stores\OptionNoticeStore;
use DeepWebSolutions\Framework\Notices\Stores\RequestNoticeStore;
use DeepWebSolutions\Framework\Notices\Stores\UserMetaRecipientStates;
use DeepWebSolutions\Framework\Notices\Stores\UserNoticeStore;
use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use DeepWebSolutions\Framework\Shared\Exception\LogicException;
use DeepWebSolutions\Framework\Shared\Exception\RuntimeException;
use Psr\Log\LoggerInterface as PsrLoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Keeps a plugin's admin notices, shows them in the admin and lets users dismiss them through a REST endpoint.
 *
 * The prefix names the plugin's storage keys, hooks and REST namespace. Request notices live in the instance, so components that add them must share it.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class AdminNotices implements ComponentInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * The pattern every prefix matches.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     string
	 */
	protected const string PREFIX_PATTERN = '/\A[a-z][a-z0-9_]{0,31}\z/';

	/**
	 * The segments a prefix may not contain, because storage names append them to prefixes, so such a prefix could name another plugin's rows.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     list<string>
	 */
	protected const array RESERVED_SEGMENTS = array( 'notice', 'network' );

	/**
	 * The content store of each scope.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     array<value-of<NoticeScope>, NoticeStoreInterface>
	 */
	protected array $stores = array();

	/**
	 * The recipient-state store.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     RecipientStateStoreInterface
	 */
	protected RecipientStateStoreInterface $states;

	/**
	 * The selection and acknowledgement of the notices to show.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     NoticeSelector
	 */
	protected NoticeSelector $selector;

	/**
	 * Whether this request showed a notice that carries a dismissal nonce.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     bool
	 */
	protected bool $needs_dismiss_script = false;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string                                             $prefix The plugin's prefix, matching PREFIX_PATTERN without a reserved segment.
	 * @param   PsrLoggerInterface                                 $logger The logger that records store failures.
	 * @param   array<value-of<NoticeScope>, NoticeStoreInterface> $stores Optional. The content stores of the scopes that do not use the default stores. Default none.
	 * @param   RecipientStateStoreInterface|null                  $states Optional. The recipient-state store. Default is the user meta store.
	 *
	 * @throws  InvalidArgumentException Thrown when the prefix is invalid.
	 * @throws  LogicException           Thrown when WordPress has no database connection for a default store.
	 */
	public function __construct(
		protected string $prefix,
		protected PsrLoggerInterface $logger,
		array $stores = array(),
		?RecipientStateStoreInterface $states = null
	) {
		if ( 1 !== \preg_match( self::PREFIX_PATTERN, $prefix ) || array() !== \array_intersect( \explode( '_', $prefix ), self::RESERVED_SEGMENTS ) ) {
			throw new InvalidArgumentException( "Notices prefix '$prefix' is not a lowercase letter followed by up to 31 lowercase letters, digits and underscores, or it has a 'notice' or 'network' segment." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		$options = null;
		foreach ( NoticeScope::cases() as $scope ) {
			$this->stores[ $scope->value ] = $stores[ $scope->value ] ?? match ( $scope ) {
				NoticeScope::Request => new RequestNoticeStore(),
				NoticeScope::User    => new UserNoticeStore( $prefix ),
				default              => $options ??= new OptionNoticeStore( $prefix ),
			};
		}

		$this->states   = $states ?? new UserMetaRecipientStates( $prefix );
		$this->selector = new NoticeSelector( $prefix, $logger, $this->stores, $this->states );
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
	public function register_hooks(): void {
		\add_action( 'all_admin_notices', array( $this, 'render_notices' ) );
		\add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		\add_action( 'admin_footer', array( $this, 'print_dismiss_script' ) );
	}

	// endregion

	// region METHODS

	/**
	 * Saves a notice in its scope's store for the current context and returns its record.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   Notice $notice  The notice.
	 * @param   int    $user_id Optional. The user of a user notice, where 0 is the current user. Default 0.
	 *
	 * @throws  InvalidArgumentException Thrown when a user is given for a notice of another scope.
	 * @throws  LogicException           Thrown when a user notice has no user and nobody is logged in.
	 * @throws  RuntimeException         Thrown when the write fails.
	 *
	 * @return  StoredNotice
	 */
	public function add( Notice $notice, int $user_id = 0 ): StoredNotice {
		if ( NoticeScope::Request === $notice->scope && 0 < \did_action( 'all_admin_notices' ) ) {
			\_doing_it_wrong( __METHOD__, \esc_html( "Request notice '$notice->id' was added after the admin notices showed, so it does not show." ), '2.0.0' );
		}

		return $this->stores[ $notice->scope->value ]->save( $notice, NoticeContext::current( $notice->scope, $user_id ) );
	}

	/**
	 * Deletes every record of the notice with the given id in its scope's store for the current context.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string      $id      The notice's id.
	 * @param   NoticeScope $scope   The notice's scope.
	 * @param   int         $user_id Optional. The user of a user notice, where 0 is the current user. Default 0.
	 *
	 * @throws  InvalidArgumentException Thrown when a user is given for another scope.
	 * @throws  LogicException           Thrown when a user notice has no user and nobody is logged in.
	 * @throws  RuntimeException         Thrown when the delete fails.
	 */
	public function remove( string $id, NoticeScope $scope, int $user_id = 0 ): void {
		$this->stores[ $scope->value ]->remove( $id, NoticeContext::current( $scope, $user_id ) );
	}

	/**
	 * Returns the scope's unexpired notices for the current context, oldest first.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeScope $scope   The scope.
	 * @param   int         $user_id Optional. The user of user notices, where 0 is the current user. Default 0.
	 *
	 * @throws  InvalidArgumentException Thrown when a user is given for another scope.
	 * @throws  LogicException           Thrown when user notices have no user and nobody is logged in.
	 * @throws  RuntimeException         Thrown when the store cannot be read.
	 *
	 * @return  list<StoredNotice>
	 */
	public function get_notices( NoticeScope $scope, int $user_id = 0 ): array {
		return $this->stores[ $scope->value ]->all( NoticeContext::current( $scope, $user_id ) );
	}

	// endregion

	// region HOOKS

	/**
	 * Prints the notices the current user sees here and acknowledges the one-shots among them.
	 *
	 * Block-editor screens print nothing, because the editors' styles hide classic notices.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function render_notices(): void {
		// The screen API loads only in the admin.
		if ( \function_exists( 'get_current_screen' ) && true === \get_current_screen()?->is_block_editor() ) {
			return;
		}

		foreach ( $this->selector->select() as $record ) {
			$this->print_notice( $record );
			$this->selector->acknowledge( $record );
		}
	}

	/**
	 * Registers the dismissal endpoint, POST {namespace}/notices/dismissals.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function register_rest_routes(): void {
		\register_rest_route(
			$this->get_rest_namespace(),
			'/notices/dismissals',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_dismiss' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array(
					'scope' => array(
						'type'     => 'string',
						'enum'     => \array_column( NoticeScope::cases(), 'value' ),
						'required' => true,
					),
					'id'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'nonce' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Prints the script that posts dismissals, when this request showed a notice that carries a dismissal nonce.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function print_dismiss_script(): void {
		if ( ! $this->needs_dismiss_script ) {
			return;
		}

		$settings = \wp_json_encode(
			array(
				'prefix'    => $this->prefix,
				'url'       => \rest_url( "{$this->get_rest_namespace()}/notices/dismissals" ),
				'restNonce' => \wp_create_nonce( 'wp_rest' ),
			)
		);

		\wp_print_inline_script_tag(
			<<<JS
			( function ( settings ) {
				document.addEventListener( 'click', function ( event ) {
					var button = event.target instanceof Element ? event.target.closest( '.notice-dismiss' ) : null;
					var notice = button ? button.closest( '[data-notice-nonce]' ) : null;
					if ( ! notice || notice.getAttribute( 'data-notices' ) !== settings.prefix ) {
						return;
					}

					fetch( settings.url, {
						method: 'POST',
						credentials: 'include',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': settings.restNonce },
						body: JSON.stringify( {
							scope: notice.getAttribute( 'data-notice-scope' ),
							id: notice.getAttribute( 'data-notice-id' ),
							nonce: notice.getAttribute( 'data-notice-nonce' )
						} )
					} ).then( function ( response ) {
						if ( ! response.ok ) {
							console.error( 'The notice dismissal failed with HTTP status ' + response.status + '.' );
						}
					} ).catch( function ( error ) {
						console.error( 'The notice dismissal failed.', error );
					} );
				} );
			} )( $settings );
			JS
		);
	}

	/**
	 * Records the current user's dismissal of a notice shown to them.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   \WP_REST_Request<array<string, mixed>> $request The request, with scope, id and nonce.
	 *
	 * @return  \WP_REST_Response|\WP_Error
	 */
	public function rest_dismiss( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params  = $request->get_params();
		$scope   = \is_string( $params['scope'] ?? null ) ? NoticeScope::tryFrom( $params['scope'] ) : null;
		$id      = $params['id'] ?? null;
		$nonce   = $params['nonce'] ?? null;
		$user_id = \get_current_user_id();

		if ( null === $scope || ! \is_string( $id ) || 1 !== \preg_match( Notice::ID_PATTERN, $id ) || ! \is_string( $nonce ) ) {
			return new \WP_Error( 'rest_invalid_param', 'The notice scope, id or nonce is invalid.', array( 'status' => 400 ) );
		}

		$identity = new NoticeIdentity( NoticeContext::current( $scope ), $id );
		if ( false === \wp_verify_nonce( $nonce, $this->get_nonce_action( $identity ) ) ) {
			return new \WP_Error( 'rest_forbidden', 'The nonce does not match a notice shown to this user.', array( 'status' => 403 ) );
		}

		try {
			if ( NoticeScope::Request !== $scope ) {
				$record = $this->stores[ $scope->value ]->find( $id, $identity->context );
				if ( null === $record ) {
					return new \WP_Error( 'rest_notice_invalid_id', 'No notice has that id.', array( 'status' => 404 ) );
				}

				$notice = $record->notice;
				if ( ! $notice->persistent || ! $notice->dismissible || ( null !== $notice->capability && ! \current_user_can( $notice->capability ) ) ) {
					return new \WP_Error( 'rest_forbidden', 'This user cannot dismiss that notice.', array( 'status' => 403 ) );
				}
			}

			$changed = $this->states->record( $identity, $user_id, RecipientState::Dismissed );
		} catch ( \Throwable $exception ) {
			$this->logger->error(
				'Could not dismiss a {scope} notice: {message}',
				array(
					'scope'     => $scope->value,
					'message'   => $exception->getMessage(),
					'exception' => $exception,
				)
			);

			return new \WP_Error( 'rest_notice_store_error', 'The notice store failed.', array( 'status' => 500 ) );
		}

		if ( $changed ) {
			/**
			 * Fires when a user's dismissal changed their state for a notice.
			 *
			 * @since   2.0.0
			 * @version 2.0.0
			 *
			 * @param   string $id      The notice's id.
			 * @param   string $scope   The notice's scope.
			 * @param   int    $user_id The user who dismissed it.
			 * @param   int    $owner   The notice's owner: 0 for a request notice, otherwise the user, blog or network id.
			 */
			\do_action( "{$this->prefix}_notice_dismissed", $id, $scope->value, $user_id, $identity->context->owner );
		}

		return new \WP_REST_Response( array( 'changed' => $changed ) );
	}

	// endregion

	// region HELPERS

	/**
	 * Prints a notice, with a dismissal nonce when it is persistent and dismissible.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   StoredNotice $record The notice.
	 */
	protected function print_notice( StoredNotice $record ): void {
		$notice     = $record->notice;
		$attributes = array(
			'data-notices'      => $this->prefix,
			'data-notice-scope' => $notice->scope->value,
			'data-notice-id'    => $notice->id,
		);
		if ( $notice->persistent && $notice->dismissible ) {
			$attributes['data-notice-nonce'] = \wp_create_nonce( $this->get_nonce_action( new NoticeIdentity( $record->context, $notice->id ) ) );
			$this->needs_dismiss_script      = true;
		}

		/**
		 * Filters a notice's message as it prints, such as to translate it for the viewer or add a link that needs a fresh nonce.
		 *
		 * @since   2.0.0
		 * @version 2.0.0
		 *
		 * @param   string $message The message, as HTML.
		 * @param   string $id      The notice's id.
		 * @param   string $scope   The notice's scope.
		 */
		$message = \apply_filters( "{$this->prefix}_notice_message", $notice->message, $notice->id, $notice->scope->value );

		\wp_admin_notice(
			\is_string( $message ) ? $message : $notice->message,
			array(
				'type'        => $notice->type->value,
				'dismissible' => $notice->dismissible,
				'id'          => "{$this->prefix}-{$notice->scope->value}-notice-{$notice->id}",
				'attributes'  => $attributes,
			)
		);
	}

	/**
	 * Returns the REST namespace.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  non-falsy-string
	 */
	protected function get_rest_namespace(): string {
		return \str_replace( '_', '-', $this->prefix ) . '/v1';
	}

	/**
	 * Returns the action of the identity's dismissal nonce.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeIdentity $identity The notice identity.
	 *
	 * @return  string
	 */
	protected function get_nonce_action( NoticeIdentity $identity ): string {
		return "{$this->prefix}_dismiss|{$identity->to_key()}";
	}

	// endregion
}
