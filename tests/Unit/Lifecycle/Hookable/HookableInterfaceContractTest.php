<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Tests\Unit\Lifecycle\Hookable;

use DeepWebSolutions\Framework\Core\Lifecycle\Hookable\HookableInterface;
use PHPUnit\Framework\TestCase;

final class HookableInterfaceContractTest extends TestCase {
	public function test_anonymous_implementation_satisfies_contract(): void {
		$calls = new \stdClass();

		$hookable = new class( $calls ) implements HookableInterface {
			public function __construct(
				private \stdClass $calls,
			) {}

			public function register_hooks(): void {
				$this->calls->registered = true;
			}
		};

		$hookable->register_hooks();

		self::assertTrue( $calls->registered );
	}
}
