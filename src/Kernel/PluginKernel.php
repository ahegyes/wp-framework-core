<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Kernel;

use DeepWebSolutions\Framework\Core\Contracts\HookableInterface;
use DeepWebSolutions\Framework\Core\Contracts\InitializableInterface;
use Psr\Container\ContainerInterface;

/**
 * Lifecycle dispatcher for plugin components.
 *
 * Plugin authors register component classes via {@see self::register()}, then call
 * {@see self::boot()} once (typically on `plugins_loaded` priority 15). Boot is two-pass:
 * every InitializableInterface::initialize() runs first across registered components,
 * then every HookableInterface::register_hooks() runs.
 *
 * Components are resolved through the PSR-11 container — the kernel uses only
 * ContainerInterface::get() and is otherwise container-agnostic.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class PluginKernel {
	// region FIELDS AND CONSTANTS

	/**
	 * Component classes registered for lifecycle dispatch, in registration order.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     list<class-string>
	 */
	private array $registrations = array();

	/**
	 * Resolved component instances, populated during {@see self::boot()}.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     list<object>
	 */
	private array $components = array();

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
	 * @param   ContainerInterface $container PSR-11 container that resolves registered component classes.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {}

	// endregion

	// region METHODS

	/**
	 * Register a component class for lifecycle dispatch.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   class-string $component_class Fully-qualified class name resolvable by the container.
	 */
	public function register( string $component_class ): void {
		$this->registrations[] = $component_class;
	}

	/**
	 * Resolve registered components and run two-pass lifecycle dispatch.
	 *
	 * Idempotent — subsequent calls are no-ops.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		foreach ( $this->registrations as $class ) {
			$this->components[] = $this->container->get( $class );
		}

		foreach ( $this->components as $component ) {
			if ( $component instanceof InitializableInterface ) {
				$component->initialize();
			}
		}

		foreach ( $this->components as $component ) {
			if ( $component instanceof HookableInterface ) {
				$component->register_hooks();
			}
		}
	}

	// endregion
}
