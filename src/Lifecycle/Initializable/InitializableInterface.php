<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Lifecycle\Initializable;

/**
 * A component that initializes internal state. Called by PluginKernel after all
 * surviving components are resolved and before any register_hooks(). A peer (in any
 * Feature) is already constructed and reachable from the container, but no component
 * has registered its hooks yet — so initialize() may read a peer's constructed state
 * but MUST NOT depend on any peer's hooks being live.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
interface InitializableInterface {
	/**
	 * Initialize internal state. Single-component, pre-hook setup only.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  Exceptions\InitializationException On unrecoverable init failure.
	 */
	public function initialize(): void;
}
