<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Notices;

use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;

\defined( 'ABSPATH' ) || exit;

/**
 * One revision of one notice in one context, which recipient states are recorded against, so notices of the same id in other scopes, owners or networks never share a state.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final readonly class NoticeIdentity {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeContext $context  The context the notice belongs to.
	 * @param   string        $id       The notice's id.
	 * @param   int           $revision The notice's revision.
	 *
	 * @throws  InvalidArgumentException Thrown when the id does not match the notice id pattern or the revision is below 1.
	 */
	public function __construct(
		public NoticeContext $context,
		public string $id,
		public int $revision
	) {
		if ( 1 !== \preg_match( Notice::ID_PATTERN, $id ) ) {
			throw new InvalidArgumentException( "Notice id '$id' is not a lowercase letter followed by up to 63 lowercase letters, digits, underscores and hyphens." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		if ( $revision < 1 ) {
			throw new InvalidArgumentException( "The revision of notice '$id' is below 1." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}
	}

	// endregion

	// region METHODS

	/**
	 * Returns the identity as a key of scope, owner, id and revision, where a user notice's owner is its site and user.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  string
	 */
	public function to_key(): string {
		$context = $this->context;
		$owner   = NoticeScope::User === $context->scope ? "$context->site_id:$context->owner" : $context->owner;

		return "{$context->scope->value}:$owner:$this->id@$this->revision";
	}

	// endregion
}
