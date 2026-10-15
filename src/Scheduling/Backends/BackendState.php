<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Scheduling\Backends;

\defined( 'ABSPATH' ) || exit;

/**
 * The readiness of a scheduler in the current request.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @internal
 */
enum BackendState {
	/**
	 * The scheduler is not loaded.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	case Absent;

	/**
	 * The scheduler is loaded, but its store is not ready yet.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	case Initializing;

	/**
	 * The scheduler is below the supported version, so it is read and cleared but takes no writes.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	case Unsupported;

	/**
	 * The scheduler takes reads, writes and clears.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	case Supported;
}
