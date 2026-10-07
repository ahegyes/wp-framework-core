<?php declare( strict_types=1 );
/**
 * Defines the helper functions of the core package.
 *
 * @since   2.0.0
 * @version 2.0.0
 * @package DeepWebSolutions\Framework
 */

namespace DeepWebSolutions\Framework;

use DeepWebSolutions\Framework\Shared\Exception\LogicException;
use Psr\Container\ContainerInterface;

if ( ! \defined( 'ABSPATH' ) ) {
	return; // Since this file is autoloaded by Composer, 'exit' breaks all external dev tools.
}

/**
 * Returns the container entry for a class, typed as that class.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @template T of object
 *
 * @param   ContainerInterface $container  The container to read from.
 * @param   class-string<T>    $class_name The class of the entry.
 *
 * @throws  LogicException Thrown when the entry is not an instance of the class.
 *
 * @return  T
 */
function container_get( ContainerInterface $container, string $class_name ): object {
	$entry = $container->get( $class_name );
	if ( ! $entry instanceof $class_name ) {
		throw new LogicException( "The container entry '$class_name' has type '" . \get_debug_type( $entry ) . "', not that class." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
	}

	return $entry;
}
