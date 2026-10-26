<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Caching;

use DeepWebSolutions\Framework\Core\Caching\Exceptions\InvalidCacheKeyException;
use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use Psr\SimpleCache\CacheInterface as PsrCacheInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Caches values in a group of the current site's object cache, and clears them all at once by changing the group's salt.
 *
 * Each entry is named by its key's hash, which never equals the group's last_changed key, and holds the salt it was stored under, so that clear() leaves one entry per key for the object cache to evict.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class ObjectCache implements PsrCacheInterface {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $group The object cache group, under the plugin's own prefix.
	 *
	 * @throws  InvalidArgumentException Thrown when the group is not a lowercase letter followed by up to 63 lowercase letters, digits, underscores and hyphens.
	 */
	public function __construct(
		protected string $group
	) {
		if ( 1 !== \preg_match( '/\A[a-z][a-z0-9_-]{0,63}\z/', $group ) ) {
			throw new InvalidArgumentException( "Group '$group' is not a lowercase letter followed by up to 63 lowercase letters, digits, underscores and hyphens." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}
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
		$lifetime = CacheEntries::get_lifetime( $ttl );
		if ( ! \is_null( $lifetime ) && $lifetime <= 0 ) {
			return $this->delete( $key );
		}

		$name = \md5( CacheEntries::check_key( $key ) );
		return wp_cache_set( $name, CacheEntries::wrap( $value, $this->get_salt() ), $this->group, $lifetime ?? 0 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function delete( string $key ): bool {
		$name = \md5( CacheEntries::check_key( $key ) );
		return wp_cache_delete( $name, $this->group ) || $this->is_absent( $name );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function clear(): bool {
		wp_cache_set_last_changed( $this->group );
		return wp_cache_set( 'last_changed', \bin2hex( \random_bytes( 8 ) ), $this->group ); // The last_changed time repeats within a microsecond, so an entry stored just before it could keep its salt.
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
		if ( array() === $keys ) {
			return array();
		}

		$salt    = $this->find_salt();
		$names   = \array_map( 'md5', $keys );
		$entries = \is_null( $salt ) ? array() : wp_cache_get_multiple( $names, $this->group );

		$values = array();
		foreach ( $keys as $index => $key ) {
			$entry          = \is_null( $salt ) ? null : CacheEntries::unwrap( $entries[ $names[ $index ] ] ?? false, $salt );
			$values[ $key ] = \is_null( $entry ) ? $default : $entry[0];
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
	 * @param   null|int|\DateInterval $ttl    Optional. The lifetime of each entry, where null keeps it until the object cache evicts it and zero or less deletes it. Default is null.
	 *
	 * @throws  InvalidCacheKeyException Thrown when a key is invalid, before any is written.
	 *
	 * @return  bool
	 */
	#[\Override]
	public function setMultiple( iterable $values, null|int|\DateInterval $ttl = null ): bool {
		$entries = array();
		foreach ( $values as $key => $value ) {
			$entries[ \md5( CacheEntries::check_key( $key ) ) ] = $value;
		}

		$lifetime = CacheEntries::get_lifetime( $ttl );
		if ( ! \is_null( $lifetime ) && $lifetime <= 0 ) {
			return $this->delete_names( \array_keys( $entries ) );
		}

		if ( array() === $entries ) {
			return true;
		}

		$salt = $this->get_salt();
		foreach ( $entries as $name => $value ) {
			$entries[ $name ] = CacheEntries::wrap( $value, $salt );
		}

		$stored = wp_cache_set_multiple( $entries, $this->group, $lifetime ?? 0 );
		return \array_all( \array_keys( $entries ), fn( $name ) => true === ( $stored[ $name ] ?? false ) );
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
		return $this->delete_names( \array_map( 'md5', CacheEntries::get_keys( $keys ) ) );
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
		$name = \md5( CacheEntries::check_key( $key ) );
		$salt = $this->find_salt();

		return \is_null( $salt ) ? null : CacheEntries::unwrap( wp_cache_get( $name, $this->group ), $salt );
	}

	/**
	 * Returns the group's salt, or null when it has none.
	 *
	 * Read directly, because wp_cache_get_last_changed() stores a salt and fires an action for a group without one.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  string|null
	 */
	protected function find_salt(): ?string {
		$salt = wp_cache_get( 'last_changed', $this->group );
		return \is_string( $salt ) && '' !== $salt ? $salt : null;
	}

	/**
	 * Returns the group's salt, after starting one when it has none.
	 *
	 * Started through wp_cache_set_last_changed(), because wp_cache_get_last_changed() would return a stored salt that no read accepts.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  string
	 */
	protected function get_salt(): string {
		return $this->find_salt() ?? wp_cache_set_last_changed( $this->group );
	}

	/**
	 * Deletes the named entries and returns whether every one is gone.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   list<string> $names The entry names.
	 *
	 * @return  bool
	 */
	protected function delete_names( array $names ): bool {
		if ( array() === $names ) {
			return true;
		}

		$deleted = wp_cache_delete_multiple( $names, $this->group );
		$gone    = true;
		foreach ( $names as $name ) {
			$gone = ( true === ( $deleted[ $name ] ?? false ) || $this->is_absent( $name ) ) && $gone;
		}

		return $gone;
	}

	/**
	 * Returns whether the group holds nothing under the name.
	 *
	 * The object cache also reports a failed delete for a name it does not hold, which PSR-16 counts as deleted.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $name The entry name.
	 *
	 * @return  bool
	 */
	protected function is_absent( string $name ): bool {
		wp_cache_get( $name, $this->group, false, $found );
		return true !== $found;
	}

	// endregion
}
