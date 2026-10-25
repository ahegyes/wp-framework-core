<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Caching;

use DeepWebSolutions\Framework\Core\Caching\Exceptions\InvalidCacheKeyException;
use DeepWebSolutions\Framework\Core\Storage\OptionRows;
use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use Psr\SimpleCache\CacheInterface as PsrCacheInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Caches values as transients of the current site under a prefix, and clears them all at once by starting a new generation.
 *
 * Each entry is named by its key's hash, because the options table matches names regardless of letter case and accents and drops the timeout of a transient name past 172 characters, and holds the generation it was stored under, so that clear() leaves one row per key rather than one per generation.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class TransientCache implements PsrCacheInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * The lifetime in seconds of an entry stored without one, so that every entry expires and none is autoloaded.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     int
	 */
	protected const int DEFAULT_TTL = \DAY_IN_SECONDS;

	/**
	 * The length of the longest prefix, which leaves room for a key's hash within the 172 characters of a transient name.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     int
	 */
	protected const int MAX_PREFIX_LENGTH = 140;

	/**
	 * The option of the current site that keeps the generation whose entries the cache reads.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     string
	 */
	protected string $generation_option;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string      $prefix            The prefix of every transient name, under the plugin's own prefix.
	 * @param   string|null $generation_option Optional. The option that keeps the generation, which caches that clear together share. Default is the prefix followed by cache_generation.
	 *
	 * @throws  InvalidArgumentException Thrown when the prefix is empty, surrounded by whitespace or longer than 140 characters, or the option name is empty, surrounded by whitespace or longer than 191 characters.
	 */
	public function __construct(
		protected string $prefix,
		?string $generation_option = null
	) {
		OptionRows::check_name( $prefix, self::MAX_PREFIX_LENGTH );
		$this->generation_option = $generation_option ?? "{$prefix}cache_generation";
		OptionRows::check_name( $this->generation_option );
	}

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function get( string $key, mixed $default = null ): mixed { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- PSR-16 names the parameter, and callers may pass it by name.
		$entry = $this->read( $key );

		return \is_null( $entry ) ? $default : $entry[0];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function set( string $key, mixed $value, null|int|\DateInterval $ttl = null ): bool {
		$name     = $this->get_name( $key );
		$lifetime = CacheEntries::get_lifetime( $ttl ) ?? self::DEFAULT_TTL;
		if ( $lifetime <= 0 ) {
			return $this->delete( $key );
		}

		$generation = $this->get_generation() ?? $this->start_generation();
		if ( \is_null( $generation ) ) {
			return false;
		}

		$entry = CacheEntries::wrap( $value, $generation );

		// set_transient() also returns false when the store already holds the entry.
		return \set_transient( $name, $entry, $lifetime ) || \maybe_serialize( \get_transient( $name ) ) === \maybe_serialize( $entry );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @global  \wpdb $wpdb The WordPress database connection.
	 */
	#[\Override]
	public function delete( string $key ): bool {
		global $wpdb;

		$name = $this->get_name( $key );
		$wpdb->flush();
		$deleted = \delete_transient( $name );
		if ( \wp_using_ext_object_cache() || \wp_installing() ) {
			return $deleted || false === \get_transient( $name );
		}

		// An absent entry and a failed delete both make delete_transient() return false, and delete_option() marks the option absent in either case, so only the query error tells them apart, and it also shows a timeout row left behind.
		return '' === $wpdb->last_error;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function clear(): bool {
		return ! \is_null( $this->start_generation() );
	}

	/**
	 * Returns the values of the keys, keyed by key, with the default for each key that misses.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   iterable<mixed> $keys    The keys, as strings or integers.
	 * @param   mixed           $default Optional. The value of a key that misses. Default is null.
	 *
	 * @throws  InvalidCacheKeyException Thrown when a key is invalid, before any is read.
	 *
	 * @return  array<string, mixed>
	 */
	#[\Override]
	public function getMultiple( iterable $keys, mixed $default = null ): iterable { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- PSR-16 names the parameter, and callers may pass it by name.
		$keys = CacheEntries::get_keys( $keys );

		// On the options table, get_transient() reads each entry's rows in a query of its own.
		if ( ! \wp_using_ext_object_cache() && ! \wp_installing() ) {
			$options = array();
			foreach ( $keys as $key ) {
				$name = $this->get_name( $key );
				\array_push( $options, "_transient_$name", "_transient_timeout_$name" );
			}
			\wp_prime_option_caches( $options );
		}

		$values = array();
		foreach ( $keys as $key ) {
			$values[ $key ] = $this->get( $key, $default );
		}

		return $values;
	}

	/**
	 * Stores the values under their keys and returns whether every one was stored.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   iterable<mixed>        $values The values, keyed by string or integer key.
	 * @param   null|int|\DateInterval $ttl    Optional. The lifetime of each entry, where null is a day and zero or less deletes it. Default is null.
	 *
	 * @throws  InvalidCacheKeyException Thrown when a key is invalid, before any is written.
	 *
	 * @return  bool
	 */
	#[\Override]
	public function setMultiple( iterable $values, null|int|\DateInterval $ttl = null ): bool {
		$entries = array();
		foreach ( $values as $key => $value ) {
			$entries[] = array( CacheEntries::check_key( $key ), $value );
		}

		$stored = true;
		foreach ( $entries as list( $key, $value ) ) {
			$stored = $this->set( $key, $value, $ttl ) && $stored;
		}

		return $stored;
	}

	/**
	 * Deletes the keys' entries and returns whether every one is gone.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   iterable<mixed> $keys The keys, as strings or integers.
	 *
	 * @throws  InvalidCacheKeyException Thrown when a key is invalid, before any is deleted.
	 *
	 * @return  bool
	 */
	#[\Override]
	public function deleteMultiple( iterable $keys ): bool {
		$deleted = true;
		foreach ( CacheEntries::get_keys( $keys ) as $key ) {
			$deleted = $this->delete( $key ) && $deleted;
		}

		return $deleted;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function has( string $key ): bool {
		return ! \is_null( $this->read( $key ) );
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the stored value of the key wrapped in a list, or null when the key misses.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $key The key.
	 *
	 * @throws  InvalidCacheKeyException Thrown when the key is invalid.
	 *
	 * @return  array{mixed}|null
	 */
	protected function read( string $key ): ?array {
		$name       = $this->get_name( $key );
		$generation = $this->get_generation();
		if ( \is_null( $generation ) ) {
			return null;
		}

		return CacheEntries::unwrap( \get_transient( $name ), $generation );
	}

	/**
	 * Returns the current generation, or null when none has started.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  string|null
	 */
	protected function get_generation(): ?string {
		$generation = \get_option( $this->generation_option );
		return \is_string( $generation ) && '' !== $generation ? $generation : null;
	}

	/**
	 * Starts a generation as a new random token and returns it, or null when it cannot be stored.
	 *
	 * A random token, unlike a counter, never repeats once the option is deleted, so an uninstall followed by a reinstall cannot revive older entries.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  string|null
	 */
	protected function start_generation(): ?string {
		$generation = \bin2hex( \random_bytes( 8 ) );
		return \update_option( $this->generation_option, $generation, true ) ? $generation : null; // Autoloaded, because every read of the cache needs the generation.
	}

	/**
	 * Returns the transient name of the key's entry.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $key The key.
	 *
	 * @throws  InvalidCacheKeyException Thrown when the key is invalid.
	 *
	 * @return  string
	 */
	protected function get_name( string $key ): string {
		return $this->prefix . \md5( CacheEntries::check_key( $key ) );
	}

	// endregion
}
