# Module Standards

Every publishable module should be easy to audit, install, test, package, and remove.

## Required module layout

```text
<category>/<slug>/
├── README.md
├── CHANGELOG.md
├── VERSION
├── module.json
├── modules/           # files copied into the WHMCS root
└── tests/             # local tests where applicable
```

Additional documentation and tooling can live inside the module directory when necessary.

## Metadata

`module.json` should contain at least:

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

## Compatibility

Do not claim compatibility based only on PHP syntax. A module README must distinguish between:

- versions exercised by automated/static tests;
- versions tested in an actual WHMCS installation;
- versions tested against the upstream provider sandbox/production API.

## Payment modules

Payment modules must additionally:

- keep raw PAN/CVV outside WHMCS whenever provider tokenisation/hosted fields exist;
- use exact integer minor-unit arithmetic where required by the API;
- authenticate and replay-protect webhooks when supported;
- prevent duplicate invoice credits;
- use unique idempotency keys correctly;
- treat asynchronous provider states as pending rather than inventing a decline;
- document recurring billing and token-deletion behaviour;
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
- make cron/background behaviour explicit;
- provide uninstall cleanup instructions where data removal is safe.

## Logging

Logs may contain provider request IDs and sanitized response metadata. They must not intentionally contain API keys, passwords, raw card data, CVV, full access tokens, or unredacted customer secrets.

## Releases

Generated ZIP files belong in GitHub Releases, not in the source tree. Build them with `scripts/package-module.sh` or the relevant Make target.
