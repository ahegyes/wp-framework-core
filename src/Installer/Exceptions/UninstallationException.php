<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Installer\Exceptions;

use DeepWebSolutions\Framework\Shared\Exception\AbstractRuntimeException;

/**
 * Thrown by {@see \DeepWebSolutions\Framework\Core\Installer\InstallerInterface::uninstall()}
 * implementations when uninstall work fails.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class UninstallationException extends AbstractRuntimeException {}
