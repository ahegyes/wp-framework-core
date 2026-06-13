<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Enabled;

/**
 * Post-resolution gate. The kernel calls is_enabled() on a constructed component
 * after container resolution, before initialize() and register_hooks(). Reflects
 * user intent (a setting, a per-request state); environment and dependency gating
 * belongs on the Feature's {@see \DeepWebSolutions\Framework\Core\Conditional\ConditionalInterface}
 * instead, so the kernel can skip a whole Feature before resolving it.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
interface EnabledInterface {
	/**
	 * Whether this component should run for the current request.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  bool
	 */
	public function is_enabled(): bool;
}
