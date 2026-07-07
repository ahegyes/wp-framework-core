<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Lifecycle\Hookable;

/**
 * A component that registers WordPress hooks. Called by PluginKernel after every
 * surviving component in every Feature has initialized. MAY reach any peer component
 * (regardless of which Feature declared it) — they are all resolved and initialized
 * by this point.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
interface HookableInterface {
	/**
	 * Register this component's WordPress hooks.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  Exceptions\HookRegistrationException On unrecoverable hook registration failure.
	 */
	public function register_hooks(): void;
}
