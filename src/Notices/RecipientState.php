<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Notices;

\defined( 'ABSPATH' ) || exit;

/**
 * What happened between one viewer and one notice identity.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
enum RecipientState: string {
	/**
	 * A one-shot notice was shown to the viewer.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	case Delivered = 'delivered';

	/**
	 * The viewer dismissed the notice.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	case Dismissed = 'dismissed';
}
