<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Rendering\Renderable\Exceptions;

use DeepWebSolutions\Framework\Shared\Exception\AbstractRuntimeException;

/**
 * Thrown by {@see \DeepWebSolutions\Framework\Core\Rendering\Renderable\RenderableInterface::render()}
 * implementations when rendering fails unrecoverably.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class RenderingException extends AbstractRuntimeException {}
