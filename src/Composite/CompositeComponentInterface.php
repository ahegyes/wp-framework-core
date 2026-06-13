<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Composite;

/**
 * A component that owns child components. The kernel resolves and dispatches the
 * children itself and prunes the whole subtree when this component is gated off via
 * {@see \DeepWebSolutions\Framework\Core\Enabled\EnabledInterface}, so a disabled
 * group can never leave a descendant running. Static so the kernel can validate the
 * declared component graph for duplicates and cycles before resolving any component.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
interface CompositeComponentInterface {
	/**
	 * Class names of the child components this component owns; the kernel resolves
	 * each from the container and dispatches it after this component.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  list<class-string>
	 */
	public static function get_child_component_classes(): array;
}
