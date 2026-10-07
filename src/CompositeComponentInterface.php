<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework;

\defined( 'ABSPATH' ) || exit;

/**
 * A component that declares child components, which the kernel walks after it.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
interface CompositeComponentInterface extends ComponentInterface {
	/**
	 * Returns the classes of the component's children, in walk order.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  list<class-string<ComponentInterface>>
	 */
	public static function get_child_component_classes(): array;
}
