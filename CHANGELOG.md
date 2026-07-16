# Changelog

All notable changes to `swanflutter/notification-master` will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.2] — 2026-07-15

### Fixed
- Resolved CI dependency installation failures caused by Composer's security-advisory block rejecting all Laravel 11/12 framework versions; advisory blocking is now disabled and the affected Laravel advisories are explicitly ignored (they are transitive dev-only dependencies resolved by the host application).
- Fixed `composer validate --strict` failing due to a duplicate `policy` key in `composer.json`.

### Added
- `phpunit.xml` configuration and a `MessageTest` unit suite (12 tests) covering the fluent `Message` builder and payload serialisation.

### Changed
- CI workflow cleanup: bumped Composer cache key, and added `--no-scripts` to the autoload dump step.
- Replaced `firebase/php-jwt` with [`swanflutter/native-jwt`](https://packagist.org/packages/swanflutter/native-jwt) (`^1.2`), a drop-in, dependency-free JWT library with built-in `alg=none` and algorithm-confusion protection. The code now uses `SwanFlutter\NativeJwt\JWT` for RS256 OAuth2 assertion signing.

### Added
- `OAuthTokenTest` unit suite (14 tests) covering credential validation, RS256 JWT assertion signing/verification, in-memory token caching, refresh/expiry handling, and all `AuthenticationException` failure paths.

---

## [1.0.1] — 2026-07-13

### Fixed
- Upgraded the JWT signing dependency to `swanflutter/native-jwt` (`^1.2`), replacing `firebase/php-jwt` and removing exposure to the `PKSA-y2cr-5h3j-g3ys` weak-encryption advisory.
- Extended `orchestra/testbench` support to `^9.0|^10.0` covering both Laravel 11 and 12.
- Fixed 12 code style issues flagged by Laravel Pint across all source files.
- Removed `composer.lock` from version control (library best practice).
- Added CI matrix for PHP 8.2 / 8.3 / 8.4 against Laravel 11 and 12.
- Added automated GitHub Release workflow triggered on version tags.
- Packagist auto-update now triggers on both branch pushes and tag pushes.

---

## [1.0.0] — 2026-07-13

### Added
- Full FCM HTTP v1 API support with OAuth2 JWT authentication.
- Fluent immutable `Message` builder with support for token, topic, and condition targets.
- Platform-specific configuration for Android, APNs (iOS), and Web Push.
- `sendToMany()` multicast with per-token success/failure results.
- Automatic OAuth2 token caching and refresh.
- Configurable retry logic with exponential back-off on transient errors (429/5xx).
- Dry-run (`validate_only`) mode.
- **Polling mode** — `NotificationPoller` with pluggable `PollStoreInterface`.
  - `ArrayPollStore` — in-memory store (for tests).
  - `CachePollStore` — Laravel Cache adapter (Redis, Memcached, …).
  - `flushAndSend()` — atomically flush queued notifications via FCM.
- Laravel service provider with auto-discovery.
- Laravel `fcm` notification channel (`via('fcm')`).
- `PushNotification` Facade.
- Full config file publishable via `php artisan vendor:publish`.
- Typed exceptions hierarchy:
  - `PushNotificationException` (base)
  - `InvalidCredentialsException`
  - `InvalidMessageException`
  - `AuthenticationException`
  - `SendingFailedException` (includes raw FCM response body)
- PHP 8.2+ with strict types, readonly properties, named arguments, and enums throughout.
- MIT License.
