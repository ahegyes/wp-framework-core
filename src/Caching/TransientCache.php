<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Caching;

use DeepWebSolutions\Framework\Shared\Caching\CacheGenerationInterface;
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
	 * The characters PSR-16 reserves, which no key may hold.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     string
	 */
	protected const string RESERVED_CHARACTERS = '{}()/\\@:';

	/**
	 * The generation whose entries the cache reads.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     CacheGenerationInterface
	 */
	protected CacheGenerationInterface $generation;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string                        $prefix     The prefix of every transient name, under the plugin's own prefix.
	 * @param   CacheGenerationInterface|null $generation Optional. The generation. Default is one kept in the option named by the prefix followed by cache_generation.
	 *
	 * @throws  InvalidArgumentException Thrown when the prefix is empty, surrounded by whitespace or longer than 140 characters.
	 */
	public function __construct(
		protected string $prefix,
		?CacheGenerationInterface $generation = null
	) {
		if ( '' === $prefix || \trim( $prefix ) !== $prefix || \mb_strlen( $prefix ) > self::MAX_PREFIX_LENGTH ) {
			throw new InvalidArgumentException( "Cache prefix '$prefix' is empty, surrounded by whitespace or longer than 140 characters." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		$this->generation = $generation ?? new OptionCacheGeneration( "{$prefix}cache_generation" );
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
		$lifetime = $this->get_lifetime( $ttl );
		if ( $lifetime <= 0 ) {
			return $this->delete( $key );
		}

		$generation = $this->generation->get() ?? $this->generation->advance();
		if ( \is_null( $generation ) ) {
			return false;
		}

		$entry = array(
			'value'      => $value,
			'generation' => $generation,
		);

		// set_transient() also returns false when the store already holds the entry.
		return \set_transient( $name, $entry, $lifetime ) || \maybe_serialize( \get_transient( $name ) ) === \maybe_serialize( $entry );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function delete( string $key ): bool {
		$name = $this->get_name( $key );
		if ( \delete_transient( $name ) ) {
			return true;
		}

		if ( \wp_using_ext_object_cache() || \wp_installing() ) {
			return false === \get_transient( $name );
		}

		// An absent entry and a failed delete both make delete_transient() return false, and delete_option() marks the option absent in either case, so only the query error tells them apart.
		$wpdb = $GLOBALS['wpdb'] ?? null;

		return ! ( $wpdb instanceof \wpdb && '' !== $wpdb->last_error );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function clear(): bool {
		return ! \is_null( $this->generation->advance() );
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
		$keys = $this->get_keys( $keys );

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
			$entries[] = array( $this->check_key( $key ), $value );
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
		foreach ( $this->get_keys( $keys ) as $key ) {
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
		$generation = $this->generation->get();
		if ( \is_null( $generation ) ) {
			return null;
		}

		// Other code storing under the name, or a filter, can return something that is not an entry.
		$entry = \get_transient( $name );
		if ( ! \is_array( $entry ) || 2 !== \count( $entry ) || ! \array_key_exists( 'value', $entry ) || ( $entry['generation'] ?? null ) !== $generation ) {
			return null;
		}

		return array( $entry['value'] );
	}

	/**
	 * Returns the lifetime in seconds that the TTL gives an entry stored now.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   null|int|\DateInterval $ttl The TTL, where null is a day.
	 *
	 * @return  int
	 */
	protected function get_lifetime( null|int|\DateInterval $ttl ): int {
		if ( ! $ttl instanceof \DateInterval ) {
			return $ttl ?? self::DEFAULT_TTL;
		}

		$now = new \DateTimeImmutable( '@' . \time() );

		return $now->add( $ttl )->getTimestamp() - $now->getTimestamp();
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
		return $this->prefix . \md5( $this->check_key( $key ) );
	}

	/**
	 * Returns the keys as strings, after checking every one.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   iterable<mixed> $keys The keys, as strings or integers.
	 *
	 * @throws  InvalidCacheKeyException Thrown when a key is invalid.
	 *
	 * @return  list<string>
	 */
	protected function get_keys( iterable $keys ): array {
		$checked = array();
		foreach ( $keys as $key ) {
			$checked[] = $this->check_key( $key );
		}

		return $checked;
	}

	/**
	 * Returns the key as a string, or throws when PSR-16 does not allow it.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed $key The key.
	 *
	 * @throws  InvalidCacheKeyException Thrown when the key is empty, neither a string nor an integer, or holds a reserved character.
	 *
	 * @return  string
	 */
	protected function check_key( mixed $key ): string {
		// PHP turns a numeric array key into an integer, so a key of setMultiple() can arrive as one.
		if ( \is_int( $key ) ) {
			$key = (string) $key;
		}

		if ( ! \is_string( $key ) || '' === $key || false !== \strpbrk( $key, self::RESERVED_CHARACTERS ) ) {
			$shown = \is_string( $key ) ? $key : \get_debug_type( $key );
			throw new InvalidCacheKeyException( "Cache key '$shown' is empty, not a string or holds one of {}()/\\@:." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		return $key;
	}

	// endregion
}
