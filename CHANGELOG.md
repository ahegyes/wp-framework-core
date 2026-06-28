# Changelog

All notable changes to `ahegyes/wp-framework-core` are documented in this file. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Pending entries live in [`changelog/`](./changelog) — add via `composer changelog:add:core` from the monorepo root. Aggregate into a release with `composer changelog:write:core`.

## 2.0.0 - unreleased

### Added

- **Complete rewrite of v1.** Lean library architecture: composition over inheritance, PSR-11 container wiring. PHP 8.5+, WordPress 7.0+. See README for architecture details.
- **Plugin kernel** — orchestrates the component graph on boot: runs the installer's version check, gates each feature on its conditionals before construction, validates the declared graph, then initializes every runnable component before registering any hooks.
- **Feature and component model** — conditional-gated feature subtrees, kernel-dispatched composite components, and per-component enablement gating.
- **Centralized installer** — install, update, activate, deactivate, and uninstall plus version I/O, with silent idempotent migrations driven from the kernel boot.
- **Lifecycle and rendering markers** — typed contracts for hookable, initializable, renderable, and outputtable components.
- **Plugin header value object** — typed wrapper around WP plugin file headers.
