<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\AdminNotice;

\defined( 'ABSPATH' ) || exit;

/**
 * The kind of an admin notice, which sets its color.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
enum NoticeType: string {
	/**
	 * Something failed.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	case Error = 'error';

	/**
	 * Something needs attention.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	case Warning = 'warning';

	/**
	 * Something succeeded.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	case Success = 'success';

	/**
	 * Something is worth knowing.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	case Info = 'info';
}
