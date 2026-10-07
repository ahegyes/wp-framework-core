<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework;

\defined( 'ABSPATH' ) || exit;

/**
 * A node of a plugin's component tree, which the kernel constructs and then registers.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
interface ComponentInterface {
	/**
	 * Registers the component's WordPress hooks.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function register_hooks(): void;
}
