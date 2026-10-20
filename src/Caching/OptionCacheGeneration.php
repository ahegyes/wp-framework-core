<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Caching;

use DeepWebSolutions\Framework\Shared\Caching\CacheGenerationInterface;
use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;

\defined( 'ABSPATH' ) || exit;

/**
 * Keeps a cache generation in an autoloaded option of the current site, as a random token.
 *
 * A random token, unlike a counter, never repeats once the option is deleted, so an uninstall followed by a reinstall cannot revive older entries.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final class OptionCacheGeneration implements CacheGenerationInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * The length of the option_name column, past which the database truncates a name onto another option's without an error.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     int
	 */
	protected const int MAX_OPTION_LENGTH = 191;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $option The option that stores the generation.
	 *
	 * @throws  InvalidArgumentException Thrown when the option name is empty, surrounded by whitespace or longer than 191 characters.
	 */
	public function __construct(
		protected string $option
	) {
		if ( '' === $option || \trim( $option ) !== $option || \mb_strlen( $option ) > self::MAX_OPTION_LENGTH ) {
			throw new InvalidArgumentException( "Option name '$option' is empty, surrounded by whitespace or longer than 191 characters." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}
	}

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function get(): ?string {
		$generation = \get_option( $this->option );

		return \is_string( $generation ) && '' !== $generation ? $generation : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function advance(): ?string {
		$generation = \bin2hex( \random_bytes( 8 ) );

		// Autoloaded, because every read of the cache needs the generation.
		return \update_option( $this->option, $generation, true ) ? $generation : null;
	}

	// endregion
}
