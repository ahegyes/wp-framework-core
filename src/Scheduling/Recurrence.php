<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Scheduling;

use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;

\defined( 'ABSPATH' ) || exit;

/**
 * The fixed interval of a recurring hook, of a minute or more.
 *
 * The cadence is approximate, because WP-Cron keeps each chain's phase while Action Scheduler counts every interval from the previous completion.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final readonly class Recurrence {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int $interval The interval in seconds.
	 */
	protected function __construct(
		public int $interval
	) {}

	// endregion

	// region FACTORY METHODS

	/**
	 * Returns a recurrence at a fixed interval.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   int $interval The interval in seconds, at least 60.
	 *
	 * @throws  InvalidArgumentException Thrown when the interval is under a minute.
	 *
	 * @return  self
	 */
	public static function from_interval( int $interval ): self {
		if ( $interval < 60 ) {
			throw new InvalidArgumentException( "Interval '$interval' is under the minimum of 60 seconds." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		return new self( $interval );
	}

	// endregion
}
