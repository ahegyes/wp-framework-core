<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Migration;

\defined( 'ABSPATH' ) || exit;

/**
 * A migration step that does its work in batches, each run from the migration runner's scheduled callback.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
interface BatchedMigrationStepInterface {
	/**
	 * Processes one batch of about one or two seconds, and returns whether work remains.
	 *
	 * A batch converges from any partial state and never depends on the current user, because it runs in background requests and may run again after an interruption.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  bool
	 */
	public function process_batch(): bool;
}
