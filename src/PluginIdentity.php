<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core;

use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use DeepWebSolutions\Framework\Shared\ValueObject\AbstractValueObject;

\defined( 'ABSPATH' ) || exit;

/**
 * The identity of a plugin, which the framework's classes take in place of its entry file, slug and prefix.
 *
 * The slug roots the plugin's hook names and REST namespace, and the prefix starts every option, transient and meta name the framework stores for it.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final readonly class PluginIdentity extends AbstractValueObject {
	// region FIELDS AND CONSTANTS

	/**
	 * The root of the plugin's hook names: its slug with underscores for its dashes.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     string
	 */
	public string $hook_root;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string           $file   The path to the plugin's entry file.
	 * @param   non-empty-string $slug   The plugin's slug, which is also its text domain.
	 * @param   string           $prefix The plugin's prefix: a lowercase letter followed by up to 31 lowercase letters, digits and underscores.
	 *
	 * @throws  InvalidArgumentException Thrown when the prefix is invalid.
	 */
	public function __construct(
		public string $file,
		public string $slug,
		public string $prefix
	) {
		if ( 1 !== \preg_match( '/\A[a-z][a-z0-9_]{0,31}\z/', $prefix ) ) {
			throw new InvalidArgumentException( "Prefix '$prefix' is not a lowercase letter followed by up to 31 lowercase letters, digits and underscores." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		$this->hook_root = \str_replace( '-', '_', $slug );
	}

	// endregion
}
