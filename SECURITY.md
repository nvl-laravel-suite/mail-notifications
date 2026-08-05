# Security Policy

Security fixes are provided for the current `1.x` release line on PHP 8.3+
and Laravel 12–13.

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
