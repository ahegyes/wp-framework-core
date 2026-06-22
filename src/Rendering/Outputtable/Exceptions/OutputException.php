<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Rendering\Outputtable\Exceptions;

use DeepWebSolutions\Framework\Shared\Exception\AbstractRuntimeException;

/**
 * Thrown when an Outputtable component's output() fails unrecoverably.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class OutputException extends AbstractRuntimeException {}
