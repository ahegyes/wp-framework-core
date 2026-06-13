<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Lifecycle\Renderable;

/**
 * A component that RETURNS user-visible markup as a string. For WP surfaces whose
 * callback must return (not echo) — a Gutenberg block render_callback, a shortcode
 * handler. For echo surfaces (settings page, metabox) use {@see \DeepWebSolutions\Framework\Core\Lifecycle\Outputtable\OutputtableInterface}.
 * Invoked from the component's own WP callback, not by the kernel.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
interface RenderableInterface {
	/**
	 * Return this component's markup.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  Exceptions\RenderingException On unrecoverable render failure.
	 *
	 * @return  string
	 */
	public function render(): string;
}
