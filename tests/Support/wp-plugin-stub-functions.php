<?php declare( strict_types=1 );

// Global-namespace plugin_basename() stub plus a WP_PLUGIN_DIR fallback pointing at a
// directory that does not exist, so Unit tests can construct PluginHeader through the
// bootstrap metadata reader's unreadable-file path without WordPress loaded. The
// defined()/function_exists() guards keep the Integration suite (real WordPress) from
// ever seeing these.

if ( ! \defined( 'WP_PLUGIN_DIR' ) ) {
	\define( 'WP_PLUGIN_DIR', \sys_get_temp_dir() . '/dws-nonexistent-plugin-dir' );
}

if ( ! \function_exists( 'plugin_basename' ) ) {
	function plugin_basename( string $file ): string {
		$plugin_dir = \rtrim( \str_replace( '\\', '/', WP_PLUGIN_DIR ), '/' );
		$file       = \str_replace( '\\', '/', $file );
		$file       = (string) \preg_replace( '#^' . \preg_quote( $plugin_dir, '#' ) . '/#', '', $file );

		return \ltrim( $file, '/' );
	}
}
