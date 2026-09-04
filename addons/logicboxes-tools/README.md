# ServerSpan LogicBoxes Tools for WHMCS

Open-source administration and automation tools for **ResellerClub, LogicBoxes, NetEarthOne, Resell.biz and other LogicBoxes-compatible reseller accounts** from WHMCS.

This is an independent clean-room implementation based on the public LogicBoxes HTTP API and current WHMCS developer APIs. It contains no encoded PHP, vendor licensing callbacks, telemetry, or copied proprietary source.

> **Beta:** the static test suite passes, but the module must be exercised against a LogicBoxes test account and a representative WHMCS staging database before enabling automated writes in production.

## Design goals

This project deliberately treats bulk pricing, customer synchronization and domain moves as **financial/operational migrations**, not button-click conveniences.

- Unlimited reseller accounts instead of a fixed account count.
- API keys encrypted at rest with WHMCS encryption facilities.
- Dry-run first for pricing mutations.
- Durable jobs with per-item before/proposed/applied snapshots.
- Audit records for operator and cron changes.
- Idempotent imports and external-ID mappings.
- No WHMCS customer-password synchronization.
- Conservative cron batching and overlap locks.
- Provider API responses are sanitized before logging.
- HTTPS-only upstream API endpoints.

## Initial feature set

### Accounts

- Add any number of LogicBoxes-compatible accounts.
- Map each account to its WHMCS registrar module.
- Live/test API endpoints.
- Selling currency and multiplier metadata.
- Default nameserver overrides.
- Funds threshold configuration.
- Per-account automation toggles.

### Customers

- Search/import LogicBoxes customers into WHMCS.
- Export a WHMCS client to a selected reseller account.
- Persistent WHMCS client ↔ LogicBoxes customer mapping.
- Optional automatic customer creation on `ClientAdd`.
- Optional profile propagation on `ClientEdit`.
- Safe-delete mode is opt-in and refuses destructive synchronization when a mapping is not clearly owned by the module.
- New LogicBoxes customer passwords are random, used only for the upstream signup request, and never stored or synchronized from WHMCS.

### Domains

- Search up to 500 LogicBoxes domain orders per API page.
- Import domain records with upstream order/customer IDs retained in a mapping table.
- RAA / registrant-verification state visibility.
- Pending-transfer status inspection.
- Registrar reassignment and management-addon planning.
- Account/customer move primitives built on the documented LogicBoxes product move API.

### TLD pricing

- Fetch generic LogicBoxes customer selling prices.
- Fetch reseller cost prices.
- Fetch active registrar promotions.
- Normalize register, renew, transfer and restore actions.
- Build 1-10 year selling matrices.
- Fixed-margin and percentage-margin policies.
- Explicit rounding increment.
- Dry-run diff before writing.
- Apply through the WHMCS `CreateOrUpdateTLD` Local API rather than direct `tblpricing` mutation.
- Store complete before/proposed/applied job snapshots.

### Existing domain recurring prices

- Preview affected WHMCS domains first.
- Preserve zero-valued/free domains by default.
- Respect domain registration period and client currency.
- Update through WHMCS `UpdateClientDomain`.
- Every applied row records the old recurring amount for rollback tooling.

### Automation

A module-local `hooks.php` is automatically loaded by WHMCS after addon activation.

The daily worker uses account-level settings and database locks. High-impact write automation is **disabled by default**. Operators should run previews manually before enabling a matching scheduled policy.

## Database tables

Activation creates tables with the `mod_serverspan_logicboxes_` prefix for:

- reseller accounts;
- customer mappings;
- domain mappings;
- jobs;
- job items / snapshots;
- promotion cache;
- audit events;
- distributed locks.

Deactivation does **not** destroy audit, mappings or job history. Use the explicit uninstall function only when permanent data removal is intended.

## Installation

Copy the `modules` directory from the release archive into the WHMCS root, then activate **ServerSpan LogicBoxes Tools** under **Configuration > System Settings > Addon Modules**.

Because the hooks live inside the addon directory, no `/includes/hooks` file is required. WHMCS detects `modules/addons/serverspanlogicboxestools/hooks.php` when the addon is activated or its addon settings are re-saved.

## Provider prerequisites

For every account:

1. Obtain the reseller ID and API key.
2. Whitelist the WHMCS server's outbound IP address in the provider control panel.
3. Use HTTPS only.
4. For testing, enable the LogicBoxes test endpoint (`https://test.httpapi.com/api`).

## Operational warning

Do not enable automatic TLD or recurring-price writes simply because an initial preview looks reasonable. Test client-group, multi-currency, free-domain, promotion and non-standard registration-period cases first.

## Tests

```bash
php addons/logicboxes-tools/tests/selftest.php
```

The self-tests validate pure normalization, price policy, redaction, password handling, paging, job-state and mapping logic without requiring WHMCS or live provider credentials.

## Security

Never include API keys, generated customer passwords, WHMCS encryption material, raw provider payloads containing contact data, or database dumps in GitHub issues.

ServerSpan: [WHMCS-compatible reseller hosting](https://www.serverspan.com/en/webreseller) · [KVM & LXC VPS](https://www.serverspan.com/en/virtual-servers) · [DevOps tools](https://www.serverspan.com/en/tools/index)
