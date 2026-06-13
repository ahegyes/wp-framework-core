<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Installer;

use DeepWebSolutions\Framework\Shared\Version\Version;

/**
 * Centralized install / update / activate / deactivate / uninstall orchestrator.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
interface InstallerInterface {
	/**
	 * First-time install. Idempotent.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  Exceptions\InstallationException When install work fails.
	 */
	public function install(): void;

	/**
	 * Applies updates from the previously stored plugin version to the current one.
	 * The kernel runs this on boot whenever the stored version is older than the
	 * current one and advances the stored version only on success, so a failed update
	 * retries on the next request. Each migration step MUST therefore be idempotent:
	 * a partially-applied step that re-runs has to converge, not double-apply.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   Version $from_version Previously stored plugin version.
	 *
	 * @throws  Exceptions\UpdateException When the update fails.
	 */
	public function update( Version $from_version ): void;

	/**
	 * The plugin's current (target) version — the version install() and update() bring
	 * the site to. Supplied by the plugin's own wiring (typically read from the main-file
	 * header), so the kernel never reads the header during boot.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  Version
	 */
	public function get_current_version(): Version;

	/**
	 * The plugin version recorded as installed, or null when the plugin has never been
	 * installed on this site. The kernel compares it against get_current_version() on
	 * boot to choose between install(), update(), and a no-op.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  Version|null
	 */
	public function get_stored_version(): ?Version;

	/**
	 * Records `$version` as the installed version. The kernel calls this only after a
	 * successful install() or update(), so a failed routine leaves the prior version in
	 * place for the next boot to retry.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   Version $version Version to record as installed.
	 */
	public function set_stored_version( Version $version ): void;

	/**
	 * Callback target for register_activation_hook(). When `$network_wide` is true the hook fires once for the whole network, so per-site work must loop get_sites().
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   bool $network_wide Whether the activation is network-wide.
	 *
	 * @throws  Exceptions\ActivationException When activation work fails.
	 */
	public function activate( bool $network_wide = false ): void;

	/**
	 * Callback target for register_deactivation_hook().
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   bool $network_deactivating Whether the deactivation is network-wide.
	 *
	 * @throws  Exceptions\DeactivationException When deactivation work fails.
	 */
	public function deactivate( bool $network_deactivating = false ): void;

	/**
	 * Removes the plugin's persistent footprint. Runs in WordPress's cold uninstall bootstrap (only WP_UNINSTALL_PLUGIN defined, the kernel has not booted), so the implementation MUST be self-sufficient — assume no other plugins are loaded. Reached from the plugin's uninstall.php, which rebuilds the container and calls get_installer()->uninstall().
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  Exceptions\UninstallationException When uninstall work fails.
	 */
	public function uninstall(): void;
}
