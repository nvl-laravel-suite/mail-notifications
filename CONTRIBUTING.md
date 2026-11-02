# Contributing

This repository is a read-only release mirror. Pull requests to the mirror are not accepted. Open a public issue with a reproducible example or a proposed patch. Maintainers apply reviewed changes in the private source repository and publish immutable releases from there. Report vulnerabilities privately through [SECURITY.md](SECURITY.md), without private addresses, bodies, links or credentials.

## Keep the package boundary

Follow the suite’s NVL Light backend conventions and the package [architecture](docs/architecture.md). Native services own their validation, authority seams, actual-source transactions, idempotency and recovery. Hosts own HTTP, UI and business permissions. Keep native Media byte operations on its supported APIs and preserve the single initial alpha schema. Add no template/campaign/broker framework to this delivery package.

Retain supported signatures, persisted IDs/morphs, queued payload restoration, client isolation and frozen message evidence. Document breaking changes explicitly. The older tracking/factory scheduler remains a separate supported capability; do not silently turn it on in a prepared-delivery host.

## Checks in the suite source checkout

```sh
php tools/run-package-tests.php --concurrency=1 mail-notifications
php tools/analyse-packages.php mail-notifications
php vendor/bin/pest --test-directory=tests tests/Contract/MailConsumerDocumentationTest.php
```

Use the PHP runtime required by the installed lockfile and run the supported runtime/database matrix for a release. Format changed PHP using the suite Pint configuration. Test externally visible behavior and failure cases; do not delete existing tests to make a change pass. Provider/SMTP/public webhook evidence must be distinguished from fakes, local capture and static inspection.

## Maintain documentation and the agent skill

Edit canonical prepared-delivery guides in `docs/architecture.md`, `delivery.md`, `database.md` and `operations.md`. The generator copies them into the self-contained skill and rewrites the API-reference link for publication. Edit task routing in `resources/boost/skills/nvl-mail-notifications/SKILL.md`, optional tracking recipes in `references/laravel-tracking.md`, and agent UI metadata in `agents/openai.yaml`. Keep the entry point concise; put detailed examples in references.

Public PHPDoc/contract changes require reviewed `resources/consumer-api.json` and suite public-contract metadata before regenerating declarations. Do not hand-edit generated `references/api.md`, `installation.md` or portable guide copies. Regenerate/check this package only with:

```sh
php tools/package-skill-docs.php --write --package=mail-notifications
php tools/package-skill-docs.php --package=mail-notifications
```

The suite aggregate must mirror the complete package skill. `composer skills:sync` generates and synchronizes the whole family; use it when all affected family contracts are ready for that shared refresh. Consumer publication is a separate `vendor:publish`/Boost operation documented in [installation](resources/boost/skills/nvl-mail-notifications/references/installation.md); do not publish into a developer’s personal skill directories as a maintainer check.

Documentation regressions check scoped generation, portable link closure, agent metadata and real PHP imports. `DocumentedMailableExampleTest` executes the canonical English/Bulgarian Mailable with private bytes, references, locale restoration and stable idempotency. Keep that recipe executable and independently evaluate meaningful skill changes using only the published folder. Verify an archive-installed Laravel consumer can publish the whole skill tree without the monorepo.
