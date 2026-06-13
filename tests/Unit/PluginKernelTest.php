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
use DeepWebSolutions\Framework\Core\ValueObjects\PluginHeader;
use DeepWebSolutions\Framework\Shared\Version\Version;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

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
		$this->expectExceptionMessage( PluginKernelTestPassingCond::class );

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
		$this->expectExceptionMessage( PluginKernelTestLeafA::class );

		PluginKernel::run( $plugin );
	}

	public function test_component_resolves_peer_services_from_the_shared_container(): void {
		$log = new PluginKernelTestLog();

		$container = $this->make_container(
			array(
				PluginKernelTestFeatureA::class => new PluginKernelTestFeatureA( array( PluginKernelTestComp::class ) ),
				'ServiceX'                      => new \stdClass(),
			),
		);

		$component = new class( $log, $container ) implements InitializableInterface {
			public function __construct(
				private PluginKernelTestLog $log,
				private ContainerInterface $container,
			) {}

			public function initialize(): void {
				$this->log->entries[] = $this->container->has( 'ServiceX' ) ? 'ServiceX-found' : 'ServiceX-missing';
			}
		};
		$container->set( PluginKernelTestComp::class, $component );

		$plugin = $this->make_plugin( $container, array( PluginKernelTestFeatureA::class ) );
		PluginKernel::run( $plugin );

		self::assertSame( array( 'ServiceX-found' ), $log->entries );
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
		$container  = $this->make_container( array() );
		$plugin     = $this->make_plugin( $container, array(), $installer );

		PluginKernel::run( $plugin );

		self::assertSame( array( 'install', 'set:2.0.0' ), $installer->calls );
		self::assertSame( '2.0.0', $installer->stored?->value );
	}

	public function test_older_stored_version_runs_update_then_records_current_version(): void {
		$installer = new PluginKernelTestInstaller( Version::from_string( '1.4.0' ), Version::from_string( '2.0.0' ) );
		$container  = $this->make_container( array() );
		$plugin     = $this->make_plugin( $container, array(), $installer );

		PluginKernel::run( $plugin );

		self::assertSame( array( 'update:1.4.0', 'set:2.0.0' ), $installer->calls );
		self::assertSame( '2.0.0', $installer->stored?->value );
	}

	public function test_current_stored_version_runs_neither_install_nor_update(): void {
		$installer = new PluginKernelTestInstaller( Version::from_string( '2.0.0' ), Version::from_string( '2.0.0' ) );
		$container  = $this->make_container( array() );
		$plugin     = $this->make_plugin( $container, array(), $installer );

		PluginKernel::run( $plugin );

		self::assertSame( array(), $installer->calls );
	}

	public function test_failed_install_skips_dispatch_and_does_not_record_version(): void {
		$log       = new PluginKernelTestLog();
		$installer = new PluginKernelTestInstaller( null, Version::from_string( '2.0.0' ) );
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
		$log       = new PluginKernelTestLog();
		$installer = new PluginKernelTestInstaller( Version::from_string( '1.4.0' ), Version::from_string( '2.0.0' ) );
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

	public function test_logs_install_failure_at_error_level(): void {
		$logger    = new PluginKernelTestLogger();
		$installer = new PluginKernelTestInstaller( null, Version::from_string( '2.0.0' ) );

		$installer->throw_on_install = true;

		$container = $this->make_container( array() );
		$plugin    = $this->make_plugin( $container, array(), $installer );
		PluginKernel::run( $plugin, $logger );

		$levels = \array_column( $logger->records, 'level' );
		self::assertContains( 'error', $levels );
	}

	/**
	 * @param array<string, object> $services
	 */
	private function make_container( array $services ): PluginKernelTestContainer {
		return new PluginKernelTestContainer( $services );
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
	 * @param array<string, object> $storage
	 */
	public function __construct(
		private array $storage = array(),
	) {}

	public function get( string $id ): mixed {
		$this->resolved[] = $id;
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
