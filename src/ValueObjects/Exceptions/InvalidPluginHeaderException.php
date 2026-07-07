<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\ValueObjects\Exceptions;

use DeepWebSolutions\Framework\Shared\ValueObject\Exceptions\InvalidValueObjectException;

/**
 * Thrown when a plugin's header data cannot compose a valid PluginHeader value object.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class InvalidPluginHeaderException extends InvalidValueObjectException {
	/**
	 * Identifies the owning value object in invalidity messages.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     string
	 */
	#[\Override]
	protected string $value_object_type { // phpcs:ignore PHPCompatibility.Syntax.RemovedCurlyBraceArrayAccess.Removed -- PHP 8.4 property hook, not array access.
		get => 'PluginHeader';
	}
}
