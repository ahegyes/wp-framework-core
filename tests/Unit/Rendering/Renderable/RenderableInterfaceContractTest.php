<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Tests\Unit\Rendering\Renderable;

use DeepWebSolutions\Framework\Core\Rendering\Renderable\RenderableInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( RenderableInterface::class )]
final class RenderableInterfaceContractTest extends TestCase {
	public function test_render_returns_markup_string(): void {
		$r = new class implements RenderableInterface {
			public function render(): string {
				return '<p>hello</p>';
			}
		};

		self::assertSame( '<p>hello</p>', $r->render() );
	}
}
