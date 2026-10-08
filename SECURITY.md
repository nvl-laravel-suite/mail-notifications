# Security Policy

Submit reports through [this package's private vulnerability reporting form](https://github.com/nvl-laravel-suite/mail-notifications/security/advisories/new).

Security fixes are provided for the published `5.x` release line. Composer declares PHP `^8.4` and Laravel `^12.0|^13.0`. The local Dagger release gate verifies PHP 8.4/Laravel 13 with MySQL 8.4 and PostgreSQL 17 persistence contracts; PHP 8.5, Laravel 12 and MariaDB require separate compatibility evidence. Upstream security lifecycle limits still apply.

Report vulnerabilities privately through the repository host's
security-advisory feature. Do not include credentials, signatures, raw mail
content, reset links, or provider payloads.

Keep webhook verification in provider adapters fail-closed in production,
bound payload size and timestamp tolerance, redact metadata before persistence,
authorize all operational views in the host, and minimize retention.

Sensitive-array storage is opt-in and does not encrypt queryable scalar
columns. Keep the configured transformer and every required previous key or
profile available while protected history is retained. Treat
`UnreadableSensitiveDataException` as an operational incident; never bypass
the versioned envelope or reinterpret ciphertext as plaintext. Preview bounded
anonymization before mutation and keep its scheduling separate from deletion.
