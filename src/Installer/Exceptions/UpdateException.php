<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Installer\Exceptions;

use DeepWebSolutions\Framework\Shared\Exception\AbstractRuntimeException;

/**
 * Thrown by {@see \DeepWebSolutions\Framework\Core\Installer\InstallerInterface::update()}
 * implementations when updating from a previously stored version fails.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class UpdateException extends AbstractRuntimeException {}
