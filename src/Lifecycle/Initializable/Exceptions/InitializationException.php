<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Lifecycle\Initializable\Exceptions;

use DeepWebSolutions\Framework\Shared\Exception\AbstractRuntimeException;

/**
 * Thrown by {@see \DeepWebSolutions\Framework\Core\Lifecycle\Initializable\InitializableInterface::initialize()}
 * implementations when one-time setup fails.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class InitializationException extends AbstractRuntimeException {}
