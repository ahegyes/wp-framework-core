<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Tests\Integration;

use DeepWebSolutions\Framework\Core\Conditional\ConditionalInterface;
use DeepWebSolutions\Framework\Core\Feature\FeatureInterface;
use DeepWebSolutions\Framework\Core\Installer\InstallerInterface;
use DeepWebSolutions\Framework\Core\Lifecycle\Hookable\HookableInterface;
use DeepWebSolutions\Framework\Core\Lifecycle\Initializable\InitializableInterface;
use DeepWebSolutions\Framework\Core\PluginInterface;
use DeepWebSolutions\Framework\Core\PluginKernel;
use DeepWebSolutions\Framework\Core\ValueObjects\BootStatus;
use DeepWebSolutions\Framework\Core\ValueObjects\PluginHeader;
use DeepWebSolutions\Framework\Shared\Version\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[CoversClass( PluginKernel::class )]
final class PluginKernelTest extends TestCase {
	private const VERSION_OPTION = 'dws_test_kernel_version';

	protected function tear_down_option(): void {
		\delete_option( self::VERSION_OPTION );
	}

	protected function setUp(): void {
		$this->tear_down_option();
	}

	protected function tearDown(): void {
		$this->tear_down_option();
	}

	public function test_register_lifecycle_hooks_invokes_installer_with_network_flag(): void {
		$file     = WP_PLUGIN_DIR . '/plugin-header-fixture/dws-fixture.php';
		$basename = \plugin_basename( $file );
		\remove_all_actions( 'activate_' . $basename );
		\remove_all_actions( 'deactivate_' . $basename );

		$installer = $this->make_option_installer( '2.0.0' );
		$plugin    = $this->make_plugin( $file, $installer );

		PluginKernel::register_lifecycle_hooks( $plugin );

		self::assertNotFalse( \has_action( 'activate_' . $basename ) );
		self::assertNotFalse( \has_action( 'deactivate_' . $basename ) );

		\do_action( 'activate_' . $basename, true );
		\do_action( 'deactivate_' . $basename, true );

		self::assertTrue( $installer->activated_network_wide );
		self::assertTrue( $installer->deactivated_network );
	}

	public function test_fresh_boot_installs_and_persists_current_version(): void {
		$file      = WP_PLUGIN_DIR . '/plugin-header-fixture/dws-fixture.php';
		$installer = $this->make_option_installer( '2.0.0' );
		$plugin    = $this->make_plugin( $file, $installer );

		PluginKernel::run( $plugin );

		self::assertTrue( $installer->installed );
		self::assertSame( '2.0.0', \get_option( self::VERSION_OPTION ) );
	}

	public function test_older_stored_version_boot_updates_and_persists_current_version(): void {
		\update_option( self::VERSION_OPTION, '1.4.0' );

		$file      = WP_PLUGIN_DIR . '/plugin-header-fixture/dws-fixture.php';
		$installer = $this->make_option_installer( '2.0.0' );
		$plugin    = $this->make_plugin( $file, $installer );

		PluginKernel::run( $plugin );

		self::assertFalse( $installer->installed );
		self::assertSame( '1.4.0', $installer->updated_from );
		self::assertSame( '2.0.0', \get_option( self::VERSION_OPTION ) );
	}

	public function test_a_failed_boot_inside_a_dispatching_action_rolls_back_every_hook_table_change(): void {
		\update_option( self::VERSION_OPTION, '2.0.0' );

		$preexisting_cb   = static fn ( mixed $value ): mixed => $value;
		$thirdparty_cb    = static fn ( mixed $value ): mixed => $value;
		$component_cb     = static fn ( mixed $value ): mixed => $value;
		$late_outer_fired = false;
		$outer_completed  = false;
		$late_outer_cb    = static function () use ( &$late_outer_fired ): void {
			$late_outer_fired = true;
		};

		// Simulated third-party code reacting synchronously to an action a component fires
		// mid-window: it adds a hook of its own and removes a pre-existing callback. Both
		// mutations must be unwound by the failed boot.
		$thirdparty_listener = static function () use ( $thirdparty_cb, $preexisting_cb ): void {
			\add_filter( 'dws_test_kernel_thirdparty', $thirdparty_cb, 10 );
			\remove_filter( 'dws_test_kernel_preexisting', $preexisting_cb, 10 );
		};

		$container = new PluginKernelTestFactoryContainer(
			array(
				PluginKernelRollbackFeature::class => static fn (): object => new PluginKernelRollbackFeature(
					array( PluginKernelHookMutatingComponent::class, PluginKernelHookThrowingComponent::class ),
				),
				PluginKernelHookMutatingComponent::class => static fn (): object => new PluginKernelHookMutatingComponent(
					static function () use ( $component_cb, $late_outer_cb ): void {
						\add_action( 'dws_test_kernel_outer', $late_outer_cb, 15 );
						\add_filter( 'dws_test_kernel_component', $component_cb, 10 );
						\do_action( 'dws_test_kernel_activated' );
					},
				),
				PluginKernelHookThrowingComponent::class => static fn (): object => new PluginKernelHookThrowingComponent(),
			),
		);

		$plugin = $this->make_plugin(
			WP_PLUGIN_DIR . '/plugin-header-fixture/dws-fixture.php',
			$this->make_option_installer( '2.0.0' ),
			$container,
			array( PluginKernelRollbackFeature::class ),
		);

		$kernel        = null;
		$boot_listener = static function () use ( $plugin, &$kernel ): void {
			$kernel = PluginKernel::run( $plugin );
		};

		$after_listener = static function () use ( &$outer_completed ): void {
			$outer_completed = true;
		};

		try {
			\add_filter( 'dws_test_kernel_preexisting', $preexisting_cb, 10 );
			\add_action( 'dws_test_kernel_activated', $thirdparty_listener, 10 );
			\add_action( 'dws_test_kernel_outer', $boot_listener, 10 );
			\add_action( 'dws_test_kernel_outer', $after_listener, 20 );

			$before = $this->normalized_hook_table();

			\do_action( 'dws_test_kernel_outer' );

			// The outer dispatch survives a rollback performed while its tag is mid-dispatch,
			// and the hook the component slipped onto the dispatching tag never fires.
			self::assertTrue( $outer_completed );
			self::assertFalse( $late_outer_fired );

			self::assertSame( $before, $this->normalized_hook_table() );
			self::assertFalse( \has_filter( 'dws_test_kernel_component' ) );
			self::assertFalse( \has_filter( 'dws_test_kernel_thirdparty' ) );
			self::assertSame( 10, \has_filter( 'dws_test_kernel_preexisting', $preexisting_cb ) );

			self::assertInstanceOf( PluginKernel::class, $kernel );
			self::assertSame( BootStatus::Failed, $kernel->boot_report->status );
			self::assertSame( array(), $kernel->boot_report->hooked_components );
		} finally {
			foreach ( array( 'dws_test_kernel_outer', 'dws_test_kernel_preexisting', 'dws_test_kernel_activated', 'dws_test_kernel_component', 'dws_test_kernel_thirdparty' ) as $tag ) {
				\remove_all_filters( $tag );
			}
		}
	}

	public function test_an_initialize_failure_rolls_back_constructor_and_initialize_hook_registrations(): void {
		\update_option( self::VERSION_OPTION, '2.0.0' );

		$ctor_cb = static fn ( mixed $value ): mixed => $value;
		$init_cb = static fn ( mixed $value ): mixed => $value;

		$container = new PluginKernelTestFactoryContainer(
			array(
				PluginKernelRollbackFeature::class => static fn (): object => new PluginKernelRollbackFeature(
					array( PluginKernelCtorAndInitHookComponent::class, PluginKernelInitThrowingComponent::class ),
				),
				PluginKernelCtorAndInitHookComponent::class => static fn (): object => new PluginKernelCtorAndInitHookComponent(
					static function () use ( $ctor_cb ): void {
						\add_filter( 'dws_test_kernel_ctor', $ctor_cb, 10 );
					},
					static function () use ( $init_cb ): void {
						\add_filter( 'dws_test_kernel_init', $init_cb, 10 );
					},
				),
				PluginKernelInitThrowingComponent::class => static fn (): object => new PluginKernelInitThrowingComponent(),
			),
		);

		$plugin = $this->make_plugin(
			WP_PLUGIN_DIR . '/plugin-header-fixture/dws-fixture.php',
			$this->make_option_installer( '2.0.0' ),
			$container,
			array( PluginKernelRollbackFeature::class ),
		);

		try {
			$before = $this->normalized_hook_table();

			$kernel = PluginKernel::run( $plugin );

			self::assertSame( $before, $this->normalized_hook_table() );
			self::assertFalse( \has_filter( 'dws_test_kernel_ctor' ) );
			self::assertFalse( \has_filter( 'dws_test_kernel_init' ) );
			self::assertSame( BootStatus::Failed, $kernel->boot_report->status );
			self::assertSame( array( PluginKernelCtorAndInitHookComponent::class ), $kernel->boot_report->initialized_components );
			self::assertSame( array(), $kernel->boot_report->hooked_components );
		} finally {
			\remove_all_filters( 'dws_test_kernel_ctor' );
			\remove_all_filters( 'dws_test_kernel_init' );
		}
	}

	private function make_option_installer( string $current ): PluginKernelOptionInstaller {
		return new PluginKernelOptionInstaller( $current, self::VERSION_OPTION );
	}

	/**
	 * The live hook table reduced to tag => callbacks, tag-order-insensitive, so a rolled-back
	 * table can be compared byte-for-byte against the pre-window state.
	 *
	 * @return array<string, array<int, array<string, array{function: callable, accepted_args: int}>>>
	 */
	private function normalized_hook_table(): array {
		$table = array();
		foreach ( $GLOBALS['wp_filter'] as $tag => $hook ) {
			$table[ $tag ] = $hook->callbacks;
		}
		\ksort( $table );

		return $table;
	}

	/**
	 * @param list<class-string<FeatureInterface>> $features
	 */
	private function make_plugin( string $file, ?InstallerInterface $installer = null, ?ContainerInterface $container = null, array $features = array() ): PluginInterface {
		$installer ??= $this->make_option_installer( '2.0.0' );

		$container ??= new class() implements ContainerInterface {
			public function get( string $id ): mixed {
				throw new \OutOfBoundsException( $id );
			}

			public function has( string $id ): bool {
				return false;
			}
		};

		return new class( $file, $container, $installer, $features ) implements PluginInterface {
			/**
			 * @param list<class-string<FeatureInterface>> $features
			 */
			public function __construct(
				private string $file,
				private ContainerInterface $container,
				private InstallerInterface $installer,
				private array $features,
			) {}

			public function get_plugin_file(): string {
				return $this->file;
			}

			public function get_plugin_header(): PluginHeader {
				return new PluginHeader( $this->file );
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
 * Installer that records install/update and persists the version through the WordPress
 * options table, exercising the kernel's version check against real get_option/update_option.
 */
final class PluginKernelOptionInstaller implements InstallerInterface {
	public bool $installed = false;

	public ?string $updated_from = null;

	public ?bool $activated_network_wide = null;

	public ?bool $deactivated_network = null;

	public function __construct(
		private string $current,
		private string $option_key,
	) {}

	public function install(): void {
		$this->installed = true;
	}

	public function update( Version $from_version ): void {
		$this->updated_from = $from_version->value;
	}

	public function activate( bool $network_wide = false ): void {
		$this->activated_network_wide = $network_wide;
	}

	public function deactivate( bool $network_deactivating = false ): void {
		$this->deactivated_network = $network_deactivating;
	}

	public function uninstall(): void {}

	public function get_current_version(): Version {
		return Version::from_string( $this->current );
	}

	public function get_stored_version(): ?Version {
		$stored = \get_option( $this->option_key );

		return \is_string( $stored ) ? Version::from_string( $stored ) : null;
	}

	public function set_stored_version( Version $version ): void {
		\update_option( $this->option_key, $version->value );
	}
}

/**
 * Factory-backed PSR-11 container, so component construction happens during boot — inside
 * the kernel's hook-table transaction window — rather than at test-setup time.
 */
final class PluginKernelTestFactoryContainer implements ContainerInterface {
	/**
	 * @param array<string, \Closure(): object> $factories
	 */
	public function __construct(
		private array $factories,
	) {}

	public function get( string $id ): mixed {
		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \OutOfBoundsException( 'Unbound container id: ' . $id );
		}

		return ( $this->factories[ $id ] )();
	}

	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}
}

/**
 * Feature with no conditionals and a configurable component list.
 */
final class PluginKernelRollbackFeature implements FeatureInterface {
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
 * Hookable component that runs a configurable hook-table mutation from register_hooks().
 */
final class PluginKernelHookMutatingComponent implements HookableInterface {
	public function __construct(
		private \Closure $mutator,
	) {}

	public function register_hooks(): void {
		( $this->mutator )();
	}
}

final class PluginKernelHookThrowingComponent implements HookableInterface {
	public function register_hooks(): void {
		throw new \RuntimeException( 'register_hooks failed' );
	}
}

/**
 * Component that mutates the hook table from both its constructor and initialize(), pinning
 * that the transaction window opens before any component is constructed.
 */
final class PluginKernelCtorAndInitHookComponent implements InitializableInterface {
	private \Closure $init_mutator;

	public function __construct( \Closure $ctor_mutator, \Closure $init_mutator ) {
		$this->init_mutator = $init_mutator;
		$ctor_mutator();
	}

	public function initialize(): void {
		( $this->init_mutator )();
	}
}

final class PluginKernelInitThrowingComponent implements InitializableInterface {
	public function initialize(): void {
		throw new \RuntimeException( 'initialize failed' );
	}
}
