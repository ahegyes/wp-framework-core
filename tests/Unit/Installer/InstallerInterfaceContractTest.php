<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Tests\Unit\Installer;

use DeepWebSolutions\Framework\Core\Installer\InstallerInterface;
use DeepWebSolutions\Framework\Shared\Version\Version;
use PHPUnit\Framework\TestCase;

final class InstallerInterfaceContractTest extends TestCase {
	public function test_anonymous_installer_satisfies_contract(): void {
		$log        = new \stdClass();
		$log->calls = array();

		$installer = new class( $log ) implements InstallerInterface {
			public function __construct( private \stdClass $log ) {}

			public function install(): void {
				$this->log->calls[] = 'install';
			}

			public function update( Version $from_version ): void {
				$this->log->calls[] = 'update:' . $from_version->value;
			}

			public function activate( bool $network_wide = false ): void {
				$this->log->calls[] = 'activate:' . ( $network_wide ? 'network' : 'site' );
			}

			public function deactivate( bool $network_deactivating = false ): void {
				$this->log->calls[] = 'deactivate:' . ( $network_deactivating ? 'network' : 'site' );
			}

			public function uninstall(): void {
				$this->log->calls[] = 'uninstall';
			}
		};

		$installer->install();
		$installer->update( Version::from_string( '1.9.0' ) );
		$installer->activate( true );
		$installer->deactivate();
		$installer->uninstall();

		self::assertSame(
			array( 'install', 'update:1.9.0', 'activate:network', 'deactivate:site', 'uninstall' ),
			$log->calls,
		);
	}
}
