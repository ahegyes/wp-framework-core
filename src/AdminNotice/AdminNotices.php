<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\AdminNotice;

use DeepWebSolutions\Framework\Core\ComponentInterface;
use DeepWebSolutions\Framework\Core\Logging\GuardedLogger;
use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use DeepWebSolutions\Framework\Shared\Exception\LogicException;
use Psr\Log\LoggerInterface as PsrLoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Keeps a plugin's admin notices, shows them in the admin and lets users dismiss them through a REST endpoint.
 *
 * The prefix names the plugin's storage keys, hooks and REST namespace. Each scope keeps its notices as one array by id: site notices in the {prefix}_notices option, network notices in the {prefix}_network_notices network option, user notices in the {prefix}_notices user option, and request notices in the instance, so components that add them must share it. A viewer's dismissals, and the one-shots shown to them that others still see, are kept in their {prefix}_hidden_notices user option, or for network notices in their {prefix}_network_hidden_notices user meta.
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
	 * The segments a prefix may not contain, because the storage keys put them after a prefix, so such a prefix could name another plugin's keys.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     list<string>
	 */
	protected const array RESERVED_SEGMENTS = array( 'network', 'hidden' );

	/**
	 * The scopes in the order their notices show.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     list<NoticeScope>
	 */
	protected const array ORDER = array( NoticeScope::Network, NoticeScope::Site, NoticeScope::User, NoticeScope::Request );

	/**
	 * The rows of the request notices, by id.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     array<mixed>
	 */
	protected array $request_notices = array();

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
	 * @param   string             $prefix The plugin's prefix, matching PREFIX_PATTERN without a reserved segment.
	 * @param   PsrLoggerInterface $logger The logger that records store failures.
	 *
	 * @throws  InvalidArgumentException Thrown when the prefix is invalid.
	 */
	public function __construct(
		protected string $prefix,
		protected PsrLoggerInterface $logger
	) {
		if ( 1 !== \preg_match( self::PREFIX_PATTERN, $prefix ) || array() !== \array_intersect( \explode( '_', $prefix ), self::RESERVED_SEGMENTS ) ) {
			throw new InvalidArgumentException( "Notices prefix '$prefix' is not a lowercase letter followed by up to 31 lowercase letters, digits and underscores, or it has a 'network' or 'hidden' segment." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		$this->logger = new GuardedLogger( $logger, $prefix );
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
		\add_action( 'admin_footer', array( $this, 'enqueue_dismiss_script' ) );
	}

	// endregion

	// region METHODS

	/**
	 * Saves a notice in its scope for the current site, network or user, replacing any notice of the same id there.
	 *
	 * A store that throws is logged, and the notice is lost.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   Notice $notice  The notice.
	 * @param   int    $user_id Optional. The user of a user notice, where 0 is the current user. Default 0.
	 *
	 * @throws  InvalidArgumentException Thrown when a user is given for a notice of another scope.
	 * @throws  LogicException           Thrown when a user notice has no user and nobody is logged in.
	 */
	public function add( Notice $notice, int $user_id = 0 ): void {
		if ( NoticeScope::Request === $notice->scope && 0 < \did_action( 'all_admin_notices' ) ) {
			\_doing_it_wrong( __METHOD__, \esc_html( "Request notice '$notice->id' was added after the admin notices showed, so it does not show." ), '2.0.0' );
		}

		$owner = $this->get_owner( $notice->scope, $user_id );
		try {
			$notices                = $this->read( $notice->scope, $owner );
			$notices[ $notice->id ] = $notice;
			$this->write( $notice->scope, $owner, $notices );
		} catch ( \Throwable $exception ) {
			$this->log( 'Could not add a {scope} notice: {message}', $notice->scope, $exception );
		}
	}

	/**
	 * Deletes the notice with the given id from its scope for the current site, network or user.
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
	 */
	public function remove( string $id, NoticeScope $scope, int $user_id = 0 ): void {
		$this->delete( $scope, $this->get_owner( $scope, $user_id ), $id );
	}

	/**
	 * Returns the scope's unexpired notices for the current site, network or user, in the order they were first added.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeScope $scope   The scope.
	 * @param   int         $user_id Optional. The user of user notices, where 0 is the current user. Default 0.
	 *
	 * @throws  InvalidArgumentException Thrown when a user is given for another scope.
	 * @throws  LogicException           Thrown when user notices have no user and nobody is logged in.
	 *
	 * @return  list<Notice>
	 */
	public function get_notices( NoticeScope $scope, int $user_id = 0 ): array {
		return \array_values( $this->read( $scope, $this->get_owner( $scope, $user_id ) ) );
	}

	/**
	 * Returns the notices the current user sees here, in scope order.
	 *
	 * A scope whose store throws is logged and left out.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  list<Notice>
	 */
	protected function select(): array {
		$user_id = \get_current_user_id();
		if ( 0 === $user_id ) {
			return array();
		}

		$selected = array();
		foreach ( self::ORDER as $scope ) {
			$shows_here = match ( true ) {
				\is_network_admin() => NoticeScope::Site !== $scope,
				\is_user_admin()    => NoticeScope::Request === $scope || NoticeScope::User === $scope,
				default             => \is_blog_admin(),
			};
			if ( ! $shows_here ) {
				continue;
			}

			try {
				$hidden = $this->get_hidden( $scope, $user_id );
				foreach ( $this->read( $scope, $this->get_owner( $scope, 0 ) ) as $notice ) {
					if ( array() !== $notice->screens && ! \in_array( \get_current_screen()?->id, $notice->screens, true ) ) {
						continue;
					}

					if ( null !== $notice->capability && ! \current_user_can( $notice->capability ) ) {
						continue;
					}

					$state = $notice->is_shared() ? null : ( $hidden[ "$scope->value:$notice->id" ]['state'] ?? null );
					if ( 'dismissed' === $state || ( 'delivered' === $state && ! $notice->persistent ) ) {
						continue;
					}

					/**
					 * Filters whether a notice shows to the current user, once its screens, its capability and the user's dismissal allow it.
					 *
					 * @since   2.0.0
					 * @version 2.0.0
					 *
					 * @param   bool   $visible Whether the notice shows; only true shows it.
					 * @param   string $id      The notice's id.
					 * @param   string $scope   The notice's scope.
					 */
					if ( true === \apply_filters( "{$this->prefix}_notice_visible", true, $notice->id, $scope->value ) ) {
						$selected[] = $notice;
					}
				}
			} catch ( \Throwable $exception ) {
				$this->log( 'Could not select the {scope} notices: {message}', $scope, $exception );
			}
		}

		return $selected;
	}

	/**
	 * Records that the current user was shown a one-shot notice, deleting it unless other viewers still need to see it.
	 *
	 * A store that throws is logged, so the notice may show again.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   Notice $notice The notice as it was selected.
	 */
	protected function acknowledge( Notice $notice ): void {
		if ( $notice->persistent ) {
			return;
		}

		try {
			if ( NoticeScope::Request === $notice->scope || NoticeScope::User === $notice->scope || $notice->is_shared() ) {
				$this->delete( $notice->scope, $this->get_owner( $notice->scope, 0 ), $notice->id );
			} else {
				$this->hide( $notice->scope, $notice->id, \get_current_user_id(), 'delivered' );
			}
		} catch ( \Throwable $exception ) {
			$this->log( 'Could not acknowledge a {scope} notice: {message}', $notice->scope, $exception );
		}
	}

	// endregion

	// region HOOKS

	/**
	 * Prints the notices the current user sees here, with what a persistent dismissible notice's dismissal needs, and acknowledges the one-shots among them.
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

		foreach ( $this->select() as $notice ) {
			$attributes = array(
				'data-notices'      => $this->prefix,
				'data-notice-scope' => $notice->scope->value,
				'data-notice-id'    => $notice->id,
			);
			if ( $notice->persistent && $notice->dismissible ) {
				$attributes['data-notice-nonce'] = \wp_create_nonce( $this->get_nonce_action( $notice->scope, $notice->id ) );
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
			$this->acknowledge( $notice );
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
	 * Enqueues the script that posts dismissals through wp-api-fetch, when this request showed a notice that carries a dismissal nonce.
	 *
	 * The script rides a handle without a source, which prints its inline script after wp-api-fetch whether that printed in the head or prints in the footer.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function enqueue_dismiss_script(): void {
		if ( ! $this->needs_dismiss_script ) {
			return;
		}

		$settings = \wp_json_encode(
			array(
				'prefix' => $this->prefix,
				'path'   => "{$this->get_rest_namespace()}/notices/dismissals",
			)
		);

		\wp_register_script( "{$this->prefix}-notice-dismissal", false, array( 'wp-api-fetch' ), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- A handle without a source loads no file to cache.
		\wp_add_inline_script(
			"{$this->prefix}-notice-dismissal",
			<<<JS
			( function ( settings ) {
				document.addEventListener( 'click', function ( event ) {
					var button = event.target instanceof Element ? event.target.closest( '.notice-dismiss' ) : null;
					var notice = button ? button.closest( '[data-notice-nonce]' ) : null;
					if ( ! notice || notice.getAttribute( 'data-notices' ) !== settings.prefix ) {
						return;
					}

					wp.apiFetch( {
						path: settings.path,
						method: 'POST',
						data: {
							scope: notice.getAttribute( 'data-notice-scope' ),
							id: notice.getAttribute( 'data-notice-id' ),
							nonce: notice.getAttribute( 'data-notice-nonce' )
						}
					} ).catch( function ( error ) {
						console.error( 'The notice dismissal failed.', error );
					} );
				} );
			} )( $settings );
			JS
		);
		\wp_enqueue_script( "{$this->prefix}-notice-dismissal" );
	}

	/**
	 * Dismisses a notice shown to the current user, deleting it when it is shared and otherwise recording the user's dismissal.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   \WP_REST_Request<array<string, mixed>> $request The request, with the notice's scope and id and its dismissal nonce.
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

		if ( false === \wp_verify_nonce( $nonce, $this->get_nonce_action( $scope, $id ) ) ) {
			return new \WP_Error( 'rest_forbidden', 'The nonce does not match a notice shown to this user.', array( 'status' => 403 ) );
		}

		try {
			$owner  = $this->get_owner( $scope, 0 );
			$notice = null;
			if ( NoticeScope::Request !== $scope ) {
				$notice = $this->read( $scope, $owner )[ $id ] ?? null;
				if ( null === $notice ) {
					return new \WP_Error( 'rest_notice_invalid_id', 'No stored notice has that id.', array( 'status' => 404 ) );
				}

				if ( ! $notice->persistent || ! $notice->dismissible || ( null !== $notice->capability && ! \current_user_can( $notice->capability ) ) ) {
					return new \WP_Error( 'rest_forbidden', 'This user cannot dismiss that notice.', array( 'status' => 403 ) );
				}
			}

			$changed = null !== $notice && $notice->is_shared() ? $this->delete( $scope, $owner, $id ) : $this->hide( $scope, $id, $user_id, 'dismissed' );
		} catch ( \Throwable $exception ) {
			$this->log( 'Could not dismiss a {scope} notice: {message}', $scope, $exception );

			return new \WP_Error( 'rest_notice_store_error', 'The notice store failed.', array( 'status' => 500 ) );
		}

		if ( $changed ) {
			/**
			 * Fires when a user's dismissal takes effect, deleting a shared notice or hiding any other for that user.
			 *
			 * @since   2.0.0
			 * @version 2.0.0
			 *
			 * @param   string $id      The notice's id.
			 * @param   string $scope   The notice's scope.
			 * @param   int    $user_id The user who dismissed it.
			 * @param   int    $owner   The notice's owner: 0 for a request notice, otherwise the user, blog or network id.
			 */
			\do_action( "{$this->prefix}_notice_dismissed", $id, $scope->value, $user_id, $owner );
		}

		return new \WP_REST_Response( array( 'changed' => $changed ) );
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the owner of the scope's notices: 0 for the request, otherwise the user, or the current blog or network, so code inside switch_to_blog() addresses the switched site.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeScope $scope   The scope.
	 * @param   int         $user_id The user of user notices, where 0 is the current user.
	 *
	 * @throws  InvalidArgumentException Thrown when a user is given for another scope.
	 * @throws  LogicException           Thrown when user notices have no user and nobody is logged in.
	 *
	 * @return  int
	 */
	protected function get_owner( NoticeScope $scope, int $user_id ): int {
		if ( NoticeScope::User !== $scope ) {
			if ( 0 !== $user_id ) {
				throw new InvalidArgumentException( "A $scope->value notice belongs to no user, but user $user_id was given." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
			}

			return match ( $scope ) {
				NoticeScope::Request => 0,
				NoticeScope::Site    => \get_current_blog_id(),
				default              => \get_current_network_id(),
			};
		}

		$user_id = $user_id ?: \get_current_user_id();
		if ( $user_id < 1 ) {
			throw new LogicException( "A user notice needs a user, but it has user $user_id." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		return $user_id;
	}

	/**
	 * Returns the owner's unexpired notices of the scope by id.
	 *
	 * An entry that is not a valid notice of the scope, or has expired, is left out, and the scope's next write drops it.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeScope $scope The scope.
	 * @param   int         $owner The owner.
	 *
	 * @return  array<string, Notice>
	 */
	protected function read( NoticeScope $scope, int $owner ): array {
		$rows = match ( $scope ) {
			NoticeScope::Request => $this->request_notices,
			NoticeScope::User    => \get_user_option( "{$this->prefix}_notices", $owner ),
			NoticeScope::Site    => \get_option( "{$this->prefix}_notices" ),
			NoticeScope::Network => \get_network_option( $owner, "{$this->prefix}_network_notices" ),
		};

		$notices = array();
		foreach ( \is_array( $rows ) ? $rows : array() as $row ) {
			$notice = \is_array( $row ) ? Notice::from_array( $row ) : null;
			if ( null !== $notice && $scope === $notice->scope && ( null === $notice->expires_at || $notice->expires_at > \time() ) ) {
				$notices[ $notice->id ] = $notice;
			}
		}

		return $notices;
	}

	/**
	 * Writes the owner's notices of the scope, replacing those stored.
	 *
	 * User options go through wp_slash(), because the meta API unslashes what it stores.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeScope           $scope   The scope.
	 * @param   int                   $owner   The owner.
	 * @param   array<string, Notice> $notices The notices by id.
	 */
	protected function write( NoticeScope $scope, int $owner, array $notices ): void {
		$rows = \array_map( static fn ( Notice $notice ): array => $notice->to_array(), $notices );
		match ( $scope ) {
			NoticeScope::Request => $this->request_notices = $rows,
			NoticeScope::User    => \update_user_option( $owner, "{$this->prefix}_notices", \wp_slash( $rows ) ),
			NoticeScope::Site    => \update_option( "{$this->prefix}_notices", $rows, false ),
			NoticeScope::Network => \update_network_option( $owner, "{$this->prefix}_network_notices", $rows ),
		};
	}

	/**
	 * Deletes the owner's notice of the scope with the given id, and returns whether it was there.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeScope $scope The scope.
	 * @param   int         $owner The owner.
	 * @param   string      $id    The notice's id.
	 *
	 * @return  bool
	 */
	protected function delete( NoticeScope $scope, int $owner, string $id ): bool {
		$notices = $this->read( $scope, $owner );
		if ( ! isset( $notices[ $id ] ) ) {
			return false;
		}

		unset( $notices[ $id ] );
		$this->write( $scope, $owner, $notices );

		return true;
	}

	/**
	 * Returns the user's dismissals and showings of the scope's notices, keyed by scope and id: per site, or network-wide for network notices.
	 *
	 * The two keys differ in name, because get_user_option() falls back to a network-wide key of the same name. An invalid entry is left out, and the next write drops it.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeScope $scope   The scope.
	 * @param   int         $user_id The user.
	 *
	 * @return  array<string, array{state: string, at: int}>
	 */
	protected function get_hidden( NoticeScope $scope, int $user_id ): array {
		$stored = NoticeScope::Network === $scope ? \get_user_meta( $user_id, "{$this->prefix}_network_hidden_notices", true ) : \get_user_option( "{$this->prefix}_hidden_notices", $user_id );

		$hidden = array();
		foreach ( \is_array( $stored ) ? $stored : array() as $key => $entry ) {
			if ( \is_string( $key ) && \is_array( $entry ) && \is_string( $entry['state'] ?? null ) && \is_int( $entry['at'] ?? null ) ) {
				$hidden[ $key ] = array(
					'state' => $entry['state'],
					'at'    => $entry['at'],
				);
			}
		}

		return $hidden;
	}

	/**
	 * Records the user's dismissal or showing of a notice, replacing an earlier one, and returns whether it changed.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeScope $scope   The notice's scope.
	 * @param   string      $id      The notice's id.
	 * @param   int         $user_id The user.
	 * @param   string      $state   Either 'dismissed' or 'delivered'.
	 *
	 * @return  bool
	 */
	protected function hide( NoticeScope $scope, string $id, int $user_id, string $state ): bool {
		$hidden = $this->get_hidden( $scope, $user_id );
		if ( ( $hidden[ "$scope->value:$id" ]['state'] ?? null ) === $state ) {
			return false;
		}

		$hidden[ "$scope->value:$id" ] = array(
			'state' => $state,
			'at'    => \time(),
		);
		if ( NoticeScope::Network === $scope ) {
			\update_user_meta( $user_id, "{$this->prefix}_network_hidden_notices", \wp_slash( $hidden ) );
		} else {
			\update_user_option( $user_id, "{$this->prefix}_hidden_notices", \wp_slash( $hidden ) );
		}

		return true;
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
	 * Returns the action of a notice's dismissal nonce on the current site.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeScope $scope The notice's scope.
	 * @param   string      $id    The notice's id.
	 *
	 * @return  string
	 */
	protected function get_nonce_action( NoticeScope $scope, string $id ): string {
		return "{$this->prefix}_dismiss|" . \get_current_blog_id() . ":$scope->value:$id";
	}

	// endregion
}
