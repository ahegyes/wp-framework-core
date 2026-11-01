<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Uninstall;

use DeepWebSolutions\Framework\Shared\Exception\RuntimeException;

\defined( 'ABSPATH' ) || exit;

/**
 * Removes a plugin's stored data where a site consents, and its scheduled jobs in every case.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @phpstan-type Footprint array{cron_hooks?: list<non-empty-string>, as_hooks?: list<non-empty-string>, as_groups?: list<non-empty-string>, options?: list<non-empty-string>, option_prefixes?: list<non-empty-string>, transients?: list<non-empty-string>, post_types?: list<non-empty-string>, comment_types?: list<non-empty-string>, meta?: array<string, list<non-empty-string>>, meta_prefixes?: array<string, list<non-empty-string>>, user_meta_site?: list<non-empty-string>, user_meta_site_prefixes?: list<non-empty-string>, user_meta_global?: list<non-empty-string>, user_meta_global_prefixes?: list<non-empty-string>, network_options?: list<non-empty-string>, capabilities?: list<non-empty-string>, tables?: list<non-empty-string>}
 * @phpstan-type Args array{consent?: array{option?: string, key?: string, constant?: string}, meta_types?: array<string, array{meta_type?: string, meta_tables?: list<non-empty-string>, cache_groups?: list<non-empty-string>}>, cleanup?: callable(array{scope: 'site'|'network', site_id: int, plugin_file: string}): void}
 */
final class Uninstaller {
	// region FIELDS AND CONSTANTS

	/**
	 * The failures of the run so far.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     list<string>
	 */
	protected array $failures = array();

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @phpstan-param array{consent: array{option: string, key: string, constant: string}, meta_types: array<string, array{meta_type: string, meta_tables: list<string>, cache_groups: list<string>}>, cleanup: (callable(array{scope: 'site'|'network', site_id: int, plugin_file: string}): void)|null} $args
	 *
	 * @param   string               $plugin_file The path to the plugin's entry file.
	 * @param   array<string, mixed> $footprint   The plugin's stored data by kind.
	 * @param   array<string, mixed> $args        The consent, the meta types by name and the cleanup.
	 */
	protected function __construct(
		protected readonly string $plugin_file,
		protected readonly array $footprint,
		protected readonly array $args
	) {}

	// endregion

	// region METHODS

	/**
	 * Removes the plugin's footprint from every site that consents, and unschedules its jobs on every site.
	 *
	 * On each site the cleanup runs first, and a failed cleanup keeps that site's footprint. A failed delete leaves the others to run, while a throw from another plugin's hook ends that site's pass. Either keeps the site's consent row, and the failures are thrown once every site was visited, so WordPress keeps the plugin's files and the next uninstall retries. A job that cannot be unscheduled is logged instead, since it only ever fires into a missing callback.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @phpstan-param Footprint $footprint
	 * @phpstan-param Args      $args
	 *
	 * @param   string               $plugin_file The path to the plugin's entry file.
	 * @param   array<string, mixed> $footprint   The plugin's stored data by kind.
	 * @param   array<string, mixed> $args        Optional. The consent (an option, its key in a grouped row, or a constant), the meta types the meta kinds may name beside post, term and comment, and the cleanup. Default none, which keeps the footprint.
	 *
	 * @throws  RuntimeException Thrown when a web request runs on a large network, before any change, or when a delete, a site's pass or the cleanup failed, once every site was visited.
	 *
	 * @return  void
	 */
	public static function run( string $plugin_file, array $footprint, array $args = array() ): void {
		$args            += array(
			'consent'    => array(),
			'meta_types' => array(),
			'cleanup'    => null,
		);
		$args['consent'] += array(
			'option'   => '',
			'key'      => '',
			'constant' => '',
		);

		$args['meta_types'] = array(
			'post'    => array( 'meta_type' => 'post' ),
			'term'    => array( 'meta_type' => 'term' ),
			'comment' => array( 'meta_type' => 'comment' ),
		) + $args['meta_types'];

		$meta_types = array();
		foreach ( $args['meta_types'] as $name => $definition ) {
			// A user meta type would delete rows that belong to every site of a network during one site's pass.
			if ( \in_array( $definition['meta_type'] ?? '', array( '', 'post', 'term', 'comment' ), true ) ) {
				$meta_types[ $name ] = array(
					'meta_type'    => $definition['meta_type'] ?? '',
					'meta_tables'  => self::get_strings( $definition['meta_tables'] ?? null ),
					'cache_groups' => self::get_strings( $definition['cache_groups'] ?? null ),
				);
			}
		}
		$args['meta_types'] = $meta_types;

		$uninstaller = new self( $plugin_file, $footprint, $args );
		if ( ! is_multisite() ) {
			$uninstaller->uninstall_site( true );
		} else {
			if ( wp_is_large_network() && ! self::is_constant_true( 'WP_CLI' ) ) { // A web request could not finish sweeping a large network within the server's time limit, which WP-CLI does not have.
				throw new RuntimeException( 'Uninstall does not sweep a large network in a web request; run `wp plugin uninstall` instead.' );
			}

			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( $site_id );
				try {
					$uninstaller->uninstall_site( false );
				} finally {
					restore_current_blog();
					if ( wp_cache_supports( 'flush_runtime' ) ) { // WordPress keeps every visited site's options in its runtime cache, so the sweep's memory would grow with each site.
						wp_cache_flush_runtime();
					}
				}
			}

			if ( self::is_constant_true( $args['consent']['constant'] ) ) {
				try {
					if ( $uninstaller->run_cleanup( 'network' ) ) {
						$uninstaller->delete_network_footprint();
					}
				} catch ( \Throwable $throwable ) {
					$uninstaller->failures[] = "network: {$throwable->getMessage()}";
				}
			}
		}

		if ( array() !== $uninstaller->failures ) {
			throw new RuntimeException( 'Uninstall could not remove everything: ' . \implode( '; ', $uninstaller->failures ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing, never rendered.
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Runs the uninstall pass on the current site, which unschedules the plugin's jobs and, only where the site consents, runs the cleanup and deletes the footprint.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @global  \wpdb $wpdb The WordPress database connection.
	 *
	 * @param   bool $network_footprint Whether the pass also deletes the network options and network-wide user meta, which a single site keeps as its own.
	 *
	 * @return  void
	 */
	protected function uninstall_site( bool $network_footprint ): void {
		global $wpdb;

		$failed = \count( $this->failures );
		try {
			foreach ( self::get_strings( $this->footprint['cron_hooks'] ?? null ) as $hook ) {
				$wpdb->flush();
				try {
					$result = wp_unschedule_hook( $hook, true );
				} catch ( \Throwable $throwable ) {
					$result = $throwable;
				}
				$this->record_failure( "cron hook $hook", $result );
			}

			if ( \function_exists( 'as_unschedule_all_actions' ) ) { // Action Scheduler is loaded only while a plugin that bundles it is active.
				$jobs = array();
				foreach ( self::get_strings( $this->footprint['as_hooks'] ?? null ) as $hook ) {
					$jobs[ "Action Scheduler hook $hook" ] = array( $hook );
				}
				foreach ( self::get_strings( $this->footprint['as_groups'] ?? null ) as $group ) {
					$jobs[ "Action Scheduler group $group" ] = array( '', array(), $group );
				}

				foreach ( $jobs as $subject => $arguments ) {
					$wpdb->flush();
					try {
						as_unschedule_all_actions( ...$arguments ); // Action Scheduler throws when its store fails to cancel an action.
						$result = null;
					} catch ( \Throwable $throwable ) {
						$result = $throwable;
					}
					$this->record_failure( $subject, $result );
				}
			}

			// A job that cannot be unscheduled only ever fires into a missing callback, so it is logged and never holds the uninstall back.
			$unscheduling = \array_splice( $this->failures, $failed );
			if ( array() !== $unscheduling ) {
				\error_log( '[' . plugin_basename( $this->plugin_file ) . '] Uninstall could not unschedule: ' . \implode( '; ', $unscheduling ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- No logger exists while a plugin is uninstalled.
			}

			$consent = get_option( $this->args['consent']['option'] );
			if ( '' !== $this->args['consent']['key'] ) {
				$consent = \is_array( $consent ) ? $consent[ $this->args['consent']['key'] ] ?? null : null;
			}
			if ( 'yes' !== $consent && ! self::is_constant_true( $this->args['consent']['constant'] ) ) {
				return;
			}

			if ( ! $this->run_cleanup( 'site' ) ) { // A failed cleanup keeps the footprint, so its retry still finds every row it reads.
				return;
			}

			$options = self::get_strings( $this->footprint['options'] ?? null );
			foreach ( self::get_strings( $this->footprint['option_prefixes'] ?? null ) as $prefix ) {
				$options = \array_merge( $options, $this->get_names_by_prefix( $wpdb->options, 'option_name', $prefix ) );
			}
			foreach ( \array_diff( \array_unique( $options ), array( $this->args['consent']['option'] ) ) as $option ) {
				$wpdb->flush();
				$this->record_failure( "option $option", delete_option( $option ) );
			}

			foreach ( self::get_strings( $this->footprint['transients'] ?? null ) as $transient ) {
				$wpdb->flush();
				$this->record_failure( "transient $transient", delete_transient( $transient ) );
			}

			foreach ( self::get_strings( $this->footprint['post_types'] ?? null ) as $post_type ) {
				foreach ( $this->get_matching_ids( $wpdb->posts, 'ID', 'post_type', $post_type ) as $post_id ) {
					$wpdb->flush();
					$this->record_failure( "post $post_id of type $post_type", self::confirm_deleted( wp_delete_post( $post_id, true ) ) );
				}
			}

			foreach ( self::get_strings( $this->footprint['comment_types'] ?? null ) as $comment_type ) {
				foreach ( $this->get_matching_ids( $wpdb->comments, 'comment_ID', 'comment_type', $comment_type ) as $comment_id ) {
					$wpdb->flush();
					$this->record_failure( "comment $comment_id of type $comment_type", self::confirm_deleted( wp_delete_comment( $comment_id, true ) ) );
				}
			}

			$meta_keys     = \array_intersect_key( \is_array( $this->footprint['meta'] ?? null ) ? $this->footprint['meta'] : array(), $this->args['meta_types'] );
			$meta_prefixes = \array_intersect_key( \is_array( $this->footprint['meta_prefixes'] ?? null ) ? $this->footprint['meta_prefixes'] : array(), $this->args['meta_types'] );
			foreach ( \array_keys( $meta_keys + $meta_prefixes ) as $name ) {
				$meta_type = $this->args['meta_types'][ $name ];

				$tables = array();
				foreach ( $meta_type['meta_tables'] as $table ) {
					$wpdb->flush();
					$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $table ) ) ?? '' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A schema read.
					if ( $this->record_failure( "the table $wpdb->prefix$table", $found ) && $wpdb->prefix . $table === $found ) {
						$tables[] = $found;
					}
				}

				$keys = self::get_strings( $meta_keys[ $name ] ?? null );
				foreach ( self::get_strings( $meta_prefixes[ $name ] ?? null ) as $prefix ) {
					foreach ( '' === $meta_type['meta_type'] ? $tables : array( (string) _get_meta_table( $meta_type['meta_type'] ), ...$tables ) as $table ) {
						$keys = \array_merge( $keys, $this->get_names_by_prefix( $table, 'meta_key', $prefix ) );
					}
				}
				$keys = \array_values( \array_unique( $keys ) );

				if ( '' !== $meta_type['meta_type'] ) {
					$this->delete_meta_keys( $meta_type['meta_type'], $keys );
				}
				foreach ( $tables as $table ) {
					foreach ( $keys as $key ) {
						$wpdb->flush();
						// The collation alone would also match another plugin's key that differs only in letter case.
						$this->record_failure( "$table rows of key $key", $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key = %s AND CAST( meta_key AS BINARY ) = CAST( %s AS BINARY )', $table, $key, $key ) ?? '' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A meta type's own tables have no WordPress meta API while their plugin is unloaded.
					}
				}

				if ( wp_cache_supports( 'flush_group' ) ) { // Where the object cache cannot flush a single group, the entries expire on their own.
					foreach ( $meta_type['cache_groups'] as $group ) {
						wp_cache_flush_group( $group );
					}
				}
			}

			$blog_prefix = $wpdb->get_blog_prefix(); // Site user meta keys carry the site's table prefix, as update_user_option() writes them.
			$this->delete_meta_keys( 'user', \array_map( static fn ( string $key ): string => $blog_prefix . $key, self::get_strings( $this->footprint['user_meta_site'] ?? null ) ) );
			foreach ( self::get_strings( $this->footprint['user_meta_site_prefixes'] ?? null ) as $prefix ) {
				$this->delete_meta_keys( 'user', $this->get_names_by_prefix( $wpdb->usermeta, 'meta_key', $blog_prefix . $prefix ) );
			}

			foreach ( self::get_strings( $this->footprint['capabilities'] ?? null ) as $capability ) {
				foreach ( wp_roles()->role_objects as $role ) {
					$wpdb->flush();
					$role->remove_cap( $capability );
					$this->record_failure( "capability $capability of role $role->name", null );
				}
			}

			foreach ( self::get_strings( $this->footprint['tables'] ?? null ) as $table ) {
				$wpdb->flush();
				$this->record_failure( "table $table", $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) ?? '' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A plugin's own table, which no WordPress API drops.
			}

			if ( $network_footprint ) {
				$this->delete_network_footprint();
			}

			// The consent row goes last and only after a clean pass, so an interrupted or failed run still consents on its retry.
			if ( '' !== $this->args['consent']['option'] && \count( $this->failures ) === $failed ) {
				$wpdb->flush();
				$this->record_failure( "option {$this->args['consent']['option']}", delete_option( $this->args['consent']['option'] ) );
			}
		} catch ( \Throwable $throwable ) {
			$this->failures[] = 'site ' . get_current_blog_id() . ": {$throwable->getMessage()}";
		}
	}

	/**
	 * Deletes the footprint's network options, on every network of a multisite, and network-wide user meta, which a single site keeps as its own.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @global  \wpdb $wpdb The WordPress database connection.
	 *
	 * @return  void
	 */
	protected function delete_network_footprint(): void {
		global $wpdb;

		$options = self::get_strings( $this->footprint['network_options'] ?? null );
		if ( is_multisite() ) {
			// WordPress's delete_network_option() matches keys under the column's collation, which would also delete another plugin's key that differs only in letter case.
			$this->delete_meta_keys( 'site', $options );
			foreach ( $options as $option ) {
				wp_cache_delete( get_current_network_id() . ":$option", 'site-options' );
			}
		} else {
			foreach ( $options as $option ) {
				$wpdb->flush();
				$this->record_failure( "network option $option", delete_option( $option ) );
			}
		}

		$this->delete_meta_keys( 'user', self::get_strings( $this->footprint['user_meta_global'] ?? null ) );
		foreach ( self::get_strings( $this->footprint['user_meta_global_prefixes'] ?? null ) as $prefix ) {
			$this->delete_meta_keys( 'user', $this->get_names_by_prefix( $wpdb->usermeta, 'meta_key', $prefix ) );
		}
	}

	/**
	 * Deletes every row of each meta key, whatever object it belongs to, row by row.
	 *
	 * WordPress's delete_metadata() matches keys under the column's collation, which would also delete another plugin's key that differs only in letter case.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @global  \wpdb $wpdb The WordPress database connection.
	 *
	 * @param   string       $meta_type The meta type.
	 * @param   list<string> $keys      The meta keys.
	 *
	 * @return  void
	 */
	protected function delete_meta_keys( string $meta_type, array $keys ): void {
		global $wpdb;

		foreach ( $keys as $key ) {
			foreach ( $this->get_matching_ids( (string) _get_meta_table( $meta_type ), 'user' === $meta_type ? 'umeta_id' : 'meta_id', 'meta_key', $key ) as $meta_id ) {
				$wpdb->flush();
				$this->record_failure( "$meta_type meta $meta_id of key $key", self::confirm_deleted( delete_metadata_by_mid( $meta_type, $meta_id ) ) );
			}
		}
	}

	/**
	 * Runs the plugin's cleanup, if any, for one pass on the current site, and returns whether it did not fail.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @global  \wpdb $wpdb The WordPress database connection.
	 *
	 * @phpstan-param 'site'|'network' $scope
	 *
	 * @param   string $scope The pass's scope, 'site' or 'network'.
	 *
	 * @return  bool
	 */
	protected function run_cleanup( string $scope ): bool {
		global $wpdb;

		if ( null === $this->args['cleanup'] ) {
			return true;
		}

		$wpdb->flush();
		try {
			( $this->args['cleanup'] )(
				array(
					'scope'       => $scope,
					'site_id'     => get_current_blog_id(),
					'plugin_file' => $this->plugin_file,
				)
			);
			$result = null;
		} catch ( \Throwable $throwable ) {
			$result = $throwable;
		}

		return $this->record_failure( 'cleanup', $result );
	}

	/**
	 * Records the failure of an operation that ran after the database's last error was flushed, and returns whether it did not fail.
	 *
	 * WordPress's delete functions return false both for a missing row and for a failed query, so only the database error tells a failure apart.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @global  \wpdb $wpdb The WordPress database connection.
	 *
	 * @param   string $subject What the operation removes or reads, for the failure's message.
	 * @param   mixed  $result  The operation's return value, or its throw.
	 *
	 * @return  bool
	 */
	protected function record_failure( string $subject, mixed $result ): bool {
		global $wpdb;

		if ( $result instanceof \Throwable ) {
			$this->failures[] = "$subject: {$result->getMessage()}";
		} elseif ( $result instanceof \WP_Error ) {
			$this->failures[] = "$subject: {$result->get_error_message()}";
		} elseif ( '' !== $wpdb->last_error ) {
			$this->failures[] = "$subject: $wpdb->last_error";
		} else {
			return true;
		}

		return false;
	}

	/**
	 * Returns the column's distinct values that start with the prefix, and records a failed read.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @global  \wpdb $wpdb The WordPress database connection.
	 *
	 * @param   string $table  The table.
	 * @param   string $column The column.
	 * @param   string $prefix The prefix, matched byte for byte.
	 *
	 * @return  list<string>
	 */
	protected function get_names_by_prefix( string $table, string $column, string $prefix ): array {
		global $wpdb;

		$wpdb->flush();
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT CAST( %i AS BINARY ) FROM %i WHERE %i LIKE %s', $column, $table, $column, $wpdb->esc_like( $prefix ) . '%' ) ?? '' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- WordPress lists no option or meta key by prefix.
		if ( ! $this->record_failure( "the names under $prefix in $table", $names ) ) {
			return array();
		}

		// The collation also matches names that differ from the prefix in letter case or accents, and would fold such twins into one distinct name, hence the bytes.
		return \array_values( \array_filter( $names, static fn ( mixed $name ): bool => \is_string( $name ) && \str_starts_with( $name, $prefix ) ) );
	}

	/**
	 * Returns the ids of the rows whose column holds exactly the value, and records a failed read.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @global  \wpdb $wpdb The WordPress database connection.
	 *
	 * @param   string $table     The table.
	 * @param   string $id_column The id column.
	 * @param   string $column    The column to match.
	 * @param   string $value     The value, matched byte for byte.
	 *
	 * @return  list<int>
	 */
	protected function get_matching_ids( string $table, string $id_column, string $column, string $value ): array {
		global $wpdb;

		$wpdb->flush();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT %i AS id, %i AS value FROM %i WHERE %i = %s', $id_column, $column, $table, $column, $value ) ?? '', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Reads every row, trashed posts and auto-drafts included.
		if ( ! $this->record_failure( "the $value rows of $table", $rows ) ) {
			return array();
		}

		$ids = array();
		foreach ( (array) $rows as $row ) {
			// The column's collation also matches values that differ in letter case or accents.
			if ( \is_array( $row ) && ( $row['value'] ?? null ) === $value && \is_numeric( $row['id'] ?? null ) ) {
				$ids[] = (int) $row['id'];
			}
		}

		return $ids;
	}

	/**
	 * Returns an error when a WordPress delete that reports its outcome by its return value did not delete, and otherwise the value.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   \WP_Post|bool|null $deleted The delete function's return value.
	 *
	 * @return  \WP_Post|true|\WP_Error
	 */
	protected static function confirm_deleted( mixed $deleted ): mixed {
		return null === $deleted || false === $deleted ? new \WP_Error( 'not_deleted', 'It was not deleted.' ) : $deleted;
	}

	/**
	 * Returns whether the constant is defined as true.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $name The constant's name.
	 *
	 * @return  bool
	 */
	protected static function is_constant_true( string $name ): bool {
		return \defined( $name ) && true === \constant( $name );
	}

	/**
	 * Returns the value's strings other than '' and '0', or none when it is not an array.
	 *
	 * An empty name or prefix would match every row, Action Scheduler reads either as no name and cancels every plugin's pending actions, and a non-string would throw under strict types.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   mixed $value The value.
	 *
	 * @return  list<string>
	 */
	protected static function get_strings( mixed $value ): array {
		return \array_values( \array_filter( \is_array( $value ) ? $value : array(), static fn ( mixed $item ): bool => \is_string( $item ) && '' !== $item && '0' !== $item ) );
	}

	// endregion
}
