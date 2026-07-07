<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core;

use DeepWebSolutions\Framework\Core\Composite\CompositeComponentInterface;
use DeepWebSolutions\Framework\Core\Conditional\ConditionalInterface;
use DeepWebSolutions\Framework\Core\Enabled\EnabledInterface;
use DeepWebSolutions\Framework\Core\Feature\Exceptions\FeatureException;
use DeepWebSolutions\Framework\Core\Feature\FeatureInterface;
use DeepWebSolutions\Framework\Core\Lifecycle\Hookable\HookableInterface;
use DeepWebSolutions\Framework\Core\Lifecycle\Initializable\InitializableInterface;
use DeepWebSolutions\Framework\Core\ValueObjects\BootStatus;
use DeepWebSolutions\Framework\Core\ValueObjects\PluginBootReport;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Runtime engine that boots a plugin. On boot it first runs the installer's version
 * check, then gates each Feature on its conditionals before resolving it, then flattens
 * every surviving Feature's component tree — pruning any subtree whose node is disabled
 * — and initializes every initializable component before any hookable one registers
 * hooks, so a hook callback may reach a peer component regardless of which Feature
 * declared it.
 *
 * The kernel is the sole dispatcher of the component lifecycle: a composite component
 * declares its children but never dispatches them itself, which keeps the parent-off ⇒
 * subtree-off guarantee enforced in one place.
 *
 * The whole component phase runs inside a hook-table transaction: WordPress's hook
 * table is snapshotted before any Feature or component is constructed, and any failure
 * in the phase — resolution, initialization, or hook registration — unwinds the table
 * through WordPress's own API to its window-start state, so no hook registered through
 * the WordPress hook API by any phase of a failed boot survives (constructor,
 * initialize(), register_hooks()). Hook-table mutations third-party code made
 * synchronously inside the window are unwound with them; $wp_current_filter,
 * $wp_actions, and non-hook side effects (options writes, post-type registration, …)
 * are not transactional. A residue left by direct hook-table manipulation is detected
 * after rollback and logged rather than force-removed.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @phpstan-type BootMetrics array{gated_features: list<array{feature: class-string<FeatureInterface>, conditional: class-string<ConditionalInterface>}>, pruned_components: list<class-string>, runnable_components: list<class-string>, inert_components: list<class-string>, initialized_components: list<class-string>, hooked_components: list<class-string>}
 */
final class PluginKernel {
	// region FIELDS AND CONSTANTS

	/**
	 * Per-phase component lists in their empty, boot-start shape.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     BootMetrics
	 */
	protected const EMPTY_BOOT_METRICS = array(
		'gated_features'         => array(),
		'pruned_components'      => array(),
		'runnable_components'    => array(),
		'inert_components'       => array(),
		'initialized_components' => array(),
		'hooked_components'      => array(),
	);

	/**
	 * Whether {@see self::boot()} has already run. Subsequent calls short-circuit.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     bool
	 */
	protected bool $booted = false;

	/**
	 * Diagnostic report of the current boot attempt. Seeded with the not-started shape,
	 * swapped for a Running report when boot begins, and replaced at each terminal point
	 * (blocked, failed, completed).
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     PluginBootReport
	 */
	public protected(set) PluginBootReport $boot_report;

	/**
	 * Per-phase component lists accumulated by the current boot attempt, folded into
	 * {@see self::$boot_report} whenever the report is rebuilt.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @var     BootMetrics
	 */
	protected array $boot_metrics = self::EMPTY_BOOT_METRICS;

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   PluginInterface      $plugin Plugin to boot.
	 * @param   LoggerInterface|null $logger Optional logger; names each Feature or component the kernel gates out and reports a failed installer routine.
	 */
	public function __construct(
		protected readonly PluginInterface $plugin,
		protected readonly ?LoggerInterface $logger = null,
	) {
		$this->boot_report = new PluginBootReport();
	}

	// endregion

	// region METHODS

	/**
	 * Construct and boot in one call. Returns the kernel for callers that want to
	 * inspect or re-call (idempotent) later.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   PluginInterface      $plugin Plugin to boot.
	 * @param   LoggerInterface|null $logger Optional diagnostic logger.
	 *
	 * @return  self
	 */
	public static function run( PluginInterface $plugin, ?LoggerInterface $logger = null ): self {
		$kernel = new self( $plugin, $logger );
		$kernel->boot();

		return $kernel;
	}

	/**
	 * Wires the plugin's activation and deactivation routines to WordPress. Must be
	 * called while the plugin's main file is being included — register_activation_hook()
	 * only schedules a callback for the activation request, which fires before any
	 * plugins_loaded-deferred {@see self::boot()} would run.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   PluginInterface $plugin Plugin whose installer handles activation/deactivation.
	 */
	public static function register_lifecycle_hooks( PluginInterface $plugin ): void {
		\register_activation_hook(
			$plugin->get_plugin_file(),
			static function ( bool $network_wide = false ) use ( $plugin ): void {
				$plugin->get_installer()->activate( $network_wide );
			},
		);

		\register_deactivation_hook(
			$plugin->get_plugin_file(),
			static function ( bool $network_deactivating = false ) use ( $plugin ): void {
				$plugin->get_installer()->deactivate( $network_deactivating );
			},
		);
	}

	/**
	 * Boots the plugin. Runs the installer version check first; if it fails the boot
	 * stops before any component runs. Otherwise each Feature's conditionals gate it in
	 * or out, every surviving Feature's component tree is flattened with disabled
	 * subtrees pruned, and every initializable component is initialized before any
	 * hookable one registers hooks, so a hook callback may safely reach a peer in
	 * another Feature.
	 *
	 * The component phase runs inside the hook-table transaction described on the class.
	 * A failure resolving or running a component fails closed — it is logged and the
	 * request registers nothing — except a malformed component graph, which propagates so
	 * the developer error surfaces rather than passing silently.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  FeatureException When the declared component graph is malformed: a duplicate or cyclic component, a declared Feature that is not a FeatureInterface, or a declared gate that is not a ConditionalInterface.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted       = true;
		$this->boot_metrics = self::EMPTY_BOOT_METRICS;
		$this->boot_report  = new PluginBootReport( status: BootStatus::Running );

		if ( ! $this->run_installer() ) {
			return;
		}

		// The snapshot precedes Feature resolution so a hook added by a component constructor falls
		// inside the transaction window and an initialize() throw rolls back like a register_hooks() one.
		$snapshot = $this->snapshot_hook_table();

		try {
			$container = $this->plugin->get_container();

			$surviving_features = array();
			foreach ( $this->plugin->get_feature_classes() as $feature_class ) {
				if ( ! \is_a( $feature_class, FeatureInterface::class, true ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- framework-internal exception; never reaches an HTML output context unescaped.
					throw new FeatureException( 'Feature ' . $feature_class . ' does not implement ' . FeatureInterface::class . '.' );
				}

				if ( $this->are_conditionals_met( $feature_class, $container ) ) {
					/** @var FeatureInterface $feature */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- inline @var type assertion, no description applies.
					$feature              = $container->get( $feature_class );
					$surviving_features[] = $feature;
				}
			}

			$this->assert_unique_component_graph( $surviving_features );
			$runnable_components = $this->collect_runnable_components( $surviving_features, $container );
			$this->report_inert_components( $runnable_components );

			foreach ( $runnable_components as $record ) {
				$component = $record['component'];
				if ( $component instanceof InitializableInterface ) {
					$component->initialize();
					$this->boot_metrics['initialized_components'][] = $record['class'];
				}
			}

			$hooked = array();
			foreach ( $runnable_components as $record ) {
				$component = $record['component'];
				if ( $component instanceof HookableInterface ) {
					$component->register_hooks();
					$hooked[] = $record['class'];
				}
			}

			$this->boot_metrics['hooked_components'] = $hooked;

			$this->boot_report = $this->build_boot_report( BootStatus::Completed );
		} catch ( FeatureException $error ) {
			$this->rollback_hook_table( $snapshot );
			$this->boot_report = $this->build_boot_report( BootStatus::Failed, $this->format_throwable_summary( $error ) );

			// A duplicate/cyclic component graph or a malformed Feature or gate declaration is a deterministic
			// developer error, not a runtime fault — it propagates so it surfaces in development rather than failing silently.
			throw $error;
		} catch ( \Throwable $error ) {
			$this->rollback_hook_table( $snapshot );
			$summary           = $this->format_throwable_summary( $error );
			$this->boot_report = $this->build_boot_report( BootStatus::Failed, $summary );

			// A missing or throwing container binding (conditional, feature, or component) must not white-screen
			// every request: fail closed like the installer — log and register nothing for this request.
			$this->log(
				LogLevel::ERROR,
				'Plugin component boot failed; every hook registered during the attempt (constructor, initialize(), register_hooks()) was rolled back. Non-hook side effects are not transactional. ' . $summary,
				array( 'exception' => $error ),
			);
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Resolves the installer and runs its version check: install() on a fresh site,
	 * update() when the stored version is older than the current one, then records the
	 * current version. A stored version newer than the current code is a downgrade the
	 * kernel refuses to run: it logs both versions at error, blocks the boot, and neither
	 * calls update() nor touches the stored version. The resolution sits inside the guard,
	 * so a non-resolvable installer fails closed like a broken migration — it logs, leaves
	 * the stored version untouched for the next boot to retry, and stops the boot instead
	 * of fataling every request.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  bool True when the boot may proceed; false when the routine failed.
	 */
	protected function run_installer(): bool {
		try {
			$installer = $this->plugin->get_installer();
			$stored    = $installer->get_stored_version();
			$current   = $installer->get_current_version();

			if ( null === $stored ) {
				$installer->install();
				$installer->set_stored_version( $current );
			} elseif ( $stored->is_less_than( $current ) ) {
				$installer->update( $stored );
				$installer->set_stored_version( $current );
			} elseif ( $stored->is_greater_than( $current ) ) {
				$stored_version  = (string) $stored;
				$current_version = (string) $current;

				$this->boot_report = $this->build_boot_report(
					BootStatus::Blocked,
					'Stored plugin version ' . $stored_version . ' is newer than code version ' . $current_version . '.',
				);
				$this->log(
					LogLevel::ERROR,
					'Stored plugin version ' . $stored_version . ' is newer than code version ' . $current_version . '; skipping component boot for this request.',
					array(
						'stored_version'  => $stored_version,
						'current_version' => $current_version,
					),
				);

				return false;
			}
		} catch ( \Throwable $error ) {
			$summary           = $this->format_throwable_summary( $error );
			$this->boot_report = $this->build_boot_report( BootStatus::Blocked, $summary );
			$this->log(
				LogLevel::ERROR,
				'Plugin installation routine failed; skipping component boot for this request. ' . $summary,
				array( 'exception' => $error ),
			);

			return false;
		}

		return true;
	}

	/**
	 * Resolves every conditional class declared by the Feature and returns false on the
	 * first unsatisfied gate. Throws when a declared conditional does not implement
	 * {@see ConditionalInterface} — fail-closed, since a malformed gate must not let a
	 * Feature boot unguarded.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   class-string<FeatureInterface> $feature_class Feature being evaluated.
	 * @param   ContainerInterface             $container     Plugin container.
	 *
	 * @throws  FeatureException When a declared conditional class does not implement ConditionalInterface.
	 *
	 * @return  bool
	 */
	protected function are_conditionals_met( string $feature_class, ContainerInterface $container ): bool {
		foreach ( $feature_class::get_conditional_classes() as $conditional_class ) {
			$conditional = $container->get( $conditional_class );

			if ( ! $conditional instanceof ConditionalInterface ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- framework-internal exception; never reaches an HTML output context unescaped.
				throw new FeatureException( 'Conditional ' . $conditional_class . ' declared by feature ' . $feature_class . ' does not implement ' . ConditionalInterface::class . '.' );
			}

			if ( ! $conditional->is_met() ) {
				$this->boot_metrics['gated_features'][] = array(
					'feature'     => $feature_class,
					'conditional' => $conditional_class,
				);

				$this->log(
					LogLevel::DEBUG,
					'Feature gated out by an unmet conditional.',
					array(
						'feature'     => $feature_class,
						'conditional' => $conditional_class,
					),
				);

				return false;
			}
		}

		return true;
	}

	/**
	 * Validates the declared component graph before any component is resolved: a class
	 * reached twice — listed by two Features, nested under two composites, or both a
	 * Feature component and a composite child — throws, which also catches cycles. The
	 * walk reads {@see CompositeComponentInterface::get_child_component_classes()}
	 * statically, so it covers the whole declared graph regardless of which components
	 * end up enabled.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   list<FeatureInterface> $features Surviving Features.
	 *
	 * @throws  FeatureException When a component class appears more than once in the graph.
	 */
	protected function assert_unique_component_graph( array $features ): void {
		/** @var array<class-string, true> $seen */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- inline @var type assertion, no description applies.
		$seen = array();
		foreach ( $features as $feature ) {
			foreach ( $feature->get_component_classes() as $component_class ) {
				$this->assert_component_not_duplicated( $component_class, $seen );
			}
		}
	}

	/**
	 * Marks a component class seen and recurses into its declared children, throwing if
	 * the class was already seen anywhere in the graph.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   class-string              $component_class Component class to record.
	 * @param   array<class-string, true> $seen            Classes already encountered, by reference.
	 *
	 * @throws  FeatureException When the class has already been seen.
	 */
	protected function assert_component_not_duplicated( string $component_class, array &$seen ): void {
		if ( isset( $seen[ $component_class ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- framework-internal exception; never reaches an HTML output context unescaped.
			throw new FeatureException( 'Component ' . $component_class . ' is registered more than once; a component may belong to a single parent.' );
		}
		$seen[ $component_class ] = true;

		if ( \is_a( $component_class, CompositeComponentInterface::class, true ) ) {
			foreach ( $component_class::get_child_component_classes() as $child_class ) {
				$this->assert_component_not_duplicated( $child_class, $seen );
			}
		}
	}

	/**
	 * Resolves the component tree of every surviving Feature into a flat, pre-order list
	 * of runnable components: a parent precedes its children, and a disabled node's whole
	 * subtree is pruned without resolving its children.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   list<FeatureInterface> $features  Surviving Features.
	 * @param   ContainerInterface     $container Plugin container.
	 *
	 * @return  list<array{class: class-string, component: object}>
	 */
	protected function collect_runnable_components( array $features, ContainerInterface $container ): array {
		/** @var list<array{class: class-string, component: object}> $runnable */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- inline @var type assertion, no description applies.
		$runnable = array();
		foreach ( $features as $feature ) {
			foreach ( $feature->get_component_classes() as $component_class ) {
				$this->collect_runnable_subtree( $component_class, $container, $runnable );
			}
		}

		return $runnable;
	}

	/**
	 * Resolves a component and, when it is runnable, appends it and recurses into its
	 * children. A disabled component is skipped along with its whole subtree.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   class-string                                        $component_class Component class to resolve.
	 * @param   ContainerInterface                                  $container       Plugin container.
	 * @param   list<array{class: class-string, component: object}> $runnable        Accumulating runnable list, by reference.
	 */
	protected function collect_runnable_subtree( string $component_class, ContainerInterface $container, array &$runnable ): void {
		/** @var object $component */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- inline @var type assertion, no description applies.
		$component = $container->get( $component_class );

		if ( ! $this->is_runnable( $component ) ) {
			$this->boot_metrics['pruned_components'][] = $component_class;
			$this->log(
				LogLevel::DEBUG,
				'Component gated out as disabled; its subtree is pruned.',
				array( 'component' => $component_class ),
			);

			return;
		}

		$this->boot_metrics['runnable_components'][] = $component_class;

		$runnable[] = array(
			'class'     => $component_class,
			'component' => $component,
		);

		// Recurse on the declared class-string, the same source of truth the graph
		// validation uses, so the resolved instance's runtime class can never reach a
		// child that duplicate/cycle validation did not see.
		if ( \is_a( $component_class, CompositeComponentInterface::class, true ) ) {
			foreach ( $component_class::get_child_component_classes() as $child_class ) {
				$this->collect_runnable_subtree( $child_class, $container, $runnable );
			}
		}
	}

	/**
	 * A component is runnable unless it implements {@see EnabledInterface} and reports
	 * itself disabled.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   object $component Component to check.
	 *
	 * @return  bool
	 */
	protected function is_runnable( object $component ): bool {
		if ( $component instanceof EnabledInterface && ! $component->is_enabled() ) {
			return false;
		}

		return true;
	}

	/**
	 * Logs and records enabled components that have no kernel-dispatched lifecycle method.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   list<array{class: class-string, component: object}> $runnable_components Enabled components.
	 */
	protected function report_inert_components( array $runnable_components ): void {
		foreach ( $runnable_components as $record ) {
			$component = $record['component'];
			if ( $component instanceof InitializableInterface || $component instanceof HookableInterface ) {
				continue;
			}

			$this->boot_metrics['inert_components'][] = $record['class'];
			$this->log(
				LogLevel::DEBUG,
				'Component has no kernel lifecycle interface and remains inert during boot.',
				array( 'component' => $record['class'] ),
			);
		}
	}

	/**
	 * Captures a plain array copy of each registered tag's callback table. The copy is
	 * near-free on the hot path — PHP copy-on-write shares the arrays until a later
	 * hook-table write separates the live table from the snapshot — and the snapshot's
	 * keys double as the record of which tags existed at window start.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @return  array<string, array<int, array<string, array{function: callable, accepted_args: int}>>>
	 */
	protected function snapshot_hook_table(): array {
		/** @var array<string, object{callbacks: array<int, array<string, array{function: callable, accepted_args: int}>>}> $hook_table */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- inline @var type assertion, no description applies.
		$hook_table = $GLOBALS['wp_filter'] ?? array();

		$snapshot = array();
		foreach ( $hook_table as $tag => $hook ) {
			$snapshot[ $tag ] = $hook->callbacks;
		}

		return $snapshot;
	}

	/**
	 * Restores the snapshotted window-start hook registrations by diffing the snapshot
	 * against the live table and unwinding through add_filter()/remove_filter() only —
	 * WordPress's own API keeps a mid-dispatch hook coherent (WP_Hook resorts its active
	 * iterations), so live WP_Hook internals are never manipulated directly; a tag the
	 * unwind empties is recreated by WordPress as a fresh registry object. A changed
	 * priority bucket is rebuilt in snapshot order, so restored callbacks keep their
	 * original execution order. The restore's scope is the transaction guarantee described
	 * on the class. A residue left by direct hook-table manipulation cannot be removed
	 * through the API and is logged instead.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   array<string, array<int, array<string, array{function: callable, accepted_args: int}>>> $snapshot Window-start hook table captured by {@see self::snapshot_hook_table()}.
	 */
	protected function rollback_hook_table( array $snapshot ): void {
		$live = $this->snapshot_hook_table();

		foreach ( \array_keys( $live + $snapshot ) as $tag ) {
			$live_priorities     = $live[ $tag ] ?? array();
			$snapshot_priorities = $snapshot[ $tag ] ?? array();

			foreach ( \array_keys( $live_priorities + $snapshot_priorities ) as $priority ) {
				$live_bucket     = $live_priorities[ $priority ] ?? array();
				$snapshot_bucket = $snapshot_priorities[ $priority ] ?? array();

				if ( $live_bucket === $snapshot_bucket ) {
					continue;
				}

				foreach ( $live_bucket as $entry ) {
					\remove_filter( $tag, $entry['function'], $priority );
				}
				foreach ( $snapshot_bucket as $entry ) {
					\add_filter( $tag, $entry['function'], $priority, $entry['accepted_args'] );
				}
			}
		}

		if ( ! $this->hook_tables_match( $snapshot, $this->snapshot_hook_table() ) ) {
			$this->log(
				LogLevel::WARNING,
				'Hook-table rollback left a residue; a component mutated the hook table outside the WordPress hook API.',
			);
		}
	}

	/**
	 * Whether two hook-table captures hold the same registrations.
	 *
	 * Compares tag, priority, and callback-id structure — the identity WordPress's own
	 * hook API maintains — without comparing the callables themselves, and independent of
	 * tag order, which rollback legitimately changes when it recreates an emptied tag.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   array<string, array<int, array<string, array{function: callable, accepted_args: int}>>> $left  One hook-table capture.
	 * @param   array<string, array<int, array<string, array{function: callable, accepted_args: int}>>> $right Other hook-table capture.
	 *
	 * @return  bool
	 */
	protected function hook_tables_match( array $left, array $right ): bool {
		$shape = static function ( array $table ): array {
			$normalized = array();
			foreach ( $table as $tag => $priorities ) {
				foreach ( $priorities as $priority => $bucket ) {
					$normalized[ $tag ][ $priority ] = \array_keys( $bucket );
				}
				if ( isset( $normalized[ $tag ] ) ) {
					\ksort( $normalized[ $tag ] );
				}
			}
			\ksort( $normalized );

			return $normalized;
		};

		return $shape( $left ) === $shape( $right );
	}

	/**
	 * Forwards a record to the optional logger, discarding any logger failure.
	 *
	 * Diagnostics never outrank the boot outcome: the boot report already carries the
	 * failure summary, so a throwing logger inside a fail-closed path must not replace a
	 * stopped boot with a fatal.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string               $level   PSR-3 log level.
	 * @param   string               $message Log message.
	 * @param   array<string, mixed> $context Structured log context.
	 */
	protected function log( string $level, string $message, array $context = array() ): void {
		try {
			$this->logger?->log( $level, $message, $context );
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- a diagnostics failure must not alter the boot outcome.
		}
	}

	/**
	 * Builds a report from the accumulated boot metrics, the given status, and an optional failure summary.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   BootStatus  $status  Status of the boot attempt.
	 * @param   string|null $failure Summary of the blocking or failing cause — a throwable's summary, or the downgrade guard's plain-string message — null when none occurred.
	 *
	 * @return  PluginBootReport
	 */
	protected function build_boot_report( BootStatus $status, ?string $failure = null ): PluginBootReport {
		return new PluginBootReport(
			status: $status,
			failure: $failure,
			gated_features: $this->boot_metrics['gated_features'],
			pruned_components: $this->boot_metrics['pruned_components'],
			runnable_components: $this->boot_metrics['runnable_components'],
			inert_components: $this->boot_metrics['inert_components'],
			initialized_components: $this->boot_metrics['initialized_components'],
			hooked_components: $this->boot_metrics['hooked_components'],
		);
	}

	/**
	 * Formats a throwable for log messages without including a stack trace.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   \Throwable $error Error to summarize.
	 *
	 * @return  string
	 */
	protected function format_throwable_summary( \Throwable $error ): string {
		$class   = $error::class;
		$message = $error->getMessage();

		return '' === $message ? $class : $class . ': ' . $message;
	}

	// endregion
}
