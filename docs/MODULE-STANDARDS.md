# Module Standards

Every publishable module should be easy to audit, install, test, package and remove.

## Canonical module layout

All new modules must use:

```text
<category>/<slug>/
├── README.md
├── CHANGELOG.md
├── VERSION
├── module.json
├── modules/           # mirrors the WHMCS root
└── tests/             # local tests where applicable
```

For example, an addon named `example` would normally contain runtime code below:

```text
addons/example/modules/addons/example/
```

Two historical addons in this repository (`supermailverify` and `supportpin`) predate this convention and remain flat for source-path compatibility. `scripts/package-module.sh` understands that legacy layout, but it must not be copied for new modules.

## Metadata

`module.json` must contain at least:

- `name`
- `slug`
- `type`
- `version`
- `stability`
- `license`
- `php`
- `whmcs`
- `maintainer`
- `homepage`

`VERSION`, the runtime module version and `module.json.version` should agree for a release.

## README requirements

A module README should state:

- purpose and major capabilities;
- exact WHMCS install path;
- PHP/WHMCS/provider requirements;
- activation/configuration steps;
- custom database tables and deactivation/uninstall behavior;
- hooks, cron and background behavior;
- external services contacted by the module;
- security-sensitive behavior and stored secrets;
- what has been statically tested versus validated in a real WHMCS/provider environment.

## Compatibility

Do not claim compatibility based only on PHP syntax. Documentation must distinguish between:

- versions exercised by automated/static tests;
- versions tested in an actual WHMCS installation;
- versions tested against an upstream provider sandbox or production API.

## Payment modules

Payment modules must additionally:

- keep raw PAN/CVV outside WHMCS whenever provider tokenisation/hosted fields exist;
- use exact integer minor-unit arithmetic where required by the API;
- authenticate and replay-protect webhooks when supported;
- prevent duplicate invoice credits;
- use unique idempotency keys correctly;
- treat asynchronous provider states as pending rather than inventing a decline;
- document recurring billing and token-deletion behavior;
- document refund semantics and partial-refund handling.

## Server modules

Server/provisioning modules must additionally:

- make create/suspend/unsuspend/terminate semantics explicit;
- avoid duplicate resource creation on retries;
- validate remote identifiers before destructive actions;
- separate API errors from customer-facing messages;
- never expose infrastructure credentials in logs.

## Addon modules

Addon modules must additionally:

- document schema changes;
- use WHMCS hooks/APIs instead of patching core files;
- protect state-changing requests with the appropriate WHMCS CSRF/session mechanisms;
- make cron/background behavior explicit;
- make repeated include/hook loading safe where WHMCS can load entrypoints more than once;
- provide uninstall cleanup instructions where data removal is safe.

## Logging

Logs may contain provider request IDs and sanitized response metadata. They must not intentionally contain API keys, passwords, raw card data, CVV, full access tokens or unredacted customer secrets.

## Releases

Generated ZIP files belong in GitHub Releases, not in the source tree.

Build one module with its Make target or build all modules with:

```bash
make package-all
```

The packager writes release ZIPs and SHA-256 files to `dist/`.
