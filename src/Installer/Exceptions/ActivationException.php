<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Installer\Exceptions;

use DeepWebSolutions\Framework\Shared\Exception\AbstractRuntimeException;

/**
 * Thrown by {@see \DeepWebSolutions\Framework\Core\Installer\InstallerInterface::activate()}
 * implementations when activation work fails.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class ActivationException extends AbstractRuntimeException {}
