<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Tests\Unit\Feature;

use DeepWebSolutions\Framework\Core\Conditional\ConditionalInterface;
use DeepWebSolutions\Framework\Core\Feature\FeatureInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( FeatureInterface::class )]
final class FeatureInterfaceContractTest extends TestCase {
	public function test_anonymous_implementation_satisfies_contract(): void {
		$feature = new class() implements FeatureInterface {
			public static function get_conditional_classes(): array {
				return array( SomeConditional::class );
			}

			public function get_component_classes(): array {
				return array( SomeComponent::class );
			}
		};

		self::assertSame( array( SomeConditional::class ), $feature::get_conditional_classes() );
		self::assertSame( array( SomeComponent::class ), $feature->get_component_classes() );
	}
}

final class SomeConditional implements ConditionalInterface {
	public function is_met(): bool {
		return true;
	}
}
final class SomeComponent {}
