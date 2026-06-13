<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\ValueObjects;

use DeepWebSolutions\Framework\Shared\ValueObject\AbstractValueObject;

use function DeepWebSolutions\Framework\Bootstrap\Plugin\get_plugin_metadata;

/**
 * Typed read-once value object wrapping the WP plugin file header.
 *
 * The single source of truth is the file header comment of the plugin's main
 * file. Consumers must NOT type metadata values manually — pass the file path
 * and let WordPress read the values via the bootstrap package's canonical
 * {@see get_plugin_metadata()} reader (which wraps `get_plugin_data()` and
 * caches per-request).
 *
 * Properties hold the RAW header values — safe to construct in any context,
 * including before the `init` action has fired. Use {@see self::get_display_name()}
 * for an init-aware translated display name.
 *
 * Inherits structural equality and JSON serialization from {@see AbstractValueObject}.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final readonly class PluginHeader extends AbstractValueObject {
	// region FIELDS AND CONSTANTS

	/**
	 * Plugin display name (raw, untranslated).
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     string
	 */
	public string $name;

	/**
	 * Plugin version.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     string
	 */
	public string $version;

	/**
	 * Plugin text domain for i18n.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     string
	 */
	public string $text_domain;

	/**
	 * Minimum required WordPress version.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     string
	 */
	public string $requires_at_least;

	/**
	 * Minimum required PHP version.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     string
	 */
	public string $requires_php;

	/**
	 * Network-only flag; `get_plugin_data()` coerces the raw header to a boolean.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     bool
	 */
	public bool $network;

	/**
	 * Plugin slug — used for hook prefixing, REST namespacing, text-domain matching.
	 * Derived from Text Domain (preferred), falling back to the plugin directory
	 * name, or the main-file name for single-file plugins.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     string
	 */
	public string $slug;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $file_path Absolute path to the plugin's main PHP file; must live under `WP_PLUGIN_DIR` for round-trip via `plugin_basename()` to succeed.
	 */
	public function __construct(
		public string $file_path
	) {
		$basename = \plugin_basename( $file_path );
		$data     = get_plugin_metadata( $basename );

		$this->name              = $data['Name'] ?? '';
		$this->version           = $data['Version'] ?? '';
		$this->text_domain       = $data['TextDomain'] ?? '';
		$this->requires_at_least = $data['RequiresWP'] ?? '';
		$this->requires_php      = $data['RequiresPHP'] ?? '';
		$this->network           = $data['Network'] ?? false;

		$directory  = \dirname( $basename );
		$this->slug = match ( true ) {
			'' !== $this->text_domain => $this->text_domain,
			'.' !== $directory        => $directory,
			default                   => \pathinfo( $basename, PATHINFO_FILENAME ),
		};
	}

	// endregion

	// region GETTERS

	/**
	 * Returns the plugin's display name, translated when safe.
	 *
	 * Translation is only requested after `init` has fired, per WP 6.7+'s
	 * "doing it wrong" gate on early translation calls. Before `init`, returns
	 * the raw {@see self::$name}.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  string
	 */
	public function get_display_name(): string {
		if ( \did_action( 'init' ) <= 0 ) {
			return $this->name;
		}

		$data = get_plugin_metadata( \plugin_basename( $this->file_path ), true );

		return $data['Name'] ?? $this->name;
	}

	// endregion
}
