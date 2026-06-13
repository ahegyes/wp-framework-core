<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Enabled;

/**
 * Post-resolution gate. The kernel calls is_enabled() on a constructed component
 * after container resolution, before initialize() and register_hooks(). Reflects
 * user intent (a setting, a per-request state); environment and dependency gating
 * belongs on the Feature's {@see \DeepWebSolutions\Framework\Core\Conditional\ConditionalInterface}
 * instead, so the kernel can skip a whole Feature before resolving it.
 *
 * The kernel evaluates this once at boot, before the current user and other plugins'
 * capability filters are fully settled, so it is a coarse attach-time gate rather than
 * an authoritative per-request permission check: a component that also performs a
 * privileged action MUST re-check the capability inside the hook callback that performs
 * it, not rely on is_enabled() alone.
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
