<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Installer\Exceptions;

use DeepWebSolutions\Framework\Shared\Exception\AbstractRuntimeException;

/**
 * Thrown by {@see \DeepWebSolutions\Framework\Core\Installer\InstallerInterface::install()}
 * implementations when first-time install work fails.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class InstallationException extends AbstractRuntimeException {}
