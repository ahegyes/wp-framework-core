<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Asset;

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
	 * @param   string           $plugin_file The path to the plugin's entry file, whose directory holds the built files and languages/.
	 * @param   non-empty-string $slug        The plugin's slug, which is its text domain.
	 */
	public function __construct(
		protected string $plugin_file,
		protected string $slug
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
		$registered = wp_register_script( $handle, plugin_dir_url( $this->plugin_file ) . $path, \array_values( \array_filter( $asset['dependencies'], 'is_string' ) ), $asset['version'], $args );
		if ( $registered ) {
			wp_set_script_translations( $handle, $this->slug, plugin_dir_path( $this->plugin_file ) . 'languages' );
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
		$registered = wp_register_style( $handle, plugin_dir_url( $this->plugin_file ) . $path, array(), $this->get_asset( $path )['version'] );
		if ( $registered ) {
			wp_style_add_data( $handle, 'rtl', 'replace' );
		}

		return $registered;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the dependencies and version that wp-scripts writes beside a built file.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $path The built file's path, relative to the plugin's directory.
	 *
	 * @return  array{dependencies: list<non-empty-string|array{id: non-empty-string, import?: 'static'|'dynamic'}>, version: string}
	 */
	protected function get_asset( string $path ): array {
		/**
		 * The build's dependencies and version.
		 *
		 * @var array{dependencies: list<non-empty-string|array{id: non-empty-string, import?: 'static'|'dynamic'}>, version: string} $asset
		 */
		$asset = require plugin_dir_path( $this->plugin_file ) . \dirname( $path ) . '/' . \pathinfo( $path, \PATHINFO_FILENAME ) . '.asset.php';
		return $asset;
	}

	// endregion
}
