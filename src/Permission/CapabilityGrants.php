<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Permission;

use DeepWebSolutions\Framework\Core\ComponentInterface;
use DeepWebSolutions\Framework\Core\Storage\OptionRows;
use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use DeepWebSolutions\Framework\Shared\Exception\RuntimeException;
use Psr\Log\LoggerInterface as PsrLoggerInterface;

\defined( 'ABSPATH' ) || exit;

/**
 * Grants a plugin's capabilities to the roles that exist on each site, and records each grant in a ledger, so that a role created later still receives them and a pair the plugin stops declaring is revoked.
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
	 * @param   PsrLoggerInterface $logger        The logger that records the capability changes that did not persist.
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
		\add_action( 'updated_option', array( $this, 'forget_deleted_roles' ), 10, 3 );
	}

	// endregion

	// region METHODS

	/**
	 * Revokes each ledgered pair no longer declared and grants each declared pair that its role lacks and the ledger does not list, once the declaration or the declared roles have changed since the last complete run.
	 *
	 * A ledgered pair that its role no longer holds, or holds as denied, was changed by hand, so it is never granted again.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	public function run(): void {
		$roles       = \wp_roles();
		$fingerprint = $this->get_fingerprint( $roles );
		$row         = $this->get_row();
		if ( $row['fingerprint'] === $fingerprint ) {
			return;
		}

		// WP_Roles::add_cap() and remove_cap() change the roles array but not the role objects, so the array holds what the request has stored.
		$owned = $row['grants'];
		foreach ( $row['pending'] as $role => $caps ) {
			foreach ( $caps as $cap ) {
				// A pending pair that its role holds was granted by a run that did not finish.
				if ( true === ( self::get_capabilities( $roles->roles, $role )[ $cap ] ?? null ) && ! \in_array( $cap, $owned[ $role ] ?? array(), true ) ) {
					$owned[ $role ][] = $cap;
				}
			}
		}

		$ledger  = array();
		$revoked = array();
		foreach ( $owned as $role => $caps ) {
			$held = self::get_capabilities( $roles->roles, $role );
			if ( \is_null( $held ) ) {
				continue;
			}

			foreach ( $caps as $cap ) {
				if ( \in_array( $cap, $this->grants[ $role ] ?? array(), true ) ) {
					$ledger[ $role ][] = $cap;
				} elseif ( (bool) ( $held[ $cap ] ?? false ) ) {
					$roles->get_role( $role )?->remove_cap( $cap );
					$revoked[ $role ][] = $cap;
				}
			}
		}

		$granted = array();
		foreach ( $this->grants as $role => $caps ) {
			$held = self::get_capabilities( $roles->roles, $role );
			if ( \is_null( $held ) ) {
				continue;
			}

			foreach ( $caps as $cap ) {
				if ( ! \in_array( $cap, $ledger[ $role ] ?? array(), true ) && ! \array_key_exists( $cap, $held ) ) {
					$granted[ $role ][] = $cap;
				}
			}
		}

		// The pending write precedes the grants, so a request killed after granting leaves the next run a record that tells its grants from hand grants.
		if ( array() !== $granted ) {
			$this->write_row( '', $ledger, $granted );
			foreach ( $granted as $role => $caps ) {
				foreach ( $caps as $cap ) {
					$roles->get_role( $role )?->add_cap( $cap );
				}
			}
		}

		$failed  = array();
		$pending = array();
		$stored  = $this->get_stored_roles( $roles->role_key );
		foreach ( $granted as $role => $caps ) {
			foreach ( $caps as $cap ) {
				if ( true === ( self::get_capabilities( $stored, $role )[ $cap ] ?? null ) ) {
					$ledger[ $role ][] = $cap;
				} else {
					$pending[ $role ][] = $cap;
					$failed[]           = "grant $cap to $role";
				}
			}
		}
		foreach ( $revoked as $role => $caps ) {
			$kept = self::get_capabilities( $stored, $role );
			foreach ( $caps as $cap ) {
				if ( \is_null( $kept ) || \array_key_exists( $cap, $kept ) ) {
					$ledger[ $role ][] = $cap;
					$failed[]          = "revoke $cap from $role";
				}
			}
		}

		$this->write_row( array() === $failed ? $fingerprint : '', $ledger, $pending );
		if ( array() === $failed ) {
			return;
		}

		$message = 'The roles row did not keep the capability changes {changes}, so the next request makes them again.';
		$context = array( 'changes' => \implode( ', ', $failed ) );
		try {
			$this->logger->warning( $message, $context );
		} catch ( \Throwable $logging_failure ) {
			// A broken logger must neither hide the failure nor break the request on every page load.
			\error_log( "[$this->ledger_option.warning] $message " . \wp_json_encode( $context ) . "\nThe logger failed: $logging_failure" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The injected logger failed.
		}
	}

	// endregion

	// region HOOKS

	/**
	 * Forgets the grants of each declared role that a write of the site's roles row deletes, so that the role is granted them again once it exists anew.
	 *
	 * WooCommerce's Reset capabilities tool deletes and recreates its roles within one request, which leaves the role names that the next run compares unchanged.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $option    The name of the option written.
	 * @param   mixed  $old_value The option's previous value.
	 * @param   mixed  $value     The option's new value.
	 */
	public function forget_deleted_roles( string $option, mixed $old_value, mixed $value ): void {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		if ( ! $wpdb instanceof \wpdb || $wpdb->get_blog_prefix() . 'user_roles' !== $option || ! \is_array( $old_value ) ) {
			return;
		}

		$deleted = \array_diff_key( \array_intersect_key( $old_value, $this->grants ), \is_array( $value ) ? $value : array() );
		if ( array() === $deleted ) {
			return;
		}

		$row = $this->get_row();
		$this->write_row( '', \array_diff_key( $row['grants'], $deleted ), \array_diff_key( $row['pending'], $deleted ) );
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
	 * Returns the stored row, which reads as empty when it is missing or is not a valid version 1 row.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  array{fingerprint: string, grants: Pairs, pending: Pairs}
	 */
	protected function get_row(): array {
		$row = \get_option( $this->ledger_option );
		if ( ! \is_array( $row ) || 1 !== ( $row['v'] ?? null ) ) {
			$row = array();
		}

		return array(
			'fingerprint' => \is_string( $row['fingerprint'] ?? null ) ? $row['fingerprint'] : '',
			'grants'      => self::get_pairs( $row['grants'] ?? null ),
			'pending'     => self::get_pairs( $row['pending'] ?? null ),
		);
	}

	/**
	 * Writes the row, autoloaded, so that the next run's comparison reads no row from the database.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $fingerprint The fingerprint, or an empty string when the next run has work to do.
	 * @param   Pairs  $grants      The ledger of grants made.
	 * @param   Pairs  $pending     The grants about to be made.
	 */
	protected function write_row( string $fingerprint, array $grants, array $pending ): void {
		\update_option(
			$this->ledger_option,
			array(
				'v'           => 1,
				'fingerprint' => $fingerprint,
				'grants'      => $grants,
				'pending'     => $pending,
			),
			true
		);
	}

	/**
	 * Returns the value of the stored roles row, read past the options cache, or null when the row cannot be read.
	 *
	 * A grant changes the request's copy of the roles even when the row keeps its value, so only the row shows what persisted.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $role_key The name of the site's roles option.
	 *
	 * @return  mixed
	 */
	protected function get_stored_roles( string $role_key ): mixed {
		try {
			return ( new OptionRows() )->get( $role_key )?->value;
		} catch ( RuntimeException ) {
			return null;
		}
	}

	/**
	 * Returns the capabilities that a roles array gives the role, or null when it holds no such role.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed  $roles The roles, keyed by name, as WP_Roles holds and stores them.
	 * @param   string $role  The role name.
	 *
	 * @return  array<array-key, mixed>|null
	 */
	protected static function get_capabilities( mixed $roles, string $role ): ?array {
		$data = \is_array( $roles ) ? $roles[ $role ] ?? null : null;

		return \is_array( $data ) && \is_array( $data['capabilities'] ?? null ) ? $data['capabilities'] : null;
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
