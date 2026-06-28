<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core;

use DeepWebSolutions\Framework\Core\Composite\CompositeComponentInterface;
use DeepWebSolutions\Framework\Core\Conditional\ConditionalInterface;
use DeepWebSolutions\Framework\Core\Enabled\EnabledInterface;
use DeepWebSolutions\Framework\Core\Feature\Exceptions\FeatureException;
use DeepWebSolutions\Framework\Core\Feature\FeatureInterface;
use DeepWebSolutions\Framework\Core\Lifecycle\Hookable\HookableInterface;
use DeepWebSolutions\Framework\Core\Lifecycle\Initializable\InitializableInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runtime engine that boots a plugin. On boot it first runs the installer's version
 * check, then gates each Feature on its conditionals before resolving it, then flattens
 * every surviving Feature's component tree — pruning any subtree whose node is disabled
 * — and initializes every runnable component before any of them registers hooks, so a
 * hook callback may reach a peer component regardless of which Feature declared it.
 *
 * The kernel is the sole dispatcher of the component lifecycle: a composite component
 * declares its children but never dispatches them itself, which keeps the parent-off ⇒
 * subtree-off guarantee enforced in one place.
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
	protected bool $booted = false;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   PluginInterface      $plugin Plugin to boot.
	 * @param   LoggerInterface|null $logger Optional logger; names each Feature or component the kernel gates out and reports a failed installer routine.
	 */
	public function __construct(
		protected readonly PluginInterface $plugin,
		protected readonly ?LoggerInterface $logger = null,
	) {}

	// endregion

	// region METHODS

	/**
	 * Construct and boot in one call. Returns the kernel for callers that want to
	 * inspect or re-call (idempotent) later.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   PluginInterface      $plugin Plugin to boot.
	 * @param   LoggerInterface|null $logger Optional diagnostic logger.
	 *
	 * @return  self
	 */
	public static function run( PluginInterface $plugin, ?LoggerInterface $logger = null ): self {
		$kernel = new self( $plugin, $logger );
		$kernel->boot();

		return $kernel;
	}

	/**
	 * Wires the plugin's activation and deactivation routines to WordPress. Must be
	 * called while the plugin's main file is being included — register_activation_hook()
	 * only schedules a callback for the activation request, which fires before any
	 * plugins_loaded-deferred {@see self::boot()} would run.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   PluginInterface $plugin Plugin whose installer handles activation/deactivation.
	 */
	public static function register_lifecycle_hooks( PluginInterface $plugin ): void {
		\register_activation_hook(
			$plugin->get_plugin_file(),
			static function ( bool $network_wide = false ) use ( $plugin ): void {
				$plugin->get_installer()->activate( $network_wide );
			},
		);

		\register_deactivation_hook(
			$plugin->get_plugin_file(),
			static function ( bool $network_deactivating = false ) use ( $plugin ): void {
				$plugin->get_installer()->deactivate( $network_deactivating );
			},
		);
	}

	/**
	 * Boots the plugin. Runs the installer version check first; if it fails the boot
	 * stops before any component runs. Otherwise each Feature's conditionals gate it in
	 * or out, every surviving Feature's component tree is flattened with disabled
	 * subtrees pruned, and every runnable component is initialized before any registers
	 * hooks, so a hook callback may safely reach a peer in another Feature.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		if ( ! $this->run_installer() ) {
			return;
		}

		$container = $this->plugin->get_container();

		$surviving_features = array();
		foreach ( $this->plugin->get_feature_classes() as $feature_class ) {
			if ( $this->are_conditionals_met( $feature_class, $container ) ) {
				/** @var FeatureInterface $feature */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- inline @var type assertion, no description applies.
				$feature              = $container->get( $feature_class );
				$surviving_features[] = $feature;
			}
		}

		$this->assert_unique_component_graph( $surviving_features );
		$runnable_components = $this->collect_runnable_components( $surviving_features, $container );

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
	}

	// endregion

	// region HELPERS

	/**
	 * Resolves the installer and runs its version check: install() on a fresh site,
	 * update() when the stored version is older than the current one, then records the
	 * current version. The resolution sits inside the guard, so a non-resolvable installer
	 * fails closed like a broken migration — it logs, leaves the stored version untouched
	 * for the next boot to retry, and stops the boot instead of fataling every request.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  bool True when the boot may proceed; false when the routine failed.
	 */
	protected function run_installer(): bool {
		try {
			$installer = $this->plugin->get_installer();
			$stored    = $installer->get_stored_version();
			$current   = $installer->get_current_version();

			if ( null === $stored ) {
				$installer->install();
				$installer->set_stored_version( $current );
			} elseif ( $stored->is_less_than( $current ) ) {
				$installer->update( $stored );
				$installer->set_stored_version( $current );
			}
		} catch ( \Throwable $error ) {
			$this->logger?->error(
				'Plugin installation routine failed; skipping component boot for this request.',
				array( 'exception' => $error ),
			);

			return false;
		}

		return true;
	}

	/**
	 * Resolves every conditional class declared by the Feature and returns false on the
	 * first unsatisfied gate. Throws when a declared conditional does not implement
	 * {@see ConditionalInterface} — fail-closed, since a malformed gate must not let a
	 * Feature boot unguarded.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   class-string<FeatureInterface> $feature_class Feature being evaluated.
	 * @param   ContainerInterface             $container     Plugin container.
	 *
	 * @throws  FeatureException When a declared conditional class does not implement ConditionalInterface.
	 *
	 * @return  bool
	 */
	protected function are_conditionals_met( string $feature_class, ContainerInterface $container ): bool {
		foreach ( $feature_class::get_conditional_classes() as $conditional_class ) {
			$conditional = $container->get( $conditional_class );

			if ( ! $conditional instanceof ConditionalInterface ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- framework-internal exception; never reaches an HTML output context unescaped.
				throw new FeatureException( 'Conditional ' . $conditional_class . ' declared by feature ' . $feature_class . ' does not implement ' . ConditionalInterface::class . '.' );
			}

			if ( ! $conditional->is_met() ) {
				$this->logger?->debug(
					'Feature gated out by an unmet conditional.',
					array(
						'feature'     => $feature_class,
						'conditional' => $conditional_class,
					),
				);

				return false;
			}
		}

		return true;
	}

	/**
	 * Validates the declared component graph before any component is resolved: a class
	 * reached twice — listed by two Features, nested under two composites, or both a
	 * Feature component and a composite child — throws, which also catches cycles. The
	 * walk reads {@see CompositeComponentInterface::get_child_component_classes()}
	 * statically, so it covers the whole declared graph regardless of which components
	 * end up enabled.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   list<FeatureInterface> $features Surviving Features.
	 *
	 * @throws  FeatureException When a component class appears more than once in the graph.
	 */
	protected function assert_unique_component_graph( array $features ): void {
		/** @var array<class-string, true> $seen */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- inline @var type assertion, no description applies.
		$seen = array();
		foreach ( $features as $feature ) {
			foreach ( $feature->get_component_classes() as $component_class ) {
				$this->assert_component_not_duplicated( $component_class, $seen );
			}
		}
	}

	/**
	 * Marks a component class seen and recurses into its declared children, throwing if
	 * the class was already seen anywhere in the graph.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   class-string              $component_class Component class to record.
	 * @param   array<class-string, true> $seen            Classes already encountered, by reference.
	 *
	 * @throws  FeatureException When the class has already been seen.
	 */
	protected function assert_component_not_duplicated( string $component_class, array &$seen ): void {
		if ( isset( $seen[ $component_class ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- framework-internal exception; never reaches an HTML output context unescaped.
			throw new FeatureException( 'Component ' . $component_class . ' is registered more than once; a component may belong to a single parent.' );
		}
		$seen[ $component_class ] = true;

		if ( \is_a( $component_class, CompositeComponentInterface::class, true ) ) {
			foreach ( $component_class::get_child_component_classes() as $child_class ) {
				$this->assert_component_not_duplicated( $child_class, $seen );
			}
		}
	}

	/**
	 * Resolves the component tree of every surviving Feature into a flat, pre-order list
	 * of runnable components: a parent precedes its children, and a disabled node's whole
	 * subtree is pruned without resolving its children.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   list<FeatureInterface> $features  Surviving Features.
	 * @param   ContainerInterface     $container Plugin container.
	 *
	 * @return  list<object>
	 */
	protected function collect_runnable_components( array $features, ContainerInterface $container ): array {
		/** @var list<object> $runnable */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- inline @var type assertion, no description applies.
		$runnable = array();
		foreach ( $features as $feature ) {
			foreach ( $feature->get_component_classes() as $component_class ) {
				$this->collect_runnable_subtree( $component_class, $container, $runnable );
			}
		}

		return $runnable;
	}

	/**
	 * Resolves a component and, when it is runnable, appends it and recurses into its
	 * children. A disabled component is skipped along with its whole subtree.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   class-string       $component_class Component class to resolve.
	 * @param   ContainerInterface $container       Plugin container.
	 * @param   list<object>       $runnable        Accumulating runnable list, by reference.
	 */
	protected function collect_runnable_subtree( string $component_class, ContainerInterface $container, array &$runnable ): void {
		/** @var object $component */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- inline @var type assertion, no description applies.
		$component = $container->get( $component_class );

		if ( ! $this->is_runnable( $component ) ) {
			$this->logger?->debug(
				'Component gated out as disabled; its subtree is pruned.',
				array( 'component' => $component_class ),
			);

			return;
		}

		$runnable[] = $component;

		// Recurse on the declared class-string, the same source of truth the graph
		// validation uses, so the resolved instance's runtime class can never reach a
		// child that duplicate/cycle validation did not see.
		if ( \is_a( $component_class, CompositeComponentInterface::class, true ) ) {
			foreach ( $component_class::get_child_component_classes() as $child_class ) {
				$this->collect_runnable_subtree( $child_class, $container, $runnable );
			}
		}
	}

	/**
	 * A component is runnable unless it implements {@see EnabledInterface} and reports
	 * itself disabled.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   object $component Component to check.
	 *
	 * @return  bool
	 */
	protected function is_runnable( object $component ): bool {
		if ( $component instanceof EnabledInterface && ! $component->is_enabled() ) {
			return false;
		}

		return true;
	}

	// endregion
}
