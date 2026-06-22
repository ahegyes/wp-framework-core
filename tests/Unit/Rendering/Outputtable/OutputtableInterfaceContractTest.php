<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Tests\Unit\Rendering\Outputtable;

use DeepWebSolutions\Framework\Core\Rendering\Outputtable\OutputtableInterface;
use PHPUnit\Framework\TestCase;

final class OutputtableInterfaceContractTest extends TestCase {
	public function test_output_echoes_markup(): void {
		$o = new class implements OutputtableInterface {
			public function output(): void {
				echo '<p>hello</p>';
			}
		};

		\ob_start();
		$o->output();
		$out = \ob_get_clean();

		self::assertSame( '<p>hello</p>', $out );
	}
}
