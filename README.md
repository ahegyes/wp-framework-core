# wp-framework-core

PSR-11-based plugin kernel and lifecycle framework. Interface-driven components, conditional-gated features, a centralized installer, and a boot that initializes components before any of them registers hooks.

Part of the [DWS WordPress framework](https://github.com/ahegyes/wordpress-framework) — see the monorepo for architecture, contributing, and the rest of the package set.

## Installation

```bash
composer require ahegyes/wp-framework-core
```

## Boot skeleton

A consumer plugin's main file wires the framework in this order — constants, the PHP 5.6-safe
requirements gate, the autoloader, activation wiring at include scope, then the deferred boot:

```php
defined( 'ABSPATH' ) || exit;

define( 'MY_PLUGIN_FILE', __FILE__ );

// Requirements gate — runs from the bootstrap package BEFORE the autoloader,
// so an unsupported runtime gets an admin notice instead of a fatal.
require_once __DIR__ . '/vendor/ahegyes/wp-framework-bootstrap/functions.php';

$requirements = \DeepWebSolutions\Framework\Bootstrap\Requirements\check_requirements( plugin_basename( __FILE__ ) );
if ( $requirements instanceof \WP_Error ) {
	\DeepWebSolutions\Framework\Bootstrap\Notice\output_requirements_error( plugin_basename( __FILE__ ), $requirements );
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

// Activation/deactivation must be wired during the include — register_activation_hook()
// fires before any plugins_loaded-deferred boot.
PluginKernel::register_lifecycle_hooks( Plugin::get_instance() );

add_action( 'plugins_loaded', 'my_plugin_boot', 15 );

function my_plugin_boot(): void {
	PluginKernel::run( Plugin::get_instance() );
}
```

`Plugin` is the consumer's `final class Plugin implements PluginInterface`, exposing the plugin
file, header, PSR-11 container, Feature classes, and installer. The
[wordpress-plugin-template](https://github.com/ahegyes/wordpress-plugin-template) is the worked,
tested reference for this shape, including the php-scoper build whose scoped paths replace the
plain `vendor/` requires above.

## Lineage

Successor to:
- [`deep-web-solutions/wp-framework-core`](https://github.com/deep-web-solutions/wordpress-framework-core) (archived)
- [`deep-web-solutions/wp-framework-foundations`](https://github.com/deep-web-solutions/wordpress-framework-foundations) (archived, folded in)
