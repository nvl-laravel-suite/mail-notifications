# Contributing to NVL Mail Notifications

Changes must preserve explicit tracking opt-in, ordinary Laravel Mail behavior,
provider-neutral core, host-owned presentation, monotonic lifecycle state,
durable webhook idempotency, and privacy-minimizing defaults.

Add isolated Pest coverage for every lifecycle or configuration change. Run
Pint, PHPStan at maximum strictness, Composer validation, dependency analysis,
package-family validation, and the package test suite.

Schema or concurrency changes must also run against a disposable PostgreSQL
database whose name starts with `nvl_mail_notifications_test_`. Status
invariant changes must preserve the MySQL 8.4 CI path and SQLite's
non-rebuilding trigger path. Sensitive-storage changes must cover plaintext
legacy reads, protected writes, key rotation, unreadable envelopes, and every
raw persistence path that bypasses Eloquent casts.

Do not add provider SDKs, application models, permissions, controllers, branded
mail views, or recipient rewriting to core. Provider-specific behavior belongs
behind narrow adapter contracts.
