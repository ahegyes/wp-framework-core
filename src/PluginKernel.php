<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework;

use DeepWebSolutions\Framework\Shared\Exception\LogicException;
use Psr\Container\ContainerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Constructs a plugin's enabled components through the container, then registers their hooks.
 *
 * The container must build any component class it is given, as an autowiring container such as PHP-DI does, because PSR-11 does not promise it.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class PluginKernel {
	// region FIELDS AND CONSTANTS

	/**
	 * The classes constructed, in walk order, whether or not registration reached them.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     list<class-string<ComponentInterface>>
	 */
	public protected(set) array $resolved = array();

	/**
	 * The classes whose gate returned false.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     list<class-string<ComponentInterface>>
	 */
	public protected(set) array $skipped = array();

	/**
	 * The classes walked so far, as keys.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     array<class-string, true>
	 */
	protected array $visited = array();

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   ContainerInterface $container The container that builds each component.
	 */
	public function __construct(
		protected ContainerInterface $container
	) {}

	// endregion

	// region METHODS

	/**
	 * Constructs every enabled component, then registers their hooks in walk order.
	 *
	 * Construction finishes before the first registration, so a failing definition or constructor leaves no component hook behind.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   list<class-string> $roots The top-level component classes, in order.
	 *
	 * @throws  LogicException Thrown when a class is not a component, appears twice in the tree, or resolves to an entry of another type.
	 */
	public function boot( array $roots ): void {
		foreach ( $this->resolve( $roots ) as $component ) {
			$component->register_hooks();
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Constructs each enabled class and, depth first, its enabled descendants.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   list<class-string> $classes The classes to walk, in order.
	 *
	 * @throws  LogicException Thrown when a class is not a component, appears twice in the tree, or resolves to an entry of another type.
	 *
	 * @return  list<ComponentInterface>
	 */
	protected function resolve( array $classes ): array {
		$components = array();
		foreach ( $classes as $class ) {
			if ( isset( $this->visited[ $class ] ) ) {
				throw new LogicException( "Component '$class' appears twice in the tree." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
			}
			$this->visited[ $class ] = true;

			if ( ! \is_a( $class, ComponentInterface::class, true ) ) {
				throw new LogicException( "Class '$class' does not implement ComponentInterface." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
			}
			if ( \is_a( $class, ConditionalComponentInterface::class, true ) && ! $class::should_load() ) {
				$this->skipped[] = $class;
				continue;
			}

			$component = $this->container->get( $class );
			if ( ! $component instanceof $class ) {
				throw new LogicException( "The container entry '$class' is not an instance of that class." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
			}

			$components[]     = $component;
			$this->resolved[] = $class;
			if ( \is_a( $class, CompositeComponentInterface::class, true ) ) {
				// The declared class names the children, so no runtime state can add an unvisited node.
				\array_push( $components, ...$this->resolve( $class::get_child_component_classes() ) );
			}
		}

		return $components;
	}

	// endregion
}
