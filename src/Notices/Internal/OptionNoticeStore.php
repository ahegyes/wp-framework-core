<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Notices\Internal;

use DeepWebSolutions\Framework\Notices\Notice;
use DeepWebSolutions\Framework\Notices\NoticeContext;
use DeepWebSolutions\Framework\Notices\NoticeScope;
use DeepWebSolutions\Framework\Notices\NoticeStoreInterface;
use DeepWebSolutions\Framework\Notices\StoredNotice;
use DeepWebSolutions\Framework\Shared\Exception\InvalidArgumentException;
use DeepWebSolutions\Framework\Shared\Exception\LogicException;
use DeepWebSolutions\Framework\Shared\Exception\RuntimeException;

\defined( 'ABSPATH' ) || exit;

/**
 * Keeps site and network notices as one non-autoloaded option per notice: a site notice in its site's options table, and a network notice in the options table of the network's main site.
 *
 * The unique option name makes each save one atomic upsert, which network meta, lacking a unique key, cannot. Consuming a record, and deleting an expired one on read, names the exact value read, so a concurrent replacement survives.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @internal
 */
final class OptionNoticeStore implements NoticeStoreInterface {
	// region FIELDS AND CONSTANTS

	/**
	 * The database connection.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     \wpdb
	 */
	protected \wpdb $wpdb;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $prefix The plugin's prefix for storage keys.
	 *
	 * @throws  LogicException Thrown when WordPress has no database connection.
	 */
	public function __construct(
		protected string $prefix
	) {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		if ( ! $wpdb instanceof \wpdb ) {
			throw new LogicException( 'The notice stores need a wpdb connection.' );
		}

		$this->wpdb = $wpdb;
	}

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  InvalidArgumentException Thrown when the notice is not of the context's scope.
	 */
	#[\Override]
	public function save( Notice $notice, NoticeContext $context ): StoredNotice {
		$name = $this->get_option_prefix( $context ) . $notice->id;
		if ( $notice->scope !== $context->scope ) {
			throw new InvalidArgumentException( "A {$notice->scope->value} notice does not belong in a {$context->scope->value} context." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		$record = new StoredNotice( $notice, $context, NoticeRecords::now(), \wp_generate_uuid4() );
		$this->on_site(
			$context,
			function () use ( $name, $record ): void {
				$wpdb = $this->wpdb;

				// One statement, because add_option() refuses an existing option and update_option() fails when the option disappears after its read.
				$written = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)', $wpdb->options, $name, \maybe_serialize( NoticeRecords::encode( $record ) ), 'off' ) ?? '' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The options API has no atomic upsert.
				if ( false === $written ) {
					throw new RuntimeException( "Could not save notice option '$name': $wpdb->last_error" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
				}
			}
		);

		return $record;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function find( string $id, NoticeContext $context ): ?StoredNotice {
		return \array_find( $this->all( $context ), static fn ( StoredNotice $record ): bool => $record->notice->id === $id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function all( NoticeContext $context ): array {
		$prefix = $this->get_option_prefix( $context );

		return $this->on_site(
			$context,
			function () use ( $prefix, $context ): array {
				$wpdb = $this->wpdb;

				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( $prefix ) . '%' ) ?? '', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The options API cannot list options by prefix.
				if ( '' !== $wpdb->last_error ) {
					throw new RuntimeException( "Could not read the notice options: $wpdb->last_error" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
				}

				$records = array();
				foreach ( (array) $rows as $row ) {
					$value = $row['option_value'] ?? null;
					if ( ! \is_string( $value ) ) {
						continue;
					}

					// A record must name its own option, so a plugin whose prefix extends this one's never shares its rows.
					$record = NoticeRecords::decode( \maybe_unserialize( $value ), $context );
					if ( null === $record || ( $row['option_name'] ?? null ) !== $prefix . $record->notice->id ) {
						continue;
					}

					if ( NoticeRecords::is_expired( $record ) ) {
						$this->delete_value( $prefix . $record->notice->id, $value );
						continue;
					}

					$records[] = $record;
				}

				return NoticeRecords::sort( $records );
			}
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function consume( StoredNotice $observed ): bool {
		$name = $this->get_option_prefix( $observed->context ) . $observed->notice->id;

		return $this->on_site(
			$observed->context,
			function () use ( $name, $observed ): bool {
				$wpdb = $this->wpdb;

				$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) ?? '' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Reads the value the conditional delete names.
				if ( '' !== $wpdb->last_error ) {
					throw new RuntimeException( "Could not read notice option '$name': $wpdb->last_error" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
				}

				if ( ! \is_string( $value ) ) {
					return false;
				}

				$row = \maybe_unserialize( $value );

				return \is_array( $row ) && ( $row['gen'] ?? null ) === $observed->generation && $this->delete_value( $name, $value );
			}
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 */
	#[\Override]
	public function remove( string $id, NoticeContext $context ): void {
		$name = $this->get_option_prefix( $context ) . $id;
		$this->on_site(
			$context,
			function () use ( $name ): void {
				$wpdb = $this->wpdb;

				if ( false === $wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Matches the store's own direct writes.
					throw new RuntimeException( "Could not delete notice option '$name': $wpdb->last_error" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
				}
			}
		);
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the option-name prefix of the context's scope.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   NoticeContext $context The context.
	 *
	 * @throws  InvalidArgumentException Thrown when the context is neither a site's nor a network's.
	 *
	 * @return  string
	 */
	protected function get_option_prefix( NoticeContext $context ): string {
		return match ( $context->scope ) {
			NoticeScope::Site    => "{$this->prefix}_notice_",
			NoticeScope::Network => "{$this->prefix}_network_notice_",
			default              => throw new InvalidArgumentException( "The option store keeps no {$context->scope->value} notices." ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		};
	}

	/**
	 * Runs an operation against the options table of the context's site, which is the network's main site for a network notice, switching to it when it is another site.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @template T
	 *
	 * @param   NoticeContext $context   The context.
	 * @param   \Closure(): T $operation The operation.
	 *
	 * @return  T
	 */
	protected function on_site( NoticeContext $context, \Closure $operation ): mixed {
		$site_id = NoticeScope::Network === $context->scope ? \get_main_site_id( $context->owner ) : $context->owner;
		$switch  = \get_current_blog_id() !== $site_id;
		if ( $switch ) {
			\switch_to_blog( $site_id );
		}

		try {
			return $operation();
		} finally {
			if ( $switch ) {
				\restore_current_blog();
			}
		}
	}

	/**
	 * Deletes the option only while it still holds the given serialized value, and returns whether it did.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $name  The option name.
	 * @param   string $value The serialized value as it was read.
	 *
	 * @throws  RuntimeException Thrown when the delete fails.
	 *
	 * @return  bool
	 */
	protected function delete_value( string $name, string $value ): bool {
		$wpdb = $this->wpdb;

		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $name, $value ) ?? '' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The options API cannot delete conditionally.
		if ( false === $deleted ) {
			throw new RuntimeException( "Could not delete notice option '$name': $wpdb->last_error" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}

		return 0 < $deleted;
	}

	// endregion
}
