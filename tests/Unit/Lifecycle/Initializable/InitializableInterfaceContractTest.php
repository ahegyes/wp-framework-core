<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Tests\Unit\Lifecycle\Initializable;

use DeepWebSolutions\Framework\Core\Lifecycle\Initializable\Exceptions\InitializationException;
use DeepWebSolutions\Framework\Core\Lifecycle\Initializable\InitializableInterface;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[UsesClass( InitializationException::class )]
final class InitializableInterfaceContractTest extends TestCase {
	public function test_anonymous_implementation_satisfies_contract(): void {
		$calls = new \stdClass();

		$initializable = new class( $calls ) implements InitializableInterface {
			public function __construct(
				private \stdClass $calls,
			) {}

			public function initialize(): void {
				$this->calls->initialized = true;
			}
		};

		$initializable->initialize();

		self::assertTrue( $calls->initialized );
	}

	public function test_failures_are_signaled_by_throwing(): void {
		$initializable = new class() implements InitializableInterface {
			public function initialize(): void {
				throw new InitializationException( 'Boom.' );
			}
		};

		$this->expectException( InitializationException::class );
		$initializable->initialize();
	}
}
