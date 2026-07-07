<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Tests\Unit\ValueObjects;

use DeepWebSolutions\Framework\Core\ValueObjects\Exceptions\InvalidPluginHeaderException;
use DeepWebSolutions\Framework\Core\ValueObjects\PluginHeader;
use DeepWebSolutions\Framework\Shared\ValueObject\Exceptions\InvalidValueObjectException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesFunction;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Support/wp-plugin-stub-functions.php';

#[CoversClass( PluginHeader::class )]
#[UsesClass( InvalidPluginHeaderException::class )]
#[UsesClass( InvalidValueObjectException::class )]
#[UsesFunction( 'DeepWebSolutions\Framework\Bootstrap\Plugin\get_plugin_metadata' )]
#[UsesFunction( 'DeepWebSolutions\Framework\Shared\Identifier\is_valid_identifier' )]
final class PluginHeaderTest extends TestCase {
	public function test_valid_text_domain_derived_slug_passes(): void {
		$header = new PluginHeader( WP_PLUGIN_DIR . '/my-plugin/my-plugin.php' );

		self::assertSame( 'my-plugin', $header->slug );
		self::assertSame( 'my-plugin', $header->text_domain );
	}

	public function test_uppercase_text_domain_derived_slug_throws(): void {
		$this->expectException( InvalidPluginHeaderException::class );
		$this->expectExceptionMessage( "derived slug 'MyPlugin' is not a valid identifier" );

		new PluginHeader( WP_PLUGIN_DIR . '/MyPlugin/my-plugin.php' );
	}

	public function test_dotted_text_domain_derived_slug_throws(): void {
		$this->expectException( InvalidPluginHeaderException::class );
		$this->expectExceptionMessage( "derived slug 'my.plugin' is not a valid identifier" );

		new PluginHeader( WP_PLUGIN_DIR . '/my.plugin/my-plugin.php' );
	}
}
