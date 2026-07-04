<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core;

use DeepWebSolutions\Framework\Core\Feature\FeatureInterface;
use DeepWebSolutions\Framework\Core\Installer\InstallerInterface;
use DeepWebSolutions\Framework\Core\ValueObjects\PluginHeader;
use Psr\Container\ContainerInterface;

/**
 * Consumer-implemented plugin contract. A plugin is a final class implementing
 * this interface, typically managed as a singleton constructed in the plugin's
 * main .php file — instance management stays with the consumer; the contract
 * declares only the accessors the kernel drives.
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
	 * keeps it clear of WordPress's theme-template get_header(). A consumer-facing
	 * accessor for the plugin's own metadata (display name, version, text domain);
	 * the kernel boot reads the version from the installer, not from here, so this
	 * stays part of the contract even though the engine does not consult it.
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
	 * @return  list<class-string<FeatureInterface>>
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
