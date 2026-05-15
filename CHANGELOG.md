# Changelog

All notable changes to `ahegyes/wp-framework-core` are documented in this file. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Pending entries live in [`changelog/`](./changelog) — add via `composer changelog:add:core` from the monorepo root. Aggregate into a release with `composer changelog:write:core`.

## 2.0.0 - unreleased

### Added

- **Complete rewrite of v1.** Lean library architecture: composition over inheritance, PSR-11 container wiring. PHP 8.5+, WordPress 7.0+. See README for architecture details.
- **Plugin kernel** — orchestrates component lifecycle with two-pass dispatch and state-based gating.
- **Lifecycle and state interface set** — typed contracts for hookable, initializable, activatable, uninstallable, and renderable components plus active/disabled state gating.
- **Plugin header value object** — typed wrapper around WP plugin file headers with init-aware translation.
