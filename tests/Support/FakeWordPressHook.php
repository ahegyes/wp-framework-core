<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Tests\Support;

/**
 * Minimal WP_Hook stand-in for Unit tests: a public ->callbacks table in WordPress's exact
 * shape ([priority => [idx => ['function' => callable, 'accepted_args' => int]]]) plus the
 * add/remove mechanics the global add_filter()/remove_filter() stubs delegate to.
 */
final class FakeWordPressHook {
	/**
	 * @var array<int, array<string, array{function: callable, accepted_args: int}>>
	 */
	public array $callbacks = array();

	public function add_filter( callable $callback, int $priority, int $accepted_args ): void {
		$priority_existed = isset( $this->callbacks[ $priority ] );

		$this->callbacks[ $priority ][ self::build_unique_id( $callback ) ] = array(
			'function'      => $callback,
			'accepted_args' => $accepted_args,
		);

		if ( ! $priority_existed && \count( $this->callbacks ) > 1 ) {
			\ksort( $this->callbacks, SORT_NUMERIC );
		}
	}

	public function remove_filter( callable $callback, int $priority ): bool {
		$idx    = self::build_unique_id( $callback );
		$exists = isset( $this->callbacks[ $priority ][ $idx ] );

		if ( $exists ) {
			unset( $this->callbacks[ $priority ][ $idx ] );
			if ( array() === $this->callbacks[ $priority ] ) {
				unset( $this->callbacks[ $priority ] );
			}
		}

		return $exists;
	}

	/**
	 * Mirrors _wp_filter_build_unique_id() so a callback re-added during rollback lands on
	 * the same idx key it held before, keeping restored tables byte-equal.
	 */
	public static function build_unique_id( callable $callback ): string {
		if ( \is_string( $callback ) ) {
			return $callback;
		}

		if ( \is_object( $callback ) ) {
			// Closures are currently implemented as objects.
			$callback = array( $callback, '' );
		}

		\assert( \is_array( $callback ) );

		if ( \is_object( $callback[0] ) ) {
			return \spl_object_hash( $callback[0] ) . $callback[1];
		}

		return $callback[0] . '::' . $callback[1];
	}
}
