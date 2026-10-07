<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework;

\defined( 'ABSPATH' ) || exit;

/**
 * A component gated by a check that runs before it is constructed.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
interface ConditionalComponentInterface {
	/**
	 * Returns whether the component and its children load.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  bool
	 */
	public static function should_load(): bool;
}
