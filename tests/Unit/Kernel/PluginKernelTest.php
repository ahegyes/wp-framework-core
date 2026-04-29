<?php declare( strict_types=1 );

namespace DeepWebSolutions\Framework\Core\Tests\Unit\Kernel;

use DeepWebSolutions\Framework\Core\Contracts\HookableInterface;
use DeepWebSolutions\Framework\Core\Contracts\InitializableInterface;
use DeepWebSolutions\Framework\Core\Kernel\PluginKernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[CoversClass( PluginKernel::class )]
final class PluginKernelTest extends TestCase {
	public function test_boot_calls_initialize_on_initializable_components(): void {
		$component = new class() implements InitializableInterface {
			public bool $initialized = false;

			public function initialize(): void {
				$this->initialized = true;
			}
		};

		$kernel = new PluginKernel( $this->stub_container_returning( $component ) );
		$kernel->register( $component::class );
		$kernel->boot();

		self::assertTrue( $component->initialized );
	}

	public function test_boot_calls_register_hooks_on_hookable_components(): void {
		$component = new class() implements HookableInterface {
			public bool $hooks_registered = false;

			public function register_hooks(): void {
				$this->hooks_registered = true;
			}
		};

		$kernel = new PluginKernel( $this->stub_container_returning( $component ) );
		$kernel->register( $component::class );
		$kernel->boot();

		self::assertTrue( $component->hooks_registered );
	}

	public function test_boot_runs_initialize_pass_before_register_hooks_pass(): void {
		$component = new class() implements InitializableInterface, HookableInterface {
			/** @var list<string> */
			public array $calls = [];

			public function initialize(): void {
				$this->calls[] = 'initialize';
			}

			public function register_hooks(): void {
				$this->calls[] = 'register_hooks';
			}
		};

		$kernel = new PluginKernel( $this->stub_container_returning( $component ) );
		$kernel->register( $component::class );
		$kernel->boot();

		self::assertSame( array( 'initialize', 'register_hooks' ), $component->calls );
	}

	public function test_boot_initializes_all_components_before_registering_any_hooks(): void {
		$log        = new \stdClass();
		$log->calls = array();

		$first = new class( $log, 'first' ) implements InitializableInterface, HookableInterface {
			public function __construct( private \stdClass $log, private string $name ) {}

			public function initialize(): void {
				$this->log->calls[] = "{$this->name}.initialize";
			}

			public function register_hooks(): void {
				$this->log->calls[] = "{$this->name}.register_hooks";
			}
		};

		$second = new class( $log, 'second' ) implements InitializableInterface, HookableInterface {
			public function __construct( private \stdClass $log, private string $name ) {}

			public function initialize(): void {
				$this->log->calls[] = "{$this->name}.initialize";
			}

			public function register_hooks(): void {
				$this->log->calls[] = "{$this->name}.register_hooks";
			}
		};

		$container = self::createStub( ContainerInterface::class );
		$container->method( 'get' )->willReturnMap(
			array(
				array( $first::class, $first ),
				array( $second::class, $second ),
			),
		);

		$kernel = new PluginKernel( $container );
		$kernel->register( $first::class );
		$kernel->register( $second::class );
		$kernel->boot();

		self::assertSame(
			array(
				'first.initialize',
				'second.initialize',
				'first.register_hooks',
				'second.register_hooks',
			),
			$log->calls,
		);
	}

	public function test_boot_silently_skips_components_implementing_neither_interface(): void {
		$component = new \stdClass();

		$kernel = new PluginKernel( $this->stub_container_returning( $component ) );
		$kernel->register( \stdClass::class );
		$kernel->boot();

		$this->expectNotToPerformAssertions();
	}

	public function test_boot_is_idempotent(): void {
		$component = new class() implements InitializableInterface {
			public int $initialize_count = 0;

			public function initialize(): void {
				++$this->initialize_count;
			}
		};

		$kernel = new PluginKernel( $this->stub_container_returning( $component ) );
		$kernel->register( $component::class );
		$kernel->boot();
		$kernel->boot();

		self::assertSame( 1, $component->initialize_count );
	}

	private function stub_container_returning( object $instance ): ContainerInterface {
		$container = self::createStub( ContainerInterface::class );
		$container->method( 'get' )->willReturn( $instance );
		return $container;
	}
}
