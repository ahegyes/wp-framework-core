<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core;

use DeepWebSolutions\Framework\Core\Installer\InstallerInterface;
use DeepWebSolutions\Framework\Core\ValueObjects\PluginHeader;
use Psr\Container\ContainerInterface;

/**
 * Consumer-implemented plugin contract. A plugin is a final class implementing
 * this interface, typically as a singleton constructed in the plugin's main
 * .php file with the file path passed to get_instance().
 *
 * @since   2.0.0
 * @version 2.0.0
 */
interface PluginInterface {
	/**
	 * Absolute path to the plugin's main .php file. Canonical anchor for every
	 * plugin_dir_path() / plugins_url() derivation.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  string
	 */
	public function get_plugin_file(): string;

	/**
	 * Typed wrapper around the plugin's main-file WP header. The get_plugin_ prefix
	 * keeps it clear of WordPress's theme-template get_header().
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  PluginHeader
	 */
	public function get_plugin_header(): PluginHeader;

	/**
	 * PSR-11 container resolving Feature classes, their components, the installer.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  ContainerInterface
	 */
	public function get_container(): ContainerInterface;

	/**
	 * Class names of the Features registered for boot; the kernel resolves each
	 * from the container.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  list<class-string<\DeepWebSolutions\Framework\Core\Feature\FeatureInterface>>
	 */
	public function get_feature_classes(): array;

	/**
	 * Centralized install/upgrade/activate/deactivate/uninstall orchestrator.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  InstallerInterface
	 */
	public function get_installer(): InstallerInterface;
}
