<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Lifecycle\Outputtable;

/**
 * A component that ECHOES user-visible output. For WP surfaces whose callback is
 * expected to print directly — a settings page render, an add_meta_box callback.
 * For surfaces whose callback must RETURN markup (block render_callback, shortcode)
 * use {@see \DeepWebSolutions\Framework\Core\Lifecycle\Renderable\RenderableInterface}.
 * Invoked from the component's own WP callback, not by the kernel.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
interface OutputtableInterface {
	/**
	 * Echo this component's output.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  Exceptions\OutputException On unrecoverable output failure.
	 */
	public function output(): void;
}
