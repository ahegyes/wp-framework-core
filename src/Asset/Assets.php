<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Asset;

use DeepWebSolutions\Framework\Core\PluginIdentity;

\defined( 'ABSPATH' ) || exit;

/**
 * Registers a plugin's built scripts and styles with the dependencies and version that wp-scripts writes beside each.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class Assets {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   PluginIdentity $identity The plugin's identity, whose entry file's directory holds the built files and languages/, and whose slug is its text domain.
	 */
	public function __construct(
		protected PluginIdentity $identity
	) {}

	// endregion

	// region METHODS

	/**
	 * Registers a built script with its build's script dependencies and version, and loads its translations from the plugin's languages/ directory, and returns whether WordPress registered it.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @phpstan-param array{strategy?: 'async'|'defer', in_footer?: bool, fetchpriority?: 'auto'|'low'|'high'} $args
	 *
	 * @param   non-empty-string     $handle The script's handle.
	 * @param   non-empty-string     $path   The built script's path, relative to the plugin's directory.
	 * @param   array<string, mixed> $args   Optional. How the script loads, as wp_register_script() takes it. Default is none, which loads it in the head.
	 *
	 * @return  bool
	 */
	public function register_script( string $handle, string $path, array $args = array() ): bool {
		$asset = $this->get_asset( $path );

		// Script modules the build imports are not script handles, so they stay out of the dependencies.
		$registered = wp_register_script( $handle, plugin_dir_url( $this->identity->file ) . $path, \array_values( \array_filter( $asset['dependencies'], 'is_string' ) ), $asset['version'], $args );
		if ( $registered ) {
			wp_set_script_translations( $handle, $this->identity->slug, plugin_dir_path( $this->identity->file ) . 'languages' );
		}

		return $registered;
	}

	/**
	 * Registers a built stylesheet with its build's version and its right-to-left variant, and returns whether WordPress registered it.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   non-empty-string $handle The stylesheet's handle.
	 * @param   non-empty-string $path   The built stylesheet's path, relative to the plugin's directory.
	 *
	 * @return  bool
	 */
	public function register_style( string $handle, string $path ): bool {
		$registered = wp_register_style( $handle, plugin_dir_url( $this->identity->file ) . $path, array(), $this->get_asset( $path )['version'] );
		if ( $registered ) {
			wp_style_add_data( $handle, 'rtl', 'replace' );
		}

		return $registered;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the dependencies and version that wp-scripts writes for a built file, or no dependencies and the file's modification time where it wrote none.
	 *
	 * The style-{entry}.css chunk that wp-scripts makes of a style.scss import has no asset file of its own, since its version sits in the entry's. A file built outside wp-scripts has none at all, so it is versioned by its own time, as a best effort.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $path The built file's path, relative to the plugin's directory.
	 *
	 * @return  array{dependencies: list<non-empty-string|array{id: non-empty-string, import?: 'static'|'dynamic'}>, version: string}
	 */
	protected function get_asset( string $path ): array {
		$file       = plugin_dir_path( $this->identity->file ) . $path;
		$directory  = \dirname( $file );
		$name       = \pathinfo( $file, \PATHINFO_FILENAME );
		$asset_file = "$directory/$name.asset.php";
		if ( ! \is_readable( $asset_file ) && \str_starts_with( $name, 'style-' ) && \str_ends_with( $file, '.css' ) ) {
			$asset_file = "$directory/" . \substr( $name, \strlen( 'style-' ) ) . '.asset.php';
		}

		if ( ! \is_readable( $asset_file ) ) {
			return array(
				'dependencies' => array(),
				'version'      => (string) \filemtime( $file ),
			);
		}

		/**
		 * The build's dependencies and version.
		 *
		 * @var array{dependencies: list<non-empty-string|array{id: non-empty-string, import?: 'static'|'dynamic'}>, version: string} $asset
		 */
		$asset = require $asset_file;
		return $asset;
	}

	// endregion
}
