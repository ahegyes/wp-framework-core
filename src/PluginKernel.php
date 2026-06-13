<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core;

use DeepWebSolutions\Framework\Core\Conditional\ConditionalInterface;
use DeepWebSolutions\Framework\Core\Enabled\EnabledInterface;
use DeepWebSolutions\Framework\Core\Feature\FeatureInterface;
use DeepWebSolutions\Framework\Core\Lifecycle\Hookable\HookableInterface;
use DeepWebSolutions\Framework\Core\Lifecycle\Initializable\InitializableInterface;
use Psr\Container\ContainerInterface;

/**
 * Runtime engine that boots a plugin. Iterates the plugin's Features, applies
 * pre-resolution conditionals, then initializes every surviving component before
 * registering any hooks, so a component may reach a peer service during
 * register_hooks() regardless of which Feature declared it.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class PluginKernel {
	// region FIELDS AND CONSTANTS

	/**
	 * Whether {@see self::boot()} has already run. Subsequent calls short-circuit.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     bool
	 */
	private bool $booted = false;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   PluginInterface $plugin Plugin to boot.
	 */
	public function __construct(
		private readonly PluginInterface $plugin,
	) {}

	// endregion

	// region METHODS

	/**
	 * Construct and boot in one call. Returns the kernel for callers that want
	 * to inspect or re-call (idempotent) later.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   PluginInterface $plugin Plugin to boot.
	 *
	 * @return  self
	 */
	public static function run( PluginInterface $plugin ): self {
		$kernel = new self( $plugin );
		$kernel->boot();

		return $kernel;
	}

	/**
	 * Boots the plugin. First each Feature's conditionals gate it in or out;
	 * the kernel then resolves every surviving Feature's components and drops
	 * those whose {@see EnabledInterface::is_enabled()} returns false. Every
	 * runnable component is initialized before any registers hooks, so a hook
	 * callback may safely reach a peer in another Feature.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$container          = $this->plugin->get_container();
		$surviving_features = array();

		foreach ( $this->plugin->get_feature_classes() as $feature_class ) {
			$feature = $container->get( $feature_class );

			if ( $this->conditionals_pass( $feature, $container ) ) {
				$surviving_features[] = $feature;
			}
		}

		// Resolve runnable components from all surviving Features.
		$runnable_components = array();
		foreach ( $surviving_features as $feature ) {
			foreach ( $feature->get_component_classes() as $component_class ) {
				$component = $container->get( $component_class );
				if ( $this->is_runnable( $component ) ) {
					$runnable_components[] = $component;
				}
			}
		}

		// Initialize all surviving components before any registers hooks.
		foreach ( $runnable_components as $component ) {
			if ( $component instanceof InitializableInterface ) {
				$component->initialize();
			}
		}

		foreach ( $runnable_components as $component ) {
			if ( $component instanceof HookableInterface ) {
				$component->register_hooks();
			}
		}

		$this->booted = true;
	}

	// endregion

	// region HELPERS

	/**
	 * Resolves every conditional class declared by the Feature and returns
	 * false on the first unsatisfied gate.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   FeatureInterface   $feature   Feature being evaluated.
	 * @param   ContainerInterface $container Plugin container.
	 *
	 * @return  bool
	 */
	private function conditionals_pass( FeatureInterface $feature, ContainerInterface $container ): bool {
		foreach ( $feature::get_conditional_classes() as $cond_class ) {
			$cond = $container->get( $cond_class );
			if ( $cond instanceof ConditionalInterface && ! $cond->is_met() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A component is runnable unless it implements {@see EnabledInterface} and
	 * reports itself disabled.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   object $component Component to check.
	 *
	 * @return  bool
	 */
	private function is_runnable( object $component ): bool {
		if ( $component instanceof EnabledInterface && ! $component->is_enabled() ) {
			return false;
		}

		return true;
	}

	// endregion
}
