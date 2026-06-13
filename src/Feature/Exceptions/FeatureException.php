<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Feature\Exceptions;

use DeepWebSolutions\Framework\Shared\Exception\AbstractRuntimeException;

/**
 * Thrown when a Feature's declared contract is violated at boot time.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class FeatureException extends AbstractRuntimeException {}
