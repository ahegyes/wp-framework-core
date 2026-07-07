<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Tests\Unit;

use DeepWebSolutions\Framework\Core\Composite\CompositeComponentInterface;
use DeepWebSolutions\Framework\Core\Conditional\ConditionalInterface;
use DeepWebSolutions\Framework\Core\Enabled\EnabledInterface;
use DeepWebSolutions\Framework\Core\Feature\Exceptions\FeatureException;
use DeepWebSolutions\Framework\Core\Feature\FeatureInterface;
use DeepWebSolutions\Framework\Core\Installer\InstallerInterface;
use DeepWebSolutions\Framework\Core\Lifecycle\Hookable\HookableInterface;
use DeepWebSolutions\Framework\Core\Lifecycle\Initializable\InitializableInterface;
use DeepWebSolutions\Framework\Core\PluginInterface;
use DeepWebSolutions\Framework\Core\PluginKernel;
use DeepWebSolutions\Framework\Core\Tests\Support\FakeWordPressHook;
use DeepWebSolutions\Framework\Core\ValueObjects\BootStatus;
use DeepWebSolutions\Framework\Core\ValueObjects\PluginBootReport;
use DeepWebSolutions\Framework\Core\ValueObjects\PluginHeader;
use DeepWebSolutions\Framework\Shared\Version\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

require_once __DIR__ . '/../Support/wp-hook-stub-functions.php';

#[CoversClass( PluginKernel::class )]
#[UsesClass( FeatureException::class )]
#[UsesClass( PluginBootReport::class )]
#[UsesClass( Version::class )]
final class PluginKernelTest extends TestCase {
	public function test_initializes_all_components_before_registering_any_hooks(): void {
		$log = new PluginKernelTestLog();

		$container = $this->make_container(
			array(
				PluginKernelTestFeatureA::class => new PluginKernelTestFeatureA( array( PluginKernelTestComp::class ) ),
				PluginKernelTestFeatureB::class => new PluginKernelTestFeatureB( array( PluginKernelTestCompB::class ) ),
				PluginKernelTestComp::class     => $this->make_component( 'A', $log ),
				PluginKernelTestCompB::class    => $this->make_component( 'B', $log ),
			),
		);

		$plugin = $this->make_plugin( $container, array( PluginKernelTestFeatureA::class, PluginKernelTestFeatureB::class ) );
		PluginKernel::run( $plugin );

		self::assertSame(
			array( 'A:init', 'B:init', 'A:hooks', 'B:hooks' ),
			$log->entries,
		);
	}

	public function test_failing_conditional_skips_feature_entirely(): void {
		$log = new PluginKernelTestLog();

		$container = $this->make_container(
			array(
				PluginKernelTestFailingCond::class      => new PluginKernelTestFailingCond(),
				PluginKernelTestGatedFailFeature::class => new PluginKernelTestGatedFailFeature( array( PluginKernelTestComp::class ) ),
				PluginKernelTestComp::class             => $this->make_component( 'A', $log ),
			),
		);

		$plugin = $this->make_plugin( $container, array( PluginKernelTestGatedFailFeature::class ) );
		PluginKernel::run( $plugin );

		self::assertSame( array(), $log->entries );
	}

	public function test_unmet_conditional_never_resolves_the_feature(): void {
		$log = new PluginKernelTestLog();

		$container = $this->make_container(
			array(
				PluginKernelTestFailingCond::class      => new PluginKernelTestFailingCond(),
				PluginKernelTestGatedFailFeature::class => new PluginKernelTestGatedFailFeature( array( PluginKernelTestComp::class ) ),
				PluginKernelTestComp::class             => $this->make_component( 'A', $log ),
			),
		);

		$plugin = $this->make_plugin( $container, array( PluginKernelTestGatedFailFeature::class ) );
		PluginKernel::run( $plugin );

		self::assertContains( PluginKernelTestFailingCond::class, $container->resolved );
		self::assertNotContains( PluginKernelTestGatedFailFeature::class, $container->resolved );
		self::assertNotContains( PluginKernelTestComp::class, $container->resolved );
	}

	public function test_met_conditional_runs_the_feature(): void {
		$log = new PluginKernelTestLog();

		$container = $this->make_container(
			array(
				PluginKernelTestPassingCond::class      => new PluginKernelTestPassingCond(),
				PluginKernelTestGatedPassFeature::class => new PluginKernelTestGatedPassFeature( array( PluginKernelTestComp::class ) ),
				PluginKernelTestComp::class             => $this->make_component( 'A', $log ),
			),
		);

		$plugin = $this->make_plugin( $container, array( PluginKernelTestGatedPassFeature::class ) );
		PluginKernel::run( $plugin );

		self::assertSame( array( 'A:init', 'A:hooks' ), $log->entries );
	}

	public function test_misbound_conditional_throws_feature_exception(): void {
		$container = $this->make_container(
			array(
				// The conditional class-string resolves to an object that is not a ConditionalInterface.
				PluginKernelTestPassingCond::class      => new PluginKernelTestNotAConditional(),
				PluginKernelTestGatedPassFeature::class => new PluginKernelTestGatedPassFeature( array() ),
			),
		);

		$plugin = $this->make_plugin( $container, array( PluginKernelTestGatedPassFeature::class ) );

		$this->expectException( FeatureException::class );
		$this->expectExceptionMessage( 'Conditional ' . PluginKernelTestPassingCond::class . ' declared by feature ' . PluginKernelTestGatedPassFeature::class . ' does not implement ' . ConditionalInterface::class . '.' );

		PluginKernel::run( $plugin );
	}

	public function test_disabled_component_skips_lifecycle_methods(): void {
		$log = new PluginKernelTestLog();

		$container = $this->make_container(
			array(
				PluginKernelTestFeatureA::class => new PluginKernelTestFeatureA( array( PluginKernelTestComp::class ) ),
				PluginKernelTestComp::class     => $this->make_component( 'A', $log, false ),
			),
		);

		$plugin = $this->make_plugin( $container, array( PluginKernelTestFeatureA::class ) );
		PluginKernel::run( $plugin );

		self::assertSame( array(), $log->entries );
	}

	public function test_composite_dispatches_parent_before_children_across_both_passes(): void {
		$log = new PluginKernelTestLog();

		$container = $this->make_container(
			array(
				PluginKernelTestFeatureA::class => new PluginKernelTestFeatureA( array( PluginKernelTestGroup::class ) ),
				PluginKernelTestGroup::class    => new PluginKernelTestGroup( $log ),
				PluginKernelTestLeafA::class    => $this->make_component( 'leafA', $log ),
				PluginKernelTestLeafB::class    => $this->make_component( 'leafB', $log ),
			),
		);

		$plugin = $this->make_plugin( $container, array( PluginKernelTestFeatureA::class ) );
		PluginKernel::run( $plugin );

		self::assertSame(
			array( 'group:init', 'leafA:init', 'leafB:init', 'group:hooks', 'leafA:hooks', 'leafB:hooks' ),
			$log->entries,
		);
	}

	public function test_disabled_composite_prunes_its_whole_subtree(): void {
		$log = new PluginKernelTestLog();

		$container = $this->make_container(
			array(
				PluginKernelTestFeatureA::class => new PluginKernelTestFeatureA( array( PluginKernelTestGroup::class ) ),
				PluginKernelTestGroup::class    => new PluginKernelTestGroup( $log, false ),
				PluginKernelTestLeafA::class    => $this->make_component( 'leafA', $log ),
				PluginKernelTestLeafB::class    => $this->make_component( 'leafB', $log ),
			),
		);

		$plugin = $this->make_plugin( $container, array( PluginKernelTestFeatureA::class ) );
		PluginKernel::run( $plugin );

		self::assertSame( array(), $log->entries );
		self::assertNotContains( PluginKernelTestLeafA::class, $container->resolved );
		self::assertNotContains( PluginKernelTestLeafB::class, $container->resolved );
	}

	public function test_disabled_child_is_skipped_while_siblings_run(): void {
		$log = new PluginKernelTestLog();

		$container = $this->make_container(
			array(
				PluginKernelTestFeatureA::class => new PluginKernelTestFeatureA( array( PluginKernelTestGroup::class ) ),
				PluginKernelTestGroup::class    => new PluginKernelTestGroup( $log ),
				PluginKernelTestLeafA::class    => $this->make_component( 'leafA', $log, false ),
				PluginKernelTestLeafB::class    => $this->make_component( 'leafB', $log ),
			),
		);

		$plugin = $this->make_plugin( $container, array( PluginKernelTestFeatureA::class ) );
		PluginKernel::run( $plugin );

		self::assertSame(
			array( 'group:init', 'leafB:init', 'group:hooks', 'leafB:hooks' ),
			$log->entries,
		);
	}

	public function test_dispatch_follows_declared_class_not_resolved_instance(): void {
		$log = new PluginKernelTestLog();

		// The declared class is a plain (non-composite) marker, but the container resolves
		// it to a composite instance. The kernel must treat it as the declared leaf and
		// never recurse into the runtime instance's children — otherwise a child could
		// reach dispatch without passing graph validation.
		$container = $this->make_container(
			array(
				PluginKernelTestFeatureA::class => new PluginKernelTestFeatureA( array( PluginKernelTestComp::class ) ),
				PluginKernelTestComp::class     => new PluginKernelTestGroup( $log ),
				PluginKernelTestLeafA::class    => $this->make_component( 'leafA', $log ),
				PluginKernelTestLeafB::class    => $this->make_component( 'leafB', $log ),
			),
		);

		$plugin = $this->make_plugin( $container, array( PluginKernelTestFeatureA::class ) );
		PluginKernel::run( $plugin );

		self::assertSame( array( 'group:init', 'group:hooks' ), $log->entries );
		self::assertNotContains( PluginKernelTestLeafA::class, $container->resolved );
		self::assertNotContains( PluginKernelTestLeafB::class, $container->resolved );
	}

	public function test_duplicate_component_registration_throws(): void {
		$log = new PluginKernelTestLog();

		$container = $this->make_container(
			array(
				PluginKernelTestFeatureA::class => new PluginKernelTestFeatureA(
					array( PluginKernelTestGroup::class, PluginKernelTestLeafA::class ),
				),
				PluginKernelTestGroup::class    => new PluginKernelTestGroup( $log ),
				PluginKernelTestLeafA::class    => $this->make_component( 'leafA', $log ),
				PluginKernelTestLeafB::class    => $this->make_component( 'leafB', $log ),
			),
		);

		$plugin = $this->make_plugin( $container, array( PluginKernelTestFeatureA::class ) );

		$this->expectException( FeatureException::class );
		$this->expectExceptionMessage( 'Component ' . PluginKernelTestLeafA::class . ' is registered more than once; a component may belong to a single parent.' );

		PluginKernel::run( $plugin );
	}

	public function test_boot_is_idempotent(): void {
		$log = new PluginKernelTestLog();

		$container = $this->make_container(
			array(
				PluginKernelTestFeatureA::class => new PluginKernelTestFeatureA( array( PluginKernelTestComp::class ) ),
				PluginKernelTestComp::class     => $this->make_component( 'A', $log ),
			),
		);

		$plugin = $this->make_plugin( $container, array( PluginKernelTestFeatureA::class ) );
		$kernel = PluginKernel::run( $plugin );
		$kernel->boot();

		self::assertSame( array( 'A:init', 'A:hooks' ), $log->entries );
	}

	public function test_fresh_site_runs_install_then_records_current_version(): void {
		$installer = new PluginKernelTestInstaller( null, Version::from_string( '2.0.0' ) );
		$container = $this->make_container( array() );
		$plugin    = $this->make_plugin( $container, array(), $installer );

		PluginKernel::run( $plugin );

		self::assertSame( array( 'install', 'set:2.0.0' ), $installer->calls );
		self::assertSame( '2.0.0', $installer->stored?->value );
	}

	public function test_older_stored_version_runs_update_then_records_current_version(): void {
		$installer = new PluginKernelTestInstaller( Version::from_string( '1.4.0' ), Version::from_string( '2.0.0' ) );
		$container = $this->make_container( array() );
		$plugin    = $this->make_plugin( $container, array(), $installer );

		PluginKernel::run( $plugin );

		self::assertSame( array( 'update:1.4.0', 'set:2.0.0' ), $installer->calls );
		self::assertSame( '2.0.0', $installer->stored?->value );
	}

	public function test_current_stored_version_runs_neither_install_nor_update(): void {
		$installer = new PluginKernelTestInstaller( Version::from_string( '2.0.0' ), Version::from_string( '2.0.0' ) );
		$container = $this->make_container( array() );
		$plugin    = $this->make_plugin( $container, array(), $installer );

		PluginKernel::run( $plugin );

		self::assertSame( array(), $installer->calls );
	}

	public function test_failed_install_skips_dispatch_and_does_not_record_version(): void {
		$log                         = new PluginKernelTestLog();
		$installer                   = new PluginKernelTestInstaller( null, Version::from_string( '2.0.0' ) );
		$installer->throw_on_install = true;

		$container = $this->make_container(
			array(
				PluginKernelTestFeatureA::class => new PluginKernelTestFeatureA( array( PluginKernelTestComp::class ) ),
				PluginKernelTestComp::class     => $this->make_component( 'A', $log ),
			),
		);

		$plugin = $this->make_plugin( $container, array( PluginKernelTestFeatureA::class ), $installer );
		PluginKernel::run( $plugin );

		self::assertSame( array(), $log->entries );
		self::assertNull( $installer->stored );
		self::assertNotContains( PluginKernelTestComp::class, $container->resolved );
	}

	public function test_failed_update_skips_dispatch_and_does_not_record_version(): void {
		$log                        = new PluginKernelTestLog();
		$installer                  = new PluginKernelTestInstaller( Version::from_string( '1.4.0' ), Version::from_string( '2.0.0' ) );
		$installer->throw_on_update = true;

		$container = $this->make_container(
			array(
				PluginKernelTestFeatureA::class => new PluginKernelTestFeatureA( array( PluginKernelTestComp::class ) ),
				PluginKernelTestComp::class     => $this->make_component( 'A', $log ),
			),
		);

		$plugin = $this->make_plugin( $container, array( PluginKernelTestFeatureA::class ), $installer );
		PluginKernel::run( $plugin );

		self::assertSame( array(), $log->entries );
		self::assertSame( '1.4.0', $installer->stored?->value );
		self::assertNotContains( PluginKernelTestComp::class, $container->resolved );
	}

	public function test_newer_stored_version_fails_closed_without_running_update(): void {
		$log       = new PluginKernelTestLog();
		$logger    = new PluginKernelTestLogger();
		$installer = new PluginKernelTestInstaller( Version::from_string( '2.1.0' ), Version::from_string( '2.0.0' ) );

		$container = $this->make_container(
			array(
				PluginKernelTestFeatureA::class => new PluginKernelTestFeatureA( array( PluginKernelTestComp::class ) ),
				PluginKernelTestComp::class     => $this->make_component( 'A', $log ),
			),
		);

		$plugin = $this->make_plugin( $container, array( PluginKernelTestFeatureA::class ), $installer );
		$kernel = PluginKernel::run( $plugin, $logger );

		self::assertSame( array(), $installer->calls );
		self::assertSame( '2.1.0', $installer->stored?->value );
		self::assertSame( array(), $log->entries );
		self::assertNotContains( PluginKernelTestComp::class, $container->resolved );
		self::assertSame( BootStatus::Blocked, $kernel->boot_report->status );
		self::assertSame( 'Stored plugin version 2.1.0 is newer than code version 2.0.0.', $kernel->boot_report->failure );
		self::assertSame( 'error', $logger->records[0]['level'] );
		self::assertSame( 'Stored plugin version 2.1.0 is newer than code version 2.0.0; skipping component boot for this request.', $logger->records[0]['message'] );
		self::assertSame(
			array(
				'stored_version'  => '2.1.0',
				'current_version' => '2.0.0',
			),
			$logger->records[0]['context'],
		);
	}

	public function test_logs_each_gated_out_feature(): void {
		$logger    = new PluginKernelTestLogger();
		$container = $this->make_container(
			array(
				PluginKernelTestFailingCond::class      => new PluginKernelTestFailingCond(),
				PluginKernelTestGatedFailFeature::class => new PluginKernelTestGatedFailFeature( array() ),
			),
		);

		$plugin = $this->make_plugin( $container, array( PluginKernelTestGatedFailFeature::class ) );
		PluginKernel::run( $plugin, $logger );

		$contexts = \array_column( $logger->records, 'context' );
		$features = \array_column( $contexts, 'feature' );
		self::assertContains( PluginKernelTestGatedFailFeature::class, $features );
	}

	public function test_logs_each_disabled_component(): void {
		$log       = new PluginKernelTestLog();
		$logger    = new PluginKernelTestLogger();
		$container = $this->make_container(
			array(
				PluginKernelTestFeatureA::class => new PluginKernelTestFeatureA( array( PluginKernelTestComp::class ) ),
				PluginKernelTestComp::class     => $this->make_component( 'A', $log, false ),
			),
		);

		$plugin = $this->make_plugin( $container, array( PluginKernelTestFeatureA::class ) );
		PluginKernel::run( $plugin, $logger );

		$contexts   = \array_column( $logger->records, 'context' );
		$components = \array_column( $contexts, 'component' );
		self::assertContains( PluginKernelTestComp::class, $components );
	}

	public function test_boot_report_records_gated_pruned_inert_and_lifecycle_components(): void {
		$log       = new PluginKernelTestLog();
		$logger    = new PluginKernelTestLogger();
		$container = $this->make_container(
			array(
				PluginKernelTestFailingCond::class      => new PluginKernelTestFailingCond(),
				PluginKernelTestGatedFailFeature::class => new PluginKernelTestGatedFailFeature( array( PluginKernelTestCompB::class ) ),
				PluginKernelTestFeatureA::class         => new PluginKernelTestFeatureA( array( PluginKernelTestComp::class, PluginKernelTestGroup::class ) ),
				PluginKernelTestComp::class             => new \stdClass(),
				PluginKernelTestCompB::class            => $this->make_component( 'gated', $log ),
				PluginKernelTestGroup::class            => new PluginKernelTestGroup( $log ),
				PluginKernelTestLeafA::class            => $this->make_component( 'leafA', $log, false ),
				PluginKernelTestLeafB::class            => $this->make_component( 'leafB', $log ),
			),
		);

		$plugin = $this->make_plugin( $container, array( PluginKernelTestGatedFailFeature::class, PluginKernelTestFeatureA::class ) );
		$kernel = PluginKernel::run( $plugin, $logger );

		self::assertSame(
			array(
				array(
					'feature'     => PluginKernelTestGatedFailFeature::class,
					'conditional' => PluginKernelTestFailingCond::class,
				),
			),
			$kernel->boot_report->gated_features,
		);
		self::assertSame( array( PluginKernelTestLeafA::class ), $kernel->boot_report->pruned_components );
		self::assertSame( array( PluginKernelTestComp::class ), $kernel->boot_report->inert_components );
		self::assertSame( array( PluginKernelTestComp::class, PluginKernelTestGroup::class, PluginKernelTestLeafB::class ), $kernel->boot_report->runnable_components );
		self::assertSame( array( PluginKernelTestGroup::class, PluginKernelTestLeafB::class ), $kernel->boot_report->initialized_components );
		self::assertSame( array( PluginKernelTestGroup::class, PluginKernelTestLeafB::class ), $kernel->boot_report->hooked_components );
		self::assertSame( array( 'group:init', 'leafB:init', 'group:hooks', 'leafB:hooks' ), $log->entries );

		$contexts   = \array_column( $logger->records, 'context' );
		$components = \array_column( $contexts, 'component' );
		self::assertContains( PluginKernelTestComp::class, $components );
	}

	public function test_logs_install_failure_at_error_level(): void {
		$logger    = new PluginKernelTestLogger();
		$installer = new PluginKernelTestInstaller( null, Version::from_string( '2.0.0' ) );

		$installer->throw_on_install = true;

		$container = $this->make_container( array() );
		$plugin    = $this->make_plugin( $container, array(), $installer );
		PluginKernel::run( $plugin, $logger );

		$levels = \array_column( $logger->records, 'level' );
		self::assertContains( 'error', $levels );
		self::assertSame(
			'Plugin installation routine failed; skipping component boot for this request. ' . \RuntimeException::class . ': install failed',
			$logger->records[0]['message'],
		);
	}

	public function test_throwing_feature_resolution_is_caught_and_halts_component_boot(): void {
		$log       = new PluginKernelTestLog();
		$logger    = new PluginKernelTestLogger();
		$container = $this->make_container(
			array(
				PluginKernelTestComp::class => $this->make_component( 'A', $log ),
			),
			array(
				PluginKernelTestFeatureA::class => new \RuntimeException( 'feature cannot be resolved' ),
			),
		);

		$plugin = $this->make_plugin( $container, array( PluginKernelTestFeatureA::class ) );
		PluginKernel::run( $plugin, $logger );

		self::assertSame( array(), $log->entries );
		self::assertSame( array( PluginKernelTestFeatureA::class ), $container->resolved );
		self::assertNotContains( PluginKernelTestComp::class, $container->resolved );
		self::assertCount( 1, $logger->records );
		self::assertSame( 'error', $logger->records[0]['level'] );
		self::assertStringContainsString( \RuntimeException::class . ': feature cannot be resolved', $logger->records[0]['message'] );

		$exception = $logger->records[0]['context']['exception'] ?? null;
		self::assertInstanceOf( \RuntimeException::class, $exception );
		self::assertSame( 'feature cannot be resolved', $exception->getMessage() );
	}

	public function test_failing_hook_registration_rolls_back_all_hook_table_changes(): void {
		$logger        = new PluginKernelTestLogger();
		$had_wp_filter = \array_key_exists( 'wp_filter', $GLOBALS );
		$prior_filter  = $GLOBALS['wp_filter'] ?? null;

		$GLOBALS['wp_filter'] = array();

		$preexisting = static fn ( mixed $value ): mixed => $value;
		$added_new   = static fn ( mixed $value, mixed $extra = null ): mixed => $value;
		$added_more  = static fn ( mixed $value ): mixed => $value;

		try {
			\add_filter( 'existing_hook', $preexisting, 10 );
			$before = $this->normalized_hook_table();

			$container = $this->make_container(
				array(
					PluginKernelTestFeatureA::class => new PluginKernelTestFeatureA(
						array( PluginKernelTestHookMutatingComp::class, PluginKernelTestHookThrowingComp::class ),
					),
					PluginKernelTestHookMutatingComp::class => new PluginKernelTestHookMutatingComp(
						static function () use ( $preexisting, $added_new, $added_more ): void {
							\add_filter( 'new_hook', $added_new, 10, 2 );
							\add_filter( 'existing_hook', $added_more, 20 );
							\remove_filter( 'existing_hook', $preexisting, 10 );
						},
					),
					PluginKernelTestHookThrowingComp::class => new PluginKernelTestHookThrowingComp(),
				),
			);

			$plugin = $this->make_plugin( $container, array( PluginKernelTestFeatureA::class ) );
			$kernel = PluginKernel::run( $plugin, $logger );

			self::assertSame( $before, $this->normalized_hook_table() );
			self::assertArrayNotHasKey( 'new_hook', $this->normalized_hook_table() );
			self::assertSame( BootStatus::Failed, $kernel->boot_report->status );
			self::assertSame( array(), $kernel->boot_report->hooked_components );
			self::assertSame( 'error', $logger->records[0]['level'] );
			self::assertSame(
				'Plugin component boot failed; every hook registered during the attempt (constructor, initialize(), register_hooks()) was rolled back. Non-hook side effects are not transactional. ' . \RuntimeException::class . ': hook registration failed',
				$logger->records[0]['message'],
			);
		} finally {
			if ( $had_wp_filter ) {
				$GLOBALS['wp_filter'] = $prior_filter;
			} else {
				unset( $GLOBALS['wp_filter'] );
			}
		}
	}

	public function test_failing_initialization_rolls_back_hook_table_changes(): void {
		$logger        = new PluginKernelTestLogger();
		$had_wp_filter = \array_key_exists( 'wp_filter', $GLOBALS );
		$prior_filter  = $GLOBALS['wp_filter'] ?? null;

		$GLOBALS['wp_filter'] = array();

		$added = static fn ( mixed $value ): mixed => $value;

		try {
			$before = $this->normalized_hook_table();

			$container = $this->make_container(
				array(
					PluginKernelTestFeatureA::class => new PluginKernelTestFeatureA(
						array( PluginKernelTestInitMutatingComp::class, PluginKernelTestInitThrowingComp::class ),
					),
					PluginKernelTestInitMutatingComp::class => new PluginKernelTestInitMutatingComp(
						static function () use ( $added ): void {
							\add_filter( 'init_added_hook', $added, 10 );
						},
					),
					PluginKernelTestInitThrowingComp::class => new PluginKernelTestInitThrowingComp(),
				),
			);

			$plugin = $this->make_plugin( $container, array( PluginKernelTestFeatureA::class ) );
			$kernel = PluginKernel::run( $plugin, $logger );

			self::assertSame( $before, $this->normalized_hook_table() );
			self::assertSame( BootStatus::Failed, $kernel->boot_report->status );
			self::assertSame( array(), $kernel->boot_report->hooked_components );
			self::assertSame( 'error', $logger->records[0]['level'] );
		} finally {
			if ( $had_wp_filter ) {
				$GLOBALS['wp_filter'] = $prior_filter;
			} else {
				unset( $GLOBALS['wp_filter'] );
			}
		}
	}

	public function test_a_throwing_logger_does_not_escape_a_failed_boot(): void {
		$had_wp_filter = \array_key_exists( 'wp_filter', $GLOBALS );
		$prior_filter  = $GLOBALS['wp_filter'] ?? null;

		$GLOBALS['wp_filter'] = array();

		try {
			$container = $this->make_container(
				array(
					PluginKernelTestFeatureA::class => new PluginKernelTestFeatureA(
						array( PluginKernelTestHookThrowingComp::class ),
					),
					PluginKernelTestHookThrowingComp::class => new PluginKernelTestHookThrowingComp(),
				),
			);

			$plugin = $this->make_plugin( $container, array( PluginKernelTestFeatureA::class ) );
			$kernel = PluginKernel::run( $plugin, new PluginKernelTestThrowingLogger() );

			self::assertSame( BootStatus::Failed, $kernel->boot_report->status );
			self::assertStringContainsString( 'hook registration failed', (string) $kernel->boot_report->failure );
		} finally {
			if ( $had_wp_filter ) {
				$GLOBALS['wp_filter'] = $prior_filter;
			} else {
				unset( $GLOBALS['wp_filter'] );
			}
		}
	}

	public function test_rollback_logs_a_residue_left_by_direct_hook_table_manipulation(): void {
		$logger        = new PluginKernelTestLogger();
		$had_wp_filter = \array_key_exists( 'wp_filter', $GLOBALS );
		$prior_filter  = $GLOBALS['wp_filter'] ?? null;

		$GLOBALS['wp_filter'] = array();

		$direct = static fn ( mixed $value ): mixed => $value;

		$directly_added_hook                              = new FakeWordPressHook();
		$directly_added_hook->callbacks[10]['direct_key'] = array(
			'function'      => $direct,
			'accepted_args' => 1,
		);

		try {
			$container = $this->make_container(
				array(
					PluginKernelTestFeatureA::class => new PluginKernelTestFeatureA(
						array( PluginKernelTestHookMutatingComp::class, PluginKernelTestHookThrowingComp::class ),
					),
					PluginKernelTestHookMutatingComp::class => new PluginKernelTestHookMutatingComp(
						static function () use ( $directly_added_hook ): void {
							$GLOBALS['wp_filter']['direct_hook'] = $directly_added_hook;
						},
					),
					PluginKernelTestHookThrowingComp::class => new PluginKernelTestHookThrowingComp(),
				),
			);

			$plugin = $this->make_plugin( $container, array( PluginKernelTestFeatureA::class ) );
			$kernel = PluginKernel::run( $plugin, $logger );

			self::assertSame( BootStatus::Failed, $kernel->boot_report->status );
			self::assertContains( 'warning', \array_column( $logger->records, 'level' ) );
			self::assertArrayHasKey( 'direct_key', $directly_added_hook->callbacks[10] );
		} finally {
			if ( $had_wp_filter ) {
				$GLOBALS['wp_filter'] = $prior_filter;
			} else {
				unset( $GLOBALS['wp_filter'] );
			}
		}
	}

	public function test_throwing_installer_resolution_is_caught_and_halts_boot(): void {
		$logger = new PluginKernelTestLogger();

		// A container-backed get_installer() that throws — e.g. a missing or misconfigured
		// installer binding — must be caught like any installer-routine failure, not propagate
		// out of boot() and fatal every request. get_container() throws so the test also proves
		// the boot stops before reaching the component graph.
		$plugin = new class() implements PluginInterface {
			public function get_plugin_file(): string {
				return '/tmp/fake.php';
			}

			public function get_plugin_header(): PluginHeader {
				throw new \RuntimeException( 'boot must not read the header' );
			}

			public function get_container(): ContainerInterface {
				throw new \RuntimeException( 'boot must stop before resolving the container' );
			}

			/**
			 * @return list<class-string<FeatureInterface>>
			 */
			public function get_feature_classes(): array {
				return array();
			}

			public function get_installer(): InstallerInterface {
				throw new \RuntimeException( 'installer cannot be resolved' );
			}
		};

		PluginKernel::run( $plugin, $logger );

		self::assertCount( 1, $logger->records );
		self::assertSame( 'error', $logger->records[0]['level'] );
		self::assertStringContainsString( \RuntimeException::class . ': installer cannot be resolved', $logger->records[0]['message'] );

		$exception = $logger->records[0]['context']['exception'] ?? null;
		self::assertInstanceOf( \RuntimeException::class, $exception );
		self::assertSame( 'installer cannot be resolved', $exception->getMessage() );
	}

	public function test_component_graph_failure_rolls_back_hooks_added_during_feature_resolution(): void {
		$had_wp_filter = \array_key_exists( 'wp_filter', $GLOBALS );
		$prior_filter  = $GLOBALS['wp_filter'] ?? null;

		$GLOBALS['wp_filter'] = array();

		try {
			$before = $this->normalized_hook_table();

			// The feature declares the same component twice, so graph validation throws after the
			// feature — and the hook its resolution registered — lands inside the transaction window.
			$container = new class() implements ContainerInterface {
				public function get( string $id ): mixed {
					\add_filter( 'feature_resolution_hook', static fn ( mixed $value ): mixed => $value, 10 );

					return new PluginKernelTestFeatureA( array( PluginKernelTestComp::class, PluginKernelTestComp::class ) );
				}

				public function has( string $id ): bool {
					return true;
				}
			};

			$plugin = $this->make_plugin( $container, array( PluginKernelTestFeatureA::class ) );
			$kernel = new PluginKernel( $plugin );

			$caught = null;
			try {
				$kernel->boot();
			} catch ( FeatureException $error ) {
				$caught = $error;
			}

			self::assertInstanceOf( FeatureException::class, $caught );
			self::assertSame( $before, $this->normalized_hook_table() );
			self::assertArrayNotHasKey( 'feature_resolution_hook', $this->normalized_hook_table() );
			self::assertSame( BootStatus::Failed, $kernel->boot_report->status );
			self::assertSame(
				FeatureException::class . ': Component ' . PluginKernelTestComp::class . ' is registered more than once; a component may belong to a single parent.',
				$kernel->boot_report->failure,
			);
		} finally {
			if ( $had_wp_filter ) {
				$GLOBALS['wp_filter'] = $prior_filter;
			} else {
				unset( $GLOBALS['wp_filter'] );
			}
		}
	}

	public function test_declared_non_feature_class_throws_and_rolls_back_hooks_added_during_feature_resolution(): void {
		$had_wp_filter = \array_key_exists( 'wp_filter', $GLOBALS );
		$prior_filter  = $GLOBALS['wp_filter'] ?? null;

		$GLOBALS['wp_filter'] = array();

		try {
			$before = $this->normalized_hook_table();

			// The second declared "feature" is a plain marker class, so the malformed-Feature guard
			// throws after the first feature — and the hook its resolution registered — lands inside
			// the transaction window.
			$container = new class() implements ContainerInterface {
				public function get( string $id ): mixed {
					\add_filter( 'feature_resolution_hook', static fn ( mixed $value ): mixed => $value, 10 );

					return new PluginKernelTestFeatureA( array() );
				}

				public function has( string $id ): bool {
					return true;
				}
			};

			// @phpstan-ignore argument.type (the malformed feature list is the point of the test)
			$plugin = $this->make_plugin( $container, array( PluginKernelTestFeatureA::class, PluginKernelTestComp::class ) );
			$kernel = new PluginKernel( $plugin );

			$caught = null;
			try {
				$kernel->boot();
			} catch ( FeatureException $error ) {
				$caught = $error;
			}

			self::assertInstanceOf( FeatureException::class, $caught );
			self::assertSame( 'Feature ' . PluginKernelTestComp::class . ' does not implement ' . FeatureInterface::class . '.', $caught->getMessage() );
			self::assertSame( $before, $this->normalized_hook_table() );
			self::assertArrayNotHasKey( 'feature_resolution_hook', $this->normalized_hook_table() );
			self::assertSame( BootStatus::Failed, $kernel->boot_report->status );
		} finally {
			if ( $had_wp_filter ) {
				$GLOBALS['wp_filter'] = $prior_filter;
			} else {
				unset( $GLOBALS['wp_filter'] );
			}
		}
	}

	public function test_inert_component_after_a_lifecycle_component_is_still_reported(): void {
		$log       = new PluginKernelTestLog();
		$container = $this->make_container(
			array(
				PluginKernelTestFeatureA::class => new PluginKernelTestFeatureA( array( PluginKernelTestCompB::class, PluginKernelTestComp::class ) ),
				PluginKernelTestCompB::class    => $this->make_component( 'B', $log ),
				PluginKernelTestComp::class     => new \stdClass(),
			),
		);

		$plugin = $this->make_plugin( $container, array( PluginKernelTestFeatureA::class ) );
		$kernel = PluginKernel::run( $plugin );

		self::assertSame( array( PluginKernelTestComp::class ), $kernel->boot_report->inert_components );
		self::assertSame( array( 'B:init', 'B:hooks' ), $log->entries );
	}

	public function test_boot_report_before_boot_has_the_full_not_started_shape(): void {
		$kernel = new PluginKernel( $this->make_plugin( $this->make_container( array() ), array() ) );
		$report = $kernel->boot_report;

		self::assertSame( BootStatus::NotStarted, $report->status );
		self::assertNull( $report->failure );
		self::assertSame( array(), $report->gated_features );
		self::assertSame( array(), $report->pruned_components );
		self::assertSame( array(), $report->runnable_components );
		self::assertSame( array(), $report->inert_components );
		self::assertSame( array(), $report->initialized_components );
		self::assertSame( array(), $report->hooked_components );
	}

	public function test_boot_report_reads_as_running_while_boot_is_in_flight(): void {
		$observed  = null;
		$container = $this->make_container(
			array(
				PluginKernelTestFeatureA::class => new PluginKernelTestFeatureA( array( PluginKernelTestHookMutatingComp::class ) ),
			),
		);

		$plugin = $this->make_plugin( $container, array( PluginKernelTestFeatureA::class ) );
		$kernel = new PluginKernel( $plugin );
		$container->set(
			PluginKernelTestHookMutatingComp::class,
			new PluginKernelTestHookMutatingComp(
				static function () use ( $kernel, &$observed ): void {
					$observed = $kernel->boot_report->status;
				},
			),
		);
		$kernel->boot();

		self::assertSame( BootStatus::Running, $observed );
		self::assertSame( BootStatus::Completed, $kernel->boot_report->status );
	}

	public function test_hook_table_comparison_matches_structure_not_callables_or_order(): void {
		$kernel = new PluginKernel( $this->make_plugin( $this->make_container( array() ), array() ) );
		$match  = new \ReflectionMethod( $kernel, 'hook_tables_match' );

		$entry_a = array(
			'function'      => static fn ( mixed $value ): mixed => $value,
			'accepted_args' => 1,
		);
		$entry_b = array(
			'function'      => static fn ( mixed $value ): mixed => $value,
			'accepted_args' => 2,
		);

		// Same tag/priority/callback-id structure with different callables and accepted_args: a match.
		self::assertTrue(
			$match->invoke(
				$kernel,
				array( 'tag_one' => array( 10 => array( 'key_a' => $entry_a ) ) ),
				array( 'tag_one' => array( 10 => array( 'key_a' => $entry_b ) ) ),
			),
		);

		// Same registrations with tags and priorities captured in different orders: a match.
		self::assertTrue(
			$match->invoke(
				$kernel,
				array(
					'tag_two' => array(
						20 => array( 'key_b' => $entry_a ),
						10 => array( 'key_a' => $entry_a ),
					),
					'tag_one' => array( 10 => array( 'key_a' => $entry_a ) ),
				),
				array(
					'tag_one' => array( 10 => array( 'key_a' => $entry_a ) ),
					'tag_two' => array(
						10 => array( 'key_a' => $entry_a ),
						20 => array( 'key_b' => $entry_a ),
					),
				),
			),
		);

		// A registration difference in the second-sorted tag: a mismatch.
		self::assertFalse(
			$match->invoke(
				$kernel,
				array(
					'tag_one' => array( 10 => array( 'key_a' => $entry_a ) ),
					'tag_two' => array( 10 => array( 'key_a' => $entry_a ) ),
				),
				array(
					'tag_one' => array( 10 => array( 'key_a' => $entry_a ) ),
					'tag_two' => array( 10 => array( 'key_other' => $entry_a ) ),
				),
			),
		);
	}

	/**
	 * @param array<string, object> $services
	 * @param array<string, \Throwable> $throwing
	 */
	private function make_container( array $services, array $throwing = array() ): PluginKernelTestContainer {
		return new PluginKernelTestContainer( $services, $throwing );
	}

	/**
	 * The live hook table reduced to tag => callbacks, tag-order-insensitive, so a
	 * rolled-back table can be compared byte-for-byte against the pre-window state.
	 *
	 * @return array<string, array<int, array<string, array{function: callable, accepted_args: int}>>>
	 */
	private function normalized_hook_table(): array {
		$table = array();
		foreach ( $GLOBALS['wp_filter'] ?? array() as $tag => $hook ) {
			$table[ $tag ] = $hook->callbacks;
		}
		\ksort( $table );

		return $table;
	}

	private function make_component( string $name, PluginKernelTestLog $log, bool $enabled = true ): object {
		return new class( $name, $log, $enabled ) implements InitializableInterface, HookableInterface, EnabledInterface {
			public function __construct(
				private string $name,
				private PluginKernelTestLog $log,
				private bool $enabled,
			) {}

			public function is_enabled(): bool {
				return $this->enabled;
			}

			public function initialize(): void {
				$this->log->entries[] = $this->name . ':init';
			}

			public function register_hooks(): void {
				$this->log->entries[] = $this->name . ':hooks';
			}
		};
	}

	/**
	 * @param list<class-string<FeatureInterface>> $features
	 */
	private function make_plugin( ContainerInterface $container, array $features, ?InstallerInterface $installer = null ): PluginInterface {
		$installer ??= new PluginKernelTestInstaller( Version::from_string( '2.0.0' ), Version::from_string( '2.0.0' ) );

		return new class( $container, $features, $installer ) implements PluginInterface {
			/**
			 * @param list<class-string<FeatureInterface>> $features
			 */
			public function __construct(
				private ContainerInterface $container,
				private array $features,
				private InstallerInterface $installer,
			) {}

			public function get_plugin_file(): string {
				return '/tmp/fake.php';
			}

			public function get_plugin_header(): PluginHeader {
				throw new \RuntimeException( 'boot must not read the header' );
			}

			public function get_container(): ContainerInterface {
				return $this->container;
			}

			/**
			 * @return list<class-string<FeatureInterface>>
			 */
			public function get_feature_classes(): array {
				return $this->features;
			}

			public function get_installer(): InstallerInterface {
				return $this->installer;
			}
		};
	}
}

/**
 * Simple log holder so anonymous classes can pin a typed dependency.
 */
final class PluginKernelTestLog {
	/**
	 * @var list<string>
	 */
	public array $entries = array();
}

/**
 * Mutable PSR-11 container that records every resolved id, so tests can assert that a
 * gated-out Feature or pruned subtree was never constructed.
 */
final class PluginKernelTestContainer implements ContainerInterface {
	/**
	 * @var list<string>
	 */
	public array $resolved = array();

	/**
	 * @param array<string, object>     $storage
	 * @param array<string, \Throwable> $throwing
	 */
	public function __construct(
		private array $storage = array(),
		private array $throwing = array(),
	) {}

	public function get( string $id ): mixed {
		$this->resolved[] = $id;
		if ( isset( $this->throwing[ $id ] ) ) {
			throw $this->throwing[ $id ];
		}

		if ( ! isset( $this->storage[ $id ] ) ) {
			throw new \OutOfBoundsException( 'Unbound container id: ' . $id );
		}

		return $this->storage[ $id ];
	}

	public function has( string $id ): bool {
		return isset( $this->storage[ $id ] );
	}

	public function set( string $id, object $value ): void {
		$this->storage[ $id ] = $value;
	}
}

/**
 * Recording installer with controllable stored/current versions and an optional
 * install failure, exercising the kernel's version-check orchestration without WordPress.
 */
final class PluginKernelTestInstaller implements InstallerInterface {
	/**
	 * @var list<string>
	 */
	public array $calls = array();

	public bool $throw_on_install = false;

	public bool $throw_on_update = false;

	public function __construct(
		public ?Version $stored,
		public Version $current,
	) {}

	public function install(): void {
		$this->calls[] = 'install';
		if ( $this->throw_on_install ) {
			throw new \RuntimeException( 'install failed' );
		}
	}

	public function update( Version $from_version ): void {
		$this->calls[] = 'update:' . $from_version->value;
		if ( $this->throw_on_update ) {
			throw new \RuntimeException( 'update failed' );
		}
	}

	public function activate( bool $network_wide = false ): void {}

	public function deactivate( bool $network_deactivating = false ): void {}

	public function uninstall(): void {}

	public function get_current_version(): Version {
		return $this->current;
	}

	public function get_stored_version(): ?Version {
		return $this->stored;
	}

	public function set_stored_version( Version $version ): void {
		$this->stored  = $version;
		$this->calls[] = 'set:' . $version->value;
	}
}

/**
 * Recording PSR-3 logger capturing level + message + context per call.
 */
final class PluginKernelTestLogger extends \Psr\Log\AbstractLogger {
	/**
	 * @var list<array{level: mixed, message: string, context: array<array-key, mixed>}>
	 */
	public array $records = array();

	/**
	 * @param array<array-key, mixed> $context
	 */
	public function log( $level, string|\Stringable $message, array $context = array() ): void {
		$this->records[] = array(
			'level'   => $level,
			'message' => (string) $message,
			'context' => $context,
		);
	}
}

/**
 * Feature with no conditionals and a configurable component list.
 */
final class PluginKernelTestFeatureA implements FeatureInterface {
	/**
	 * @param list<class-string> $components
	 */
	public function __construct(
		private array $components = array(),
	) {}

	/**
	 * @return list<class-string<ConditionalInterface>>
	 */
	public static function get_conditional_classes(): array {
		return array();
	}

	/**
	 * @return list<class-string>
	 */
	public function get_component_classes(): array {
		return $this->components;
	}
}

/**
 * Second no-conditional feature, distinct class so two can coexist in one container.
 */
final class PluginKernelTestFeatureB implements FeatureInterface {
	/**
	 * @param list<class-string> $components
	 */
	public function __construct(
		private array $components = array(),
	) {}

	/**
	 * @return list<class-string<ConditionalInterface>>
	 */
	public static function get_conditional_classes(): array {
		return array();
	}

	/**
	 * @return list<class-string>
	 */
	public function get_component_classes(): array {
		return $this->components;
	}
}

/**
 * Feature gated by an always-failing conditional.
 */
final class PluginKernelTestGatedFailFeature implements FeatureInterface {
	/**
	 * @param list<class-string> $components
	 */
	public function __construct(
		private array $components = array(),
	) {}

	/**
	 * @return list<class-string<ConditionalInterface>>
	 */
	public static function get_conditional_classes(): array {
		return array( PluginKernelTestFailingCond::class );
	}

	/**
	 * @return list<class-string>
	 */
	public function get_component_classes(): array {
		return $this->components;
	}
}

/**
 * Feature gated by an always-passing conditional.
 */
final class PluginKernelTestGatedPassFeature implements FeatureInterface {
	/**
	 * @param list<class-string> $components
	 */
	public function __construct(
		private array $components = array(),
	) {}

	/**
	 * @return list<class-string<ConditionalInterface>>
	 */
	public static function get_conditional_classes(): array {
		return array( PluginKernelTestPassingCond::class );
	}

	/**
	 * @return list<class-string>
	 */
	public function get_component_classes(): array {
		return $this->components;
	}
}

/**
 * Composite group with two static children; enablement is controllable.
 */
final class PluginKernelTestGroup implements InitializableInterface, HookableInterface, EnabledInterface, CompositeComponentInterface {
	public function __construct(
		private PluginKernelTestLog $log,
		private bool $enabled = true,
	) {}

	/**
	 * @return list<class-string>
	 */
	public static function get_child_component_classes(): array {
		return array( PluginKernelTestLeafA::class, PluginKernelTestLeafB::class );
	}

	public function is_enabled(): bool {
		return $this->enabled;
	}

	public function initialize(): void {
		$this->log->entries[] = 'group:init';
	}

	public function register_hooks(): void {
		$this->log->entries[] = 'group:hooks';
	}
}

/**
 * Always-failing conditional.
 */
final class PluginKernelTestFailingCond implements ConditionalInterface {
	public function is_met(): bool {
		return false;
	}
}

/**
 * Always-passing conditional.
 */
final class PluginKernelTestPassingCond implements ConditionalInterface {
	public function is_met(): bool {
		return true;
	}
}

/**
 * Stand-in that does NOT implement ConditionalInterface, for the fail-closed test.
 */
final class PluginKernelTestNotAConditional {}

/**
 * Marker class used as a container key.
 */
final class PluginKernelTestComp {}

/**
 * Marker class used as a container key.
 */
final class PluginKernelTestCompB {}

/**
 * Marker class used as a composite child container key.
 */
final class PluginKernelTestLeafA {}

/**
 * Marker class used as a composite child container key.
 */
final class PluginKernelTestLeafB {}

/**
 * Hookable component that runs a configurable hook-table mutation from register_hooks().
 */
final class PluginKernelTestHookMutatingComp implements HookableInterface {
	public function __construct(
		private \Closure $mutator,
	) {}

	public function register_hooks(): void {
		( $this->mutator )();
	}
}

final class PluginKernelTestHookThrowingComp implements HookableInterface {
	public function register_hooks(): void {
		throw new \RuntimeException( 'hook registration failed' );
	}
}

/**
 * PSR-3 logger whose every record throws, simulating a broken diagnostic sink.
 */
final class PluginKernelTestThrowingLogger extends \Psr\Log\AbstractLogger {
	/**
	 * @param array<array-key, mixed> $context
	 */
	public function log( $level, string|\Stringable $message, array $context = array() ): void {
		throw new \RuntimeException( 'logger sink failure' );
	}
}

/**
 * Initializable component that runs a configurable hook-table mutation from initialize().
 */
final class PluginKernelTestInitMutatingComp implements InitializableInterface {
	public function __construct(
		private \Closure $mutator,
	) {}

	public function initialize(): void {
		( $this->mutator )();
	}
}

final class PluginKernelTestInitThrowingComp implements InitializableInterface {
	public function initialize(): void {
		throw new \RuntimeException( 'initialize failed' );
	}
}
