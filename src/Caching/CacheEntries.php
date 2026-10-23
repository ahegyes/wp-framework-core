<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Caching;

use DeepWebSolutions\Framework\Core\Caching\Exceptions\InvalidCacheKeyException;

\defined( 'ABSPATH' ) || exit;

/**
 * Checks the keys, computes the lifetimes and wraps the values of the entries the PSR-16 caches store.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @internal
 */
final class CacheEntries {
	// region FIELDS AND CONSTANTS

	/**
	 * The characters PSR-16 reserves, which no key may hold.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     string
	 */
	protected const string RESERVED_CHARACTERS = '{}()/\\@:';

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	protected function __construct() {}

	// endregion

	// region METHODS

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
	public static function check_key( mixed $key ): string {
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
	public static function get_keys( iterable $keys ): array {
		$checked = array();
		foreach ( $keys as $key ) {
			$checked[] = self::check_key( $key );
		}

		return $checked;
	}

	/**
	 * Returns the lifetime in seconds that the TTL gives an entry stored now, or null for a null TTL.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   null|int|\DateInterval $ttl The TTL.
	 *
	 * @return  int|null
	 */
	public static function get_lifetime( null|int|\DateInterval $ttl ): ?int {
		if ( ! $ttl instanceof \DateInterval ) {
			return $ttl;
		}

		$now = new \DateTimeImmutable( '@' . \time() );

		return $now->add( $ttl )->getTimestamp() - $now->getTimestamp();
	}

	/**
	 * Returns the entry that stores the value under the generation.
	 *
	 * The value is serialized here, because an object cache keeps references to the objects in an array it stores.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed  $value      The value.
	 * @param   string $generation The generation the entry belongs to.
	 *
	 * @return  array{value: mixed, generation: string}
	 */
	public static function wrap( mixed $value, string $generation ): array {
		return array(
			'value'      => \maybe_serialize( $value ),
			'generation' => $generation,
		);
	}

	/**
	 * Returns the value of the entry wrapped in a list, or null when the entry is not one of the generation.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed  $entry      The stored entry.
	 * @param   string $generation The current generation.
	 *
	 * @return  array{mixed}|null
	 */
	public static function unwrap( mixed $entry, string $generation ): ?array {
		// Other code storing under the entry's name, or a filter, can return something that is not an entry.
		if ( ! \is_array( $entry ) || 2 !== \count( $entry ) || ! \array_key_exists( 'value', $entry ) || ( $entry['generation'] ?? null ) !== $generation ) {
			return null;
		}

		$value = $entry['value'];

		return array( \is_string( $value ) ? \maybe_unserialize( $value ) : $value );
	}

	// endregion
}
