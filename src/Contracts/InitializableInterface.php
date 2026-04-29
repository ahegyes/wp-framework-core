<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Contracts;

/**
 * Marks a component as needing initialization before hooks register.
 *
 * The PluginKernel calls {@see self::initialize()} during the first boot pass,
 * before any HookableInterface::register_hooks() call runs. Use for state setup
 * that other components may depend on during their hook registration.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
interface InitializableInterface {
	/**
	 * Initialize component state before hooks are registered.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function initialize(): void;
}
