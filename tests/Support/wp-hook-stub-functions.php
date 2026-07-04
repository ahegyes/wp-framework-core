<?php declare( strict_types=1 );

// Global-namespace add_filter()/remove_filter() stubs mirroring WordPress's hook-table behavior
// over FakeWordPressHook objects, so Unit tests can exercise PluginKernel's diff-based rollback
// without WordPress loaded. The function_exists() guards keep the Integration suite (real
// WordPress) from ever seeing these.

use DeepWebSolutions\Framework\Core\Tests\Support\FakeWordPressHook;

if ( ! \function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook_name ] ) ) {
			$wp_filter[ $hook_name ] = new FakeWordPressHook();
		}

		$wp_filter[ $hook_name ]->add_filter( $callback, $priority, $accepted_args );

		return true;
	}
}

if ( ! \function_exists( 'remove_filter' ) ) {
	function remove_filter( string $hook_name, callable $callback, int $priority = 10 ): bool {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook_name ] ) ) {
			return false;
		}

		$removed = $wp_filter[ $hook_name ]->remove_filter( $callback, $priority );

		if ( array() === $wp_filter[ $hook_name ]->callbacks ) {
			unset( $wp_filter[ $hook_name ] );
		}

		return $removed;
	}
}
