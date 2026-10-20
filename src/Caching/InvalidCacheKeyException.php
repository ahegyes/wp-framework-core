<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Caching;

use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;

\defined( 'ABSPATH' ) || exit;

/**
 * An exception thrown when a cache key is not one PSR-16 allows.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class InvalidCacheKeyException extends InvalidArgumentException implements PsrInvalidArgumentException {}
