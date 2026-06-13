<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Tests\Integration\ValueObjects;

use DeepWebSolutions\Framework\Core\ValueObjects\PluginHeader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( PluginHeader::class )]
final class PluginHeaderTest extends TestCase {
	private string $fixture_path;

	protected function setUp(): void {
		parent::setUp();
		$this->fixture_path = WP_PLUGIN_DIR . '/plugin-header-fixture/dws-fixture.php';
	}

	public function test_reads_name_from_header(): void {
		$header = new PluginHeader( $this->fixture_path );
		self::assertSame( 'DWS Test Fixture', $header->name );
	}

	public function test_reads_version_from_header(): void {
		$header = new PluginHeader( $this->fixture_path );
		self::assertSame( '2.5.1', $header->version );
	}

	public function test_reads_text_domain_from_header(): void {
		$header = new PluginHeader( $this->fixture_path );
		self::assertSame( 'dws-fixture', $header->text_domain );
	}

	public function test_reads_requires_at_least_from_header(): void {
		$header = new PluginHeader( $this->fixture_path );
		self::assertSame( '7.0', $header->requires_at_least );
	}

	public function test_reads_requires_php_from_header(): void {
		$header = new PluginHeader( $this->fixture_path );
		self::assertSame( '8.5', $header->requires_php );
	}

	public function test_exposes_file_path(): void {
		$header = new PluginHeader( $this->fixture_path );
		self::assertSame( $this->fixture_path, $header->file_path );
	}

	public function test_slug_derives_from_text_domain_when_present(): void {
		$header = new PluginHeader( $this->fixture_path );
		self::assertSame( 'dws-fixture', $header->slug );
	}

	public function test_slug_falls_back_to_file_name_for_single_file_plugin_without_text_domain(): void {
		$header = new PluginHeader( WP_PLUGIN_DIR . '/dws-single-file-fixture.php' );
		self::assertSame( 'dws-single-file-fixture', $header->slug );
	}

	public function test_reads_network_as_boolean(): void {
		$header = new PluginHeader( $this->fixture_path );
		self::assertFalse( $header->network );
	}

	public function test_get_display_name_returns_a_string(): void {
		$header = new PluginHeader( $this->fixture_path );
		self::assertSame( 'DWS Test Fixture', $header->get_display_name() );
	}
}
