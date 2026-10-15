<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Notices;

\defined( 'ABSPATH' ) || exit;

/**
 * A notice as a store holds it: the notice, its context, when it was saved and which saved record it is.
 *
 * @since   2.0.0
 * @version 2.0.0
 */
final readonly class StoredNotice {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   Notice        $notice     The notice.
	 * @param   NoticeContext $context    The context the notice belongs to.
	 * @param   int           $created_at The Unix time in microseconds at which the record was saved.
	 * @param   string        $generation The opaque token that names the saved record, which every save replaces.
	 */
	public function __construct(
		public Notice $notice,
		public NoticeContext $context,
		public int $created_at,
		public string $generation
	) {}

	// endregion
}
