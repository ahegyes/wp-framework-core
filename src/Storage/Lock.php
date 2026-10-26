<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Storage;

use DeepWebSolutions\Framework\Core\Clock\SystemClock;
use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use DeepWebSolutions\Framework\Shared\Exception\RuntimeException;
use Psr\Clock\ClockInterface as PsrClockInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Leases a named lock to one owner at a time, which refreshes its hold while it works, and lets another owner take the lock over from a hold left past its TTL.
 *
 * The lock is one row of the current site's options table, written only by compare-and-swap, because add_option() overwrites a row inserted after its cached check, while update_option() and delete_option() write whatever the row holds.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @phpstan-type Hold array{owner: non-empty-string, time: positive-int, ttl: positive-int}
 */
final readonly class Lock {
	// region FIELDS AND CONSTANTS

	/**
	 * The clock that dates each hold.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     PsrClockInterface
	 */
	protected PsrClockInterface $clock;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string                 $name  The name of the option that holds the lock, under the plugin's prefix.
	 * @param   int                    $ttl   The seconds that a hold lasts once it is taken or refreshed, from 1.
	 * @param   PsrClockInterface|null $clock Optional. The clock. Default is the system clock.
	 *
	 * @throws  InvalidArgumentException Thrown when the name is not a valid option name or the TTL is below 1 second.
	 */
	public function __construct(
		protected string $name,
		protected int $ttl,
		?PsrClockInterface $clock = null
	) {
		OptionRows::check_name( $name );

		if ( $ttl < 1 ) { // A hold whose TTL is below 1 second expires as it is taken, which would end the exclusion without a sign.
			throw new InvalidArgumentException( "Lock TTL '$ttl' is below 1 second." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		$this->clock = $clock ?? new SystemClock();
	}

	// endregion

	// region METHODS

	/**
	 * Takes the lock for the owner, or takes it over from a hold past its TTL or a row that holds no lock, and returns the outcome.
	 *
	 * A live hold keeps the lock even from its own owner, so that two callers sharing a token never both hold it.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $owner The token that names the holder, unique to it.
	 *
	 * @throws  InvalidArgumentException Thrown when the owner is empty.
	 * @throws  RuntimeException         Thrown when the lock cannot be read or written.
	 *
	 * @return  LockClaim
	 */
	public function acquire( string $owner ): LockClaim {
		if ( '' === $owner ) { // An empty owner would write a row that holds no lock, which the next claim takes over.
			throw new InvalidArgumentException( 'A lock owner cannot be empty.' );
		}

		$now  = $this->clock->now()->getTimestamp();
		$hold = $this->make_hold( $owner, $now );
		if ( ! \is_null( OptionRows::insert( $this->name, $hold ) ) ) {
			return LockClaim::Acquired;
		}

		$row = OptionRows::get( $this->name );
		if ( \is_null( $row ) ) {
			return LockClaim::Busy; // The row was another owner's hold until its release after the insert.
		}

		$held = self::read( $row->value );
		if ( ! \is_null( $held ) && $now <= $held['time'] + $held['ttl'] ) {
			return LockClaim::Busy;
		}

		return \is_null( OptionRows::replace( $row, $hold ) ) ? LockClaim::Busy : LockClaim::TakenOver;
	}

	/**
	 * Extends the owner's hold to a full TTL from now, and returns whether the owner still holds the lock.
	 *
	 * A hold past its TTL is extended too, as long as no other owner has taken the lock over.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $owner The token that names the holder.
	 *
	 * @throws  RuntimeException Thrown when the lock cannot be read or written.
	 *
	 * @return  bool
	 */
	public function refresh( string $owner ): bool {
		$row = OptionRows::get( $this->name );
		if ( \is_null( $row ) || ( self::read( $row->value )['owner'] ?? null ) !== $owner ) {
			return false;
		}

		// Within the second of its last write the row already holds this hold, and the database reports no change for an unchanged value.
		$hold = $this->make_hold( $owner, $this->clock->now()->getTimestamp() );
		return maybe_serialize( $hold ) === $row->raw || ! \is_null( OptionRows::replace( $row, $hold ) );
	}

	/**
	 * Deletes the owner's hold, and leaves the lock alone when another owner holds it.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $owner The token that names the holder.
	 *
	 * @throws  RuntimeException Thrown when the lock cannot be read or deleted.
	 *
	 * @return  void
	 */
	public function release( string $owner ): void {
		$row = OptionRows::get( $this->name );
		if ( ! \is_null( $row ) && ( self::read( $row->value )['owner'] ?? null ) === $owner ) {
			OptionRows::delete( $row );
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the owner's hold, taken at the time.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $owner The token that names the holder.
	 * @param   int    $time  The Unix time at which the hold is taken.
	 *
	 * @return  array{owner: string, time: int, ttl: int}
	 */
	protected function make_hold( string $owner, int $time ): array {
		return array(
			'owner' => $owner,
			'time'  => $time,
			'ttl'   => $this->ttl,
		);
	}

	/**
	 * Returns the hold that a row's value holds, or null when it holds no lock.
	 *
	 * A value holds a lock when its owner is a non-empty string and its time and TTL are positive integers, whatever other keys it has, so that a newer format still holds the lock in a mixed-version deploy.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed $value The row's value.
	 *
	 * @return  Hold|null
	 */
	protected static function read( mixed $value ): ?array {
		if ( ! \is_array( $value ) ) {
			return null;
		}

		$owner = $value['owner'] ?? null;
		$time  = $value['time'] ?? null;
		$ttl   = $value['ttl'] ?? null;
		if ( ! \is_string( $owner ) || '' === $owner || ! \is_int( $time ) || $time < 1 || ! \is_int( $ttl ) || $ttl < 1 ) {
			return null;
		}

		return array(
			'owner' => $owner,
			'time'  => $time,
			'ttl'   => $ttl,
		);
	}

	// endregion
}
