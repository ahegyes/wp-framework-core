<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Storage;

\defined( 'ABSPATH' ) || exit;

/**
 * A metadata row as its table holds it, which names the exact row to delete by its meta id.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final readonly class MetaRow {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $table     The meta table that holds the row, which differs per site for post, term and comment meta.
	 * @param   int    $id        The meta id.
	 * @param   int    $object_id The id of the object the row belongs to.
	 * @param   string $key       The meta key.
	 * @param   mixed  $value     The value as read back, or as given when the row was added.
	 */
	public function __construct(
		public string $table,
		public int $id,
		public int $object_id,
		public string $key,
		public mixed $value
	) {}

	// endregion
}
