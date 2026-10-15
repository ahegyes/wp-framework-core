<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Notices;

\defined( 'ABSPATH' ) || exit;

/**
 * Where an admin notice belongs, which decides the store that keeps it and the admin surfaces it shows on.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
enum NoticeScope: string {
	/**
	 * The current request only.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	case Request = 'request';

	/**
	 * One user on one site.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	case User = 'user';

	/**
	 * One site, seen by the users who hold the notice's capability.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	case Site = 'site';

	/**
	 * One network, seen by the users who hold the notice's capability.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	case Network = 'network';
}
