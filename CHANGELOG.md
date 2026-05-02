# Changelog

All notable changes to `ahegyes/wp-framework-core` are documented in this file. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Pending entries live in [`changelog/`](./changelog) — add via `composer changelog:add:core` from the monorepo root. Aggregate into a release with `composer changelog:write:core`.

## 2.0.0 - unreleased

### Added
- Initial release.
- `PluginKernel` for two-pass component lifecycle dispatch.
- `HookableInterface` and `InitializableInterface`.
