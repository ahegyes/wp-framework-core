<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Clock;

use Psr\Clock\ClockInterface as PsrClockInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Tells the current time from the system clock.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class SystemClock implements PsrClockInterface {
	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function now(): \DateTimeImmutable {
		return new \DateTimeImmutable();
	}

	// endregion
}
