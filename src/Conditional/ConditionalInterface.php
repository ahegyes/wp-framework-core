<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Conditional;

/**
 * Pre-resolution gate. The kernel checks is_met() before resolving any Feature
 * gated by this conditional, so it MUST NOT depend on that Feature's components and
 * SHOULD be cheap (reading an option/transient is fine).
 *
 * @since   2.0.0
 * @version 2.0.0
 */
interface ConditionalInterface {
	/**
	 * Whether the conditional is satisfied.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  bool
	 */
	public function is_met(): bool;
}
