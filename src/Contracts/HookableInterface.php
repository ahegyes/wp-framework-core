<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Contracts;

/**
 * Marks a component as having WordPress hooks to register.
 *
 * The PluginKernel calls {@see self::register_hooks()} during the second boot pass,
 * after every InitializableInterface::initialize() call has completed. Implementations
 * call add_action() / add_filter() with literal prefixed strings inside register_hooks().
 *
 * @since   2.0.0
 * @version 2.0.0
 */
interface HookableInterface {
	/**
	 * Register WordPress hooks (add_action / add_filter calls).
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function register_hooks(): void;
}
