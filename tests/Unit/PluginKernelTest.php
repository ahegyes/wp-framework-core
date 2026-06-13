<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Tests\Unit;

use DeepWebSolutions\Framework\Core\Conditional\ConditionalInterface;
use DeepWebSolutions\Framework\Core\Enabled\EnabledInterface;
use DeepWebSolutions\Framework\Core\Feature\FeatureInterface;
use DeepWebSolutions\Framework\Core\Lifecycle\Hookable\HookableInterface;
use DeepWebSolutions\Framework\Core\Lifecycle\Initializable\InitializableInterface;
use DeepWebSolutions\Framework\Core\PluginInterface;
use DeepWebSolutions\Framework\Core\PluginKernel;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class PluginKernelTest extends TestCase {
	public function test_initializes_all_components_before_registering_any_hooks(): void {
		$log = new PluginKernelTestLog();

		$component_a = $this->make_component( 'A', $log );
		$component_b = $this->make_component( 'B', $log );

		$feature_a = $this->make_feature( array( 'CompA' ) );
		$feature_b = $this->make_feature( array( 'CompB' ) );

		$container = $this->make_container(
			array(
				'FeatureA' => $feature_a,
				'FeatureB' => $feature_b,
				'CompA'    => $component_a,
				'CompB'    => $component_b,
			),
		);

		$plugin = $this->make_plugin( $container, array( 'FeatureA', 'FeatureB' ) );
		PluginKernel::run( $plugin );

		self::assertSame(
			array(
				'A:init',
				'B:init',
				'A:hooks',
				'B:hooks',
			),
			$log->entries,
		);
	}

	public function test_failing_conditional_skips_feature_entirely(): void {
		$log = new PluginKernelTestLog();

		$component_a = $this->make_component( 'A', $log );
		$feature_a   = $this->make_gated_feature( array( 'CompA' ) );

		$container = $this->make_container(
			array(
				PluginKernelTestFailingCond::class => new PluginKernelTestFailingCond(),
				'FeatureA'                         => $feature_a,
				'CompA'                            => $component_a,
			),
		);

		$plugin = $this->make_plugin( $container, array( 'FeatureA' ) );
		PluginKernel::run( $plugin );

		self::assertSame( array(), $log->entries );
	}

	public function test_disabled_component_skips_lifecycle_methods(): void {
		$log = new PluginKernelTestLog();

		$component = new class( $log ) implements InitializableInterface, HookableInterface, EnabledInterface {
			public function __construct(
				private PluginKernelTestLog $log,
			) {}

			public function is_enabled(): bool {
				return false;
			}

			public function initialize(): void {
				$this->log->entries[] = 'init';
			}

			public function register_hooks(): void {
				$this->log->entries[] = 'hooks';
			}
		};

		$feature = new class() implements FeatureInterface {
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
				return array( PluginKernelTestComp::class );
			}
		};

		$container = $this->make_container(
			array(
				'FeatureA'                   => $feature,
				PluginKernelTestComp::class  => $component,
			),
		);

		$plugin = $this->make_plugin( $container, array( 'FeatureA' ) );
		PluginKernel::run( $plugin );

		self::assertSame( array(), $log->entries );
	}

	public function test_component_resolves_peer_services_from_the_shared_container(): void {
		$log = new PluginKernelTestLog();

		$container = new PluginKernelTestContainer();

		// A service the plugin defines in its container (e.g. config/container.php).
		$container->set( 'ServiceX', new \stdClass() );

		$feature = new class() implements FeatureInterface {
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
				return array( PluginKernelTestCompB::class );
			}
		};

		$component = new class( $log, $container ) implements InitializableInterface {
			public function __construct(
				private PluginKernelTestLog $log,
				private ContainerInterface $c,
			) {}

			public function initialize(): void {
				$this->log->entries[] = $this->c->has( 'ServiceX' ) ? 'ServiceX-found' : 'ServiceX-missing';
			}
		};

		$container->set( 'FeatureA', $feature );
		$container->set( PluginKernelTestCompB::class, $component );

		$plugin = $this->make_plugin( $container, array( 'FeatureA' ) );
		PluginKernel::run( $plugin );

		self::assertSame( array( 'ServiceX-found' ), $log->entries );
	}

	public function test_boot_is_idempotent(): void {
		$log = new PluginKernelTestLog();

		$component = $this->make_component( 'A', $log );
		$feature   = $this->make_feature( array( 'CompA' ) );
		$container = $this->make_container(
			array(
				'FeatureA' => $feature,
				'CompA'    => $component,
			),
		);

		$plugin = $this->make_plugin( $container, array( 'FeatureA' ) );
		$kernel = PluginKernel::run( $plugin );
		$kernel->boot();

		$init_count  = \count( \array_filter( $log->entries, static fn( string $e ): bool => 'A:init' === $e ) );
		$hooks_count = \count( \array_filter( $log->entries, static fn( string $e ): bool => 'A:hooks' === $e ) );

		self::assertSame( 1, $init_count );
		self::assertSame( 1, $hooks_count );
	}

	/**
	 * @param array<string, object> $services
	 */
	private function make_container( array $services ): ContainerInterface {
		return new class( $services ) implements ContainerInterface {
			/**
			 * @param array<string, object> $services
			 */
			public function __construct(
				private array $services,
			) {}

			public function get( string $id ): mixed {
				return $this->services[ $id ];
			}

			public function has( string $id ): bool {
				return isset( $this->services[ $id ] );
			}
		};
	}

	/**
	 * @param list<string> $components
	 */
	private function make_feature(
		array $components,
	): FeatureInterface {
		return new class( $components ) implements FeatureInterface {
			/**
			 * @param list<string> $components
			 */
			public function __construct(
				private array $components,
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
				/** @var list<class-string> $components */
				$components = $this->components;
				return $components;
			}
		};
	}

	/**
	 * @param list<string> $components
	 */
	private function make_gated_feature(
		array $components,
	): FeatureInterface {
		return new class( $components ) implements FeatureInterface {
			/**
			 * @param list<string> $components
			 */
			public function __construct(
				private array $components,
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
				/** @var list<class-string> $components */
				$components = $this->components;
				return $components;
			}
		};
	}

	private function make_component( string $name, PluginKernelTestLog $log ): object {
		return new class( $name, $log ) implements InitializableInterface, HookableInterface {
			public function __construct(
				private string $name,
				private PluginKernelTestLog $log,
			) {}

			public function initialize(): void {
				$this->log->entries[] = $this->name . ':init';
			}

			public function register_hooks(): void {
				$this->log->entries[] = $this->name . ':hooks';
			}
		};
	}

	/**
	 * @param list<string> $features
	 */
	private function make_plugin( ContainerInterface $container, array $features ): PluginInterface {
		return new class( $container, $features ) implements PluginInterface {
			/**
			 * @param list<string> $features
			 */
			public function __construct(
				private ContainerInterface $container,
				private array $features,
			) {}

			public function get_plugin_file(): string {
				return '/tmp/fake.php';
			}

			public function get_plugin_header(): \DeepWebSolutions\Framework\Core\ValueObjects\PluginHeader {
				throw new \RuntimeException( 'not needed in this test' );
			}

			public function get_container(): ContainerInterface {
				return $this->container;
			}

			/**
			 * @return list<class-string<FeatureInterface>>
			 */
			public function get_feature_classes(): array {
				/** @var list<class-string<FeatureInterface>> $features */
				$features = $this->features;
				return $features;
			}

			public function get_installer(): \DeepWebSolutions\Framework\Core\Installer\InstallerInterface {
				throw new \RuntimeException( 'not needed in this test' );
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
 * Mutable PSR-11 container used to exercise cross-feature service registration.
 */
final class PluginKernelTestContainer implements ContainerInterface {
	/**
	 * @var array<string, object>
	 */
	private array $storage = array();

	public function get( string $id ): mixed {
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
 * Marker class used as a real class-string key for the inline test feature.
 */
final class PluginKernelTestComp {}

/**
 * Marker class used as a real class-string key for the cross-feature test.
 */
final class PluginKernelTestCompB {}

/**
 * Always-failing conditional gating the feature in the skip test.
 */
final class PluginKernelTestFailingCond implements ConditionalInterface {
	public function is_met(): bool {
		return false;
	}
}
