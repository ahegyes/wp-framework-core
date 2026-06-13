<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Tests\Unit\Conditional;

use DeepWebSolutions\Framework\Core\Conditional\ConditionalInterface;
use PHPUnit\Framework\TestCase;

final class ConditionalInterfaceContractTest extends TestCase {
	public function test_passing_conditional_returns_true(): void {
		$cond = new class() implements ConditionalInterface {
			public function is_met(): bool {
				return true;
			}
		};
		self::assertTrue( $cond->is_met() );
	}

	public function test_failing_conditional_returns_false(): void {
		$cond = new class() implements ConditionalInterface {
			public function is_met(): bool {
				return false;
			}
		};
		self::assertFalse( $cond->is_met() );
	}
}
