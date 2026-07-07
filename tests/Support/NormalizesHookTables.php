<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Tests\Support;

trait NormalizesHookTables {
	/**
	 * The live hook table reduced to tag => callbacks, tag-order-insensitive, so a rolled-back
	 * table can be compared byte-for-byte against the pre-window state.
	 *
	 * @return array<string, array<int, array<string, array{function: callable, accepted_args: int}>>>
	 */
	private function normalized_hook_table(): array {
		$table = array();
		foreach ( $GLOBALS['wp_filter'] ?? array() as $tag => $hook ) {
			$table[ $tag ] = $hook->callbacks;
		}
		\ksort( $table );

		return $table;
	}
}
