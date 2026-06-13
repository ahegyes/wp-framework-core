<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Tests\Integration;

use DeepWebSolutions\Framework\Core\Installer\InstallerInterface;
use DeepWebSolutions\Framework\Core\PluginInterface;
use DeepWebSolutions\Framework\Core\ValueObjects\PluginHeader;
use DeepWebSolutions\Framework\Shared\Version\Version;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[UsesClass( PluginHeader::class )]
final class PluginInterfaceContractTest extends TestCase {
	public function test_anonymous_implementation_satisfies_contract(): void {
		$file = WP_PLUGIN_DIR . '/plugin-header-fixture/dws-fixture.php';

		$container = new class() implements ContainerInterface {
			public function get( string $id ): mixed {
				return null;
			}

			public function has( string $id ): bool {
				return false;
			}
		};

		$installer = new class() implements InstallerInterface {
			public function install(): void {}

			public function update( Version $from_version ): void {}

			public function activate( bool $network_wide = false ): void {}

			public function deactivate( bool $network_deactivating = false ): void {}

			public function uninstall(): void {}
		};

		$plugin = new class( $file, $container, $installer ) implements PluginInterface {
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
			 * @return  list<class-string<\DeepWebSolutions\Framework\Core\Feature\FeatureInterface>>
			 */
			public function get_feature_classes(): array {
				return array();
			}

			public function get_installer(): InstallerInterface {
				return $this->installer;
			}
		};

		self::assertSame( $file, $plugin->get_plugin_file() );
		self::assertSame( 'DWS Test Fixture', $plugin->get_plugin_header()->name );
		self::assertSame( $container, $plugin->get_container() );
		self::assertSame( array(), $plugin->get_feature_classes() );
		self::assertSame( $installer, $plugin->get_installer() );
	}
}
