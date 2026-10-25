<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Storage;

\defined( 'ABSPATH' ) || exit;

/**
 * The outcome of a claim on a lock.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
enum LockClaim {
	/**
	 * The lock was free, and the claimant holds it.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	case Acquired;

	/**
	 * The claimant holds the lock in place of a hold that had expired or a row that held no lock, so the work of the previous holder may be unfinished.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	case TakenOver;

	/**
	 * A live hold keeps the lock, even one of the claimant's own, so the claimant does not hold it.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	case Busy;
}
