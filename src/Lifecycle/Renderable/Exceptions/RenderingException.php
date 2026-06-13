<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Lifecycle\Renderable\Exceptions;

use DeepWebSolutions\Framework\Shared\Exception\AbstractRuntimeException;

/**
 * Thrown when a Renderable component's render() fails unrecoverably.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class RenderingException extends AbstractRuntimeException {}
