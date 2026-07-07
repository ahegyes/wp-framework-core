<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Lifecycle\Hookable\Exceptions;

use DeepWebSolutions\Framework\Shared\Exception\AbstractRuntimeException;

/**
 * Thrown by {@see \DeepWebSolutions\Framework\Core\Lifecycle\Hookable\HookableInterface::register_hooks()}
 * implementations when hook registration fails unrecoverably.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class HookRegistrationException extends AbstractRuntimeException {}
