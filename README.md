# wp-framework-core

The plugin kernel. A plugin is composed of small components that load only where they apply, on WordPress's own APIs, with the infrastructure WordPress leaves every plugin to rebuild.

## Requirements

It builds on wp-framework-bootstrap and wp-framework-shared, and on the PSR container, log, clock and simple-cache interfaces. Its `composer.json` holds its PHP floor under `require.php` and its WordPress floor under `extra.requires-wp`, which a plugin's `Requires PHP` and `Requires at least` headers meet. The kernel builds components through a PSR-11 container the plugin chooses.

## Installation

The packages are not on Packagist, and Composer reads repositories only from the plugin's own `composer.json`, so a plugin lists one `vcs` repository for each package of the stack. It requires the package for development only, because it ships a prefixed copy instead:

```json
{
  "require-dev": {
    "ahegyes/wp-framework-core": "^2.0"
  },
  "repositories": [
    { "type": "vcs", "url": "https://github.com/ahegyes/wp-framework-bootstrap" },
    { "type": "vcs", "url": "https://github.com/ahegyes/wp-framework-shared" },
    { "type": "vcs", "url": "https://github.com/ahegyes/wp-framework-core" }
  ],
  "minimum-stability": "dev",
  "prefer-stable": true
}
```

`prefer-stable` installs the newest release that satisfies `^2.0`, and `minimum-stability` lets Composer take the development branch where no release does.

## Prefixing

WordPress loads every plugin into one PHP process, so each plugin ships the framework prefixed under its own namespace. Two plugins that ship the packages unprefixed fatal with "Cannot redeclare class …" once both load bootstrap's files, and their autoloaded classes resolve to one plugin's copy, chosen by the order the plugins load in, so the other plugin silently runs it. WordPress, WooCommerce (`WC_*`, `wc_*`, `WC()`, `Automattic\WooCommerce\*`) and Action Scheduler (`as_*`, `ActionScheduler*`) symbols stay global, and `Psr\*` is prefixed with the framework, so the plugin's container and logger implement the same prefixed interfaces.

[wordpress-configs](https://github.com/ahegyes/wordpress-configs) prefixes the packages and the plugin's container with php-scoper into `vendor-prefixed/` after every development install, and a later `composer install --no-dev` keeps that output, so a build runs both, in that order. It also replaces the framework's text domain with the plugin's `extra.text-domain`, failing the build if any of the framework's survives. PHP-DI accepts `laravel/serializable-closure` releases that the scoper refuses, so with PHP-DI as the container the plugin also requires a newer one. The plugin adds to its `composer.json`:

```json
{
  "require-dev": {
    "ahegyes/wordpress-configs": "dev-trunk",
    "humbug/php-scoper": "^0.18",
    "laravel/serializable-closure": "^2.1",
    "php-di/php-di": "^7.1.1"
  },
  "repositories": [
    { "type": "vcs", "url": "https://github.com/ahegyes/wordpress-configs" }
  ],
  "autoload": {
    "psr-4": { "Acme\\PersonaCore\\": "src/" },
    "files": [ "vendor-prefixed/scoper-autoload.php" ]
  },
  "scripts": {
    "post-autoload-dump": [ "DeepWebSolutions\\Config\\Composer\\ScopePhpDependencies::postAutoloadDump" ]
  },
  "extra": {
    "scoping-prefix": "Acme\\PersonaCore\\Scoped",
    "text-domain": "acme-persona-core"
  }
}
```

Its `scoper.inc.php` names the packages to prefix:

```php
return ( require __DIR__ . '/vendor/ahegyes/wordpress-configs/php/php-scoper/scoper-base.inc.php' )( __DIR__, 'ahegyes/wp-framework-*', 'php-di/php-di' );
```

## Usage

The plugin's main file requires `load.php` from bootstrap's prefixed copy, then its autoloader once the floor check passes:

```php
use Acme\PersonaCore\Scoped\DeepWebSolutions\Framework\Bootstrap\Requirements;

defined( 'ABSPATH' ) || exit;

define( 'ACME_PC_FILE', __FILE__ );

$acme_pc_bootstrap = __DIR__ . '/vendor-prefixed/ahegyes/wp-framework-bootstrap/load.php';
if ( ! is_readable( $acme_pc_bootstrap ) ) { // The floor gate lives in the bootstrap package, so nothing built may load without it.
	return;
}

require_once $acme_pc_bootstrap;
if ( ! Requirements::check_floors( __FILE__ ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/functions.php';
```

It boots on `plugins_loaded` at priority 15, after WooCommerce and Action Scheduler have loaded:

```php
add_action( 'plugins_loaded', 'acme_pc_boot', 15 );

// ...

function acme_pc_plugin(): Plugin {
	static $plugin = null;
	if ( ! $plugin instanceof Plugin ) {
		$plugin = new Plugin();
	}

	return $plugin;
}

// ...

function acme_pc_boot(): void {
	acme_pc_plugin()->boot();
}
```

One composition root builds the plugin. `PluginBoot` runs its composition and, when it throws, logs the failure and tells administrators instead of breaking the site, rethrowing only in local and development environments; the composition registers the scheduler and the migration runner, then boots the kernel, which builds each top-level component through the container and registers its hooks:

```php
use Acme\PersonaCore\Scoped\DeepWebSolutions\Framework\Core\AdminNotice\AdminNotices;
use Acme\PersonaCore\Scoped\DeepWebSolutions\Framework\Core\Logging\ErrorLogLogger;
use Acme\PersonaCore\Scoped\DeepWebSolutions\Framework\Core\Migration\MigrationRunner;
use Acme\PersonaCore\Scoped\DeepWebSolutions\Framework\Core\PluginBoot;
use Acme\PersonaCore\Scoped\DeepWebSolutions\Framework\Core\PluginKernel;
use Acme\PersonaCore\Scoped\DeepWebSolutions\Framework\Core\Scheduling\Scheduler;
use Acme\PersonaCore\Scoped\DI\ContainerBuilder;
use Acme\PersonaCore\Scoped\Psr\Container\ContainerInterface as PsrContainerInterface;
use Acme\PersonaCore\Scoped\Psr\Log\LoggerInterface as PsrLoggerInterface;

// ...

final class Plugin {
	// ...

	public const array COMPONENTS = array(
		AdminNotices::class,
		Greeting::class,
	);

	// ...

	public function __construct() {
		$this->logger    = new ErrorLogLogger( self::SLUG );
		$this->boot      = new PluginBoot( ACME_PC_FILE, self::PREFIX, $this->logger );
		$this->scheduler = new Scheduler( self::PREFIX );
	}

	// ...

	public function boot(): void {
		$this->boot->run( $this->compose( ... ) );
	}

	// ...

	protected function compose(): bool {
		$this->scheduler->register_hooks();
		new MigrationRunner( ACME_PC_FILE, self::PREFIX . '_revision', Migrations::STEPS, $this->logger, $this->scheduler )->register_hooks();

		$this->kernel = new PluginKernel( new ContainerBuilder()->addDefinitions( $this->get_definitions( $this->logger ) )->build() );
		$this->kernel->boot( self::COMPONENTS );

		return true;
	}

	// ...

	protected function get_definitions( PsrLoggerInterface $logger ): array {
		return array(
			AdminNotices::class => static fn ( PsrContainerInterface $c ): AdminNotices => new AdminNotices( self::PREFIX, $logger ),
		);
	}

	// ...
}
```

A component is a final class whose `register_hooks()` adds its WordPress hooks:

```php
final class Greeting implements ComponentInterface {
	// ...

	#[\Override]
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_shortcode' ) );
	}

	// ...
}
```

## Contract and versioning

The five packages release together under one version, which the [changelog](https://github.com/ahegyes/wordpress-framework/blob/trunk/CHANGELOG.md) records.

The 2.x contract is every public class, interface, enum, method and constant, the protected members of non-final classes, and the hook and priority each public callback method is registered on, unless tagged `@internal`. `@internal`, `private` and protected-on-final members are not contract, and an `@internal` symbol serves only its own package, because a caret edge installs a package beside an older or newer release of another. Hook names and their arguments, option and meta keys, REST routes and the path of bootstrap's `load.php` are contract too.

The `DeepWebSolutions\Framework\Core\Scheduling\Backends\` namespace is internal, so a class there is internal even without its tag.

Releases follow SemVer on this contract from the first tag: a break of it ships as the next major, and internals change in any release. Plugins on different majors run side by side, because each ships its own prefixed copy.

This package is developed in the [wordpress-framework](https://github.com/ahegyes/wordpress-framework) monorepo, which holds its issues and pull requests.
