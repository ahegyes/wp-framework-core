<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Tests\Integration;

use DeepWebSolutions\Framework\Core\Installer\InstallerInterface;
use DeepWebSolutions\Framework\Core\PluginInterface;
use DeepWebSolutions\Framework\Core\PluginKernel;
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

	private function make_option_installer( string $current ): PluginKernelOptionInstaller {
		return new PluginKernelOptionInstaller( $current, self::VERSION_OPTION );
	}

	private function make_plugin( string $file, ?InstallerInterface $installer = null ): PluginInterface {
		$installer ??= $this->make_option_installer( '2.0.0' );

		$container = new class() implements ContainerInterface {
			public function get( string $id ): mixed {
				throw new \OutOfBoundsException( $id );
			}

			public function has( string $id ): bool {
				return false;
			}
		};

		return new class( $file, $container, $installer ) implements PluginInterface {
			public function __construct(
				private string $file,
				private ContainerInterface $container,
				private InstallerInterface $installer,
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
			 * @return list<class-string<\DeepWebSolutions\Framework\Core\Feature\FeatureInterface>>
			 */
			public function get_feature_classes(): array {
				return array();
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
