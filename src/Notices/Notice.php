<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Notices;

use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;

\defined( 'ABSPATH' ) || exit;

/**
 * An admin notice: what it says, to whom, on which screens and for how long.
 *
 * Stores keep the row that to_array() returns rather than the object, because each plugin scopes this class under its own namespace.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @phpstan-type NoticeRow array{v: 1, id: string, message: string, type: value-of<NoticeType>, scope: value-of<NoticeScope>, persistent: bool, dismissible: bool, capability: string|null, screens: list<string>, expires_at: int|null, revision: int}
 */
final readonly class Notice {
	// region FIELDS AND CONSTANTS

	/**
	 * The pattern every notice id matches.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     string
	 */
	public const string ID_PATTERN = '/\A[a-z][a-z0-9_-]{0,63}\z/';

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string       $id          The id, unique within the notice's context, matching ID_PATTERN.
	 * @param   string       $message     The message, as HTML in the locale of the request that creates the notice.
	 * @param   NoticeType   $type        Optional. The kind of notice. Default is an error.
	 * @param   NoticeScope  $scope       Optional. Where the notice belongs. Default is a user.
	 * @param   bool         $persistent  Optional. Whether the notice shows until it is dismissed, removed or expired, rather than once to each viewer. Default false.
	 * @param   bool         $dismissible Optional. Whether a viewer can dismiss the notice. Default true.
	 * @param   string|null  $capability  Optional. The capability a viewer needs, which site and network notices require. Default is none.
	 * @param   list<string> $screens     Optional. The ids of the screens the notice shows on. Default is every screen of its scope's surfaces.
	 * @param   int|null     $expires_at  Optional. The Unix time at which the notice expires. Default is never.
	 * @param   int          $revision    Optional. The revision, which a change raises to show the notice again to viewers who dismissed or were shown an earlier one. Default 1.
	 *
	 * @throws  InvalidArgumentException Thrown when the id, capability, screens, expiry or revision is invalid.
	 */
	public function __construct(
		public string $id,
		public string $message,
		public NoticeType $type = NoticeType::Error,
		public NoticeScope $scope = NoticeScope::User,
		public bool $persistent = false,
		public bool $dismissible = true,
		public ?string $capability = null,
		public array $screens = array(),
		public ?int $expires_at = null,
		public int $revision = 1
	) {
		if ( 1 !== \preg_match( self::ID_PATTERN, $id ) ) {
			throw new InvalidArgumentException( "Notice id '$id' is not a lowercase letter followed by up to 63 lowercase letters, digits, underscores and hyphens." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		if ( '' === $capability ) {
			throw new InvalidArgumentException( "The capability of notice '$id' is empty." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		if ( \is_null( $capability ) && ( NoticeScope::Site === $scope || NoticeScope::Network === $scope ) ) {
			throw new InvalidArgumentException( "The $scope->value notice '$id' has no capability." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		if ( ! \array_is_list( $screens ) || ! \array_all( $screens, static fn ( mixed $screen ): bool => \is_string( $screen ) && '' !== $screen ) ) {
			throw new InvalidArgumentException( "The screens of notice '$id' are not a list of screen ids." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		if ( ! \is_null( $expires_at ) && $expires_at < 1 ) {
			throw new InvalidArgumentException( "The expiry of notice '$id' is not a positive Unix time." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		if ( $revision < 1 ) {
			throw new InvalidArgumentException( "The revision of notice '$id' is below 1." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}
	}

	// endregion

	// region METHODS

	/**
	 * Returns the notice as a version 1 row of scalars and lists.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  NoticeRow
	 */
	public function to_array(): array {
		return array(
			'v'           => 1,
			'id'          => $this->id,
			'message'     => $this->message,
			'type'        => $this->type->value,
			'scope'       => $this->scope->value,
			'persistent'  => $this->persistent,
			'dismissible' => $this->dismissible,
			'capability'  => $this->capability,
			'screens'     => $this->screens,
			'expires_at'  => $this->expires_at,
			'revision'    => $this->revision,
		);
	}

	// endregion

	// region FACTORY METHODS

	/**
	 * Returns the notice that a version 1 row describes, or null when the row has another version or any field in it is invalid.
	 *
	 * An absent optional field takes the row format's default and a key outside the format is ignored, but an invalid field never falls back to a default, so a damaged row cannot widen a notice's audience or drop its expiry.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   array<mixed> $row The row.
	 *
	 * @return  self|null
	 */
	public static function from_array( array $row ): ?self {
		// The version 1 defaults stay fixed even if a constructor default changes, so a stored row keeps its meaning.
		$row += array(
			'type'        => NoticeType::Error->value,
			'scope'       => NoticeScope::User->value,
			'persistent'  => false,
			'dismissible' => true,
			'capability'  => null,
			'screens'     => array(),
			'expires_at'  => null,
			'revision'    => 1,
		);

		$type    = \is_string( $row['type'] ) ? NoticeType::tryFrom( $row['type'] ) : null;
		$scope   = \is_string( $row['scope'] ) ? NoticeScope::tryFrom( $row['scope'] ) : null;
		$screens = \is_array( $row['screens'] ) ? \array_filter( $row['screens'], 'is_string' ) : null;
		if (
			1 !== ( $row['v'] ?? null ) || ! \is_string( $row['id'] ?? null ) || ! \is_string( $row['message'] ?? null ) || \is_null( $type ) || \is_null( $scope )
			|| ! \is_bool( $row['persistent'] ) || ! \is_bool( $row['dismissible'] ) || ! ( \is_null( $row['capability'] ) || \is_string( $row['capability'] ) )
			|| \is_null( $screens ) || $screens !== $row['screens'] || ! \array_is_list( $screens ) || ! ( \is_null( $row['expires_at'] ) || \is_int( $row['expires_at'] ) ) || ! \is_int( $row['revision'] )
		) {
			return null;
		}

		try {
			return new self( $row['id'], $row['message'], $type, $scope, $row['persistent'], $row['dismissible'], $row['capability'], $screens, $row['expires_at'], $row['revision'] );
		} catch ( InvalidArgumentException ) {
			return null;
		}
	}

	// endregion
}
