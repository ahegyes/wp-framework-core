<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Tests\Unit\Enabled;

use DeepWebSolutions\Framework\Core\Enabled\EnabledInterface;
use PHPUnit\Framework\TestCase;

final class EnabledInterfaceContractTest extends TestCase {
	public function test_enabled_returns_true(): void {
		$c = new class() implements EnabledInterface {
			public function is_enabled(): bool {
				return true;
			}
		};
		self::assertTrue( $c->is_enabled() );
	}

	public function test_composed_user_and_framework_gate(): void {
		$c = new class() implements EnabledInterface {
			public bool $user_on        = true;
			public bool $framework_kill = false;

			public function is_enabled(): bool {
				return $this->user_on && ! $this->framework_kill;
			}
		};

		self::assertTrue( $c->is_enabled() );

		$c->framework_kill = true;
		self::assertFalse( $c->is_enabled() );

		$c->framework_kill = false;
		$c->user_on        = false;
		self::assertFalse( $c->is_enabled() );
	}
}
