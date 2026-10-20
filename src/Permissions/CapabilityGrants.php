<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Permissions;

use DeepWebSolutions\Framework\ComponentInterface;
use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use Psr\Log\LoggerInterface as PsrLoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Grants a plugin's capabilities to the roles that exist on each site, and records each grant in a ledger, so that a role created later still receives them.
 *
 * WP_Roles::add_cap() does nothing for a missing role and core fires no action when a role is added, so a grant made once, at install, never reaches a role that appears afterwards.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @phpstan-type Pairs array<non-empty-string, list<non-empty-string>>
 */
final class CapabilityGrants implements ComponentInterface {
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
	 * @param   string             $ledger_option The option that stores, on each site, the fingerprint of the last run and the grants made, autoloaded.
	 * @param   Pairs              $grants        The capabilities to grant, as lists keyed by role name.
	 * @param   PsrLoggerInterface $logger        The logger that records the grants that did not persist.
	 *
	 * @throws  InvalidArgumentException Thrown when the option name or the grants are invalid.
	 */
	public function __construct(
		protected string $ledger_option,
		protected array $grants,
		protected PsrLoggerInterface $logger
	) {
		if ( '' === $ledger_option || \trim( $ledger_option ) !== $ledger_option || \mb_strlen( $ledger_option ) > self::MAX_OPTION_LENGTH ) {
			throw new InvalidArgumentException( "Option name '$ledger_option' is empty, surrounded by whitespace or longer than 191 characters." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		foreach ( $grants as $role => $caps ) {
			if ( ! \is_string( $role ) || '' === $role || ! \is_array( $caps ) || ! \array_is_list( $caps ) || ! \array_all( $caps, static fn ( mixed $cap ): bool => \is_string( $cap ) && '' !== $cap ) ) {
				throw new InvalidArgumentException( "Role '$role' is not a non-empty name with a list of non-empty capability names." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
			}
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
	public function register_hooks(): void {
		\add_action( 'wp_loaded', array( $this, 'run' ) );
	}

	// endregion

	// region METHODS

	/**
	 * Grants each declared capability that its role lacks and the ledger does not list, unless nothing it depends on changed since the last run.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function run(): void {
		$roles       = \wp_roles();
		$fingerprint = $this->get_fingerprint( $roles );
		$row         = \get_option( $this->ledger_option );
		if ( \is_array( $row ) && ( $row['fingerprint'] ?? null ) === $fingerprint ) {
			return;
		}

		$ledger = self::get_pairs( \is_array( $row ) ? $row['grants'] ?? null : null );
		foreach ( $this->grants as $role => $caps ) {
			$object = $roles->get_role( $role );
			if ( \is_null( $object ) ) {
				continue;
			}

			foreach ( $caps as $cap ) {
				if ( ! \in_array( $cap, $ledger[ $role ] ?? array(), true ) && ! \array_key_exists( $cap, $object->capabilities ) ) {
					$object->add_cap( $cap );
					$ledger[ $role ][] = $cap;
				}
			}
		}

		\update_option(
			$this->ledger_option,
			array(
				'v'           => 1,
				'fingerprint' => $fingerprint,
				'grants'      => $ledger,
			),
			true
		);
	}

	// endregion

	// region HELPERS

	/**
	 * Returns a hash of the declared grants and of the declared roles that exist, which changes whenever a run could grant something new.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   \WP_Roles $roles The site's roles.
	 *
	 * @return  string
	 */
	protected function get_fingerprint( \WP_Roles $roles ): string {
		return \md5( (string) \wp_json_encode( array( $this->grants, \array_values( \array_filter( \array_keys( $this->grants ), $roles->is_role( ... ) ) ) ) ) );
	}

	/**
	 * Returns the stored pairs that are lists of non-empty capability names keyed by non-empty role names, dropping the rest.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed $value The stored value.
	 *
	 * @return  Pairs
	 */
	protected static function get_pairs( mixed $value ): array {
		$pairs = array();
		foreach ( \is_array( $value ) ? $value : array() as $role => $caps ) {
			if ( \is_string( $role ) && '' !== $role && \is_array( $caps ) ) {
				$pairs[ $role ] = \array_values( \array_filter( $caps, static fn ( mixed $cap ): bool => \is_string( $cap ) && '' !== $cap ) );
			}
		}

		return $pairs;
	}

	// endregion
}
