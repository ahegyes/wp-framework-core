<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\AdminNotice;

use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use DeepWebSolutions\Framework\Shared\Exception\LogicException;

\defined( 'ABSPATH' ) || exit;

/**
 * Where a notice belongs: the current request, a user on a site, a site, or a network.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final readonly class NoticeContext {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeScope $scope   The scope.
	 * @param   int         $owner   The owner: 0 for the request, otherwise the user, blog or network id.
	 * @param   int         $site_id Optional. The blog id of a user notice's site, and 0 in every other scope. Default 0.
	 *
	 * @throws  InvalidArgumentException Thrown when the owner or the site does not fit the scope.
	 */
	public function __construct(
		public NoticeScope $scope,
		public int $owner,
		public int $site_id = 0
	) {
		$fits = match ( $scope ) {
			NoticeScope::Request => 0 === $owner && 0 === $site_id,
			NoticeScope::User    => $owner > 0 && $site_id > 0,
			default              => $owner > 0 && 0 === $site_id,
		};

		if ( ! $fits ) {
			throw new InvalidArgumentException( "Owner $owner and site $site_id do not fit a $scope->value notice." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}
	}

	// endregion

	// region FACTORY METHODS

	/**
	 * Returns the context of the scope for the current blog, network and user, so code inside switch_to_blog() addresses the switched site.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeScope $scope   The scope.
	 * @param   int         $user_id Optional. The user of a user notice, where 0 is the current user. Default 0.
	 *
	 * @throws  InvalidArgumentException Thrown when a user is given for another scope, or the user id is negative.
	 * @throws  LogicException           Thrown when a user notice has no user and nobody is logged in.
	 *
	 * @return  self
	 */
	public static function current( NoticeScope $scope, int $user_id = 0 ): self {
		if ( NoticeScope::User !== $scope ) {
			if ( 0 !== $user_id ) {
				throw new InvalidArgumentException( "A $scope->value notice belongs to no user, but user $user_id was given." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
			}

			return new self(
				$scope,
				match ( $scope ) {
					NoticeScope::Request => 0,
					NoticeScope::Site    => \get_current_blog_id(),
					default              => \get_current_network_id(),
				}
			);
		}

		$user_id = $user_id ?: \get_current_user_id();
		if ( 0 === $user_id ) {
			throw new LogicException( 'A user notice needs a user, and nobody is logged in.' );
		}

		return new self( $scope, $user_id, \get_current_blog_id() );
	}

	// endregion
}
