<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Storage;

\defined( 'ABSPATH' ) || exit;

/**
 * An option as one site's options table holds it, which names the exact row for a conditional write.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final readonly class OptionRow {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int    $site_id The blog id of the site whose options table holds the row.
	 * @param   string $name    The option name.
	 * @param   mixed  $value   The value, unserialized as the options API unserializes it.
	 * @param   string $raw     The value as stored, which conditional writes compare byte for byte.
	 */
	public function __construct(
		public int $site_id,
		public string $name,
		public mixed $value,
		public string $raw
	) {}

	// endregion
}
