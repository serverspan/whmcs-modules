# Module Catalog

This page is the operational inventory for `serverspan/whmcs-modules`. It answers the questions that matter before installing a module: where it installs, what it talks to, what data it creates, whether it runs hooks/cron, and what still needs live validation.

The repository currently contains **13 WHMCS modules** across payment gateways, addons and service-provisioning modules.

## Payment gateways

| Module | Install path | Upstream dependency | Custom schema | Hooks / cron | Validation status |
|---|---|---|---|---|---|
| [Revolut Gateway](../payments/revolut/) | `modules/gateways/` + callback/helper files | Revolut Merchant API | No module-owned operational tables | Gateway callbacks + verified webhook handling | Static/self-tests pass; provider sandbox E2E still required before production billing. |

## Addon modules

| Module | Install path | Upstream dependency | Custom schema | Hooks / cron | Validation status |
|---|---|---|---|---|---|
| [Sales Tracker](../addons/sales-tracker/) | `modules/addons/serverspansalestracker/` | None | None | None | Read-only analytics; dedicated self-tests pass; live WHMCS data comparison still recommended. |
| [Colete Online](../addons/colete-online/) | `modules/addons/serverspancoleteonline/` | Colete-Online API | `mod_serverspan_coleteonline_shipments`, `mod_serverspan_coleteonline_cache` | Admin-operated shipment/AWB/label/tracking workflow | Static/self-tests pass; Colete-Online staging quote/AWB/label/tracking flow still required. |
| [LogicBoxes Tools](../addons/logicboxes-tools/) | `modules/addons/serverspanlogicboxestools/` | LogicBoxes / ResellerClub HTTP API | Account, mapping, job, audit, cache/lock and pricing state tables | Client lifecycle hooks + daily automation | Static/self-tests and repeated-include regressions pass; test-account validation required before unattended financial writes. |
| [Super Email Verification](../addons/supermailverify/) | `modules/addons/supermailverify/` | Optional mail providers + optional reCAPTCHA / Turnstile | `mod_sev_emails`, `mod_sev_email_bans`, `mod_sev_ip_bans`, `mod_sev_domains` | Registration/checkout/ticket/contact hooks + daily cron | PHP linted in CI; live WHMCS flow validation recommended before enforcing account deletion/deactivation automation. |
| [Support PIN](../addons/supportpin/) | `modules/addons/supportpin/` | None | `mod_pin_pins`, `mod_pin_grants`, `mod_pin_log` | Client/admin hooks + daily cleanup | PHP linted in CI; live WHMCS role/access validation recommended before enabling staff restrictions. |
| [Identity Verification - Didit](../addons/diditkyc/) | `modules/addons/diditkyc/` | Didit API + hosted KYC workflow | `mod_didit_sessions`, `mod_didit_log` | Checkout gate, client banner/sidebar, signed webhook receiver + daily stale-session reconciliation | PHP linted in CI; Didit test workflow/webhook validation should be completed before enforcing checkout gating or automated client-state changes. |
| [Identity Verification - Stripe](../addons/stripekyc/) | `modules/addons/stripekyc/` | Stripe Identity API | `mod_stripekyc_sessions`, `mod_stripekyc_log` | Checkout gate, client banner/sidebar, signed webhook receiver + daily stale-session reconciliation | PHP linted in CI; Stripe test-mode verification/webhook flow should be validated before enforcing checkout gating in production. |
| [PowerDNS Manager](../addons/pdnsmanager/) | `modules/addons/pdnsmanager/` | PowerDNS Authoritative HTTP API + companion WHMCS server records | `mod_pdns_zones`, `mod_pdns_templates`, `mod_pdns_assignments`, `mod_pdns_log` | Registrar lifecycle hooks, `AfterModuleCreate`, client/admin DNS management | PHP linted in CI; validate zone creation/deletion, DNSSEC, templates and multi-server routing against the target PowerDNS version before production use. |
| [QuickBooks Online Sync](../addons/qbosync/) | `modules/addons/qbosync/` | Intuit QuickBooks Online API / OAuth2 | `mod_qbo_auth`, `mod_qbo_map`, `mod_qbo_rel`, `mod_qbo_queue`, `mod_qbo_log` | Client/invoice event queueing + daily queue processing/token refresh | PHP linted in CI; validate OAuth, tax mappings, invoice/payment/refund flows and sandbox reconciliation before production accounting sync. |
| [ownCloud Manager](../addons/ocmanager/) | `modules/addons/ocmanager/` | ownCloud OCS Provisioning API + companion WHMCS server records | `mod_oc_grouplimits`, `mod_oc_log` | Admin-operated; no cron required | PHP linted in CI; validate user/group/quota/sub-admin operations against the target ownCloud version before production use. |

## Server / provisioning modules

These implement the WHMCS service lifecycle rather than only providing an admin/client-area addon.

| Module | Install path | Upstream dependency | Custom schema | Hooks / cron | Validation status |
|---|---|---|---|---|---|
| [ownCloud Storage](../servers/ocstorage/) | `modules/servers/ocstorage/` | ownCloud OCS Provisioning API | No module-owned tables; optionally uses `mod_oc_grouplimits` from ownCloud Manager | WHMCS create/suspend/unsuspend/terminate/change-password/change-package lifecycle | PHP linted in CI; validate the full provisioning lifecycle, quota changes and reseller-group behavior against the target ownCloud version before production use. |
| [PowerDNS DNS Hosting](../servers/pdnshosting/) | `modules/servers/pdnshosting/` | PowerDNS Authoritative HTTP API | No module-owned tables; can reuse `mod_pdns_templates` from PowerDNS Manager | WHMCS create/terminate/test-connection/client-area lifecycle; suspend/unsuspend are documented no-op success | PHP linted in CI; validate zone lifecycle, selected creation method and template application against the target PowerDNS version before production use. |

## Maturity labels

- **Beta** - source is published and repository checks pass, but one or more real WHMCS/provider validation paths remain before we recommend unattended production use.
- **Stable** - the documented supported workflow has been exercised against the stated WHMCS/provider versions and no known release-blocking issues remain.

A semantic version without a `-beta` suffix does not override the maturity label. Older modules entered the repository before the current versioning policy and may still be marked Beta at `1.0.0` or later.

## Automated checks

`make test` currently performs repository-wide PHP linting across `payments/`, `addons/` and `servers/`, plus dedicated behavioral/self-tests for:

- Revolut Gateway
- Sales Tracker
- Colete Online
- LogicBoxes Tools
- LogicBoxes repeated addon-entrypoint include regression
- LogicBoxes repeated hook-registration regression

The remaining modules are currently covered by repository-wide PHP linting but do not yet have dedicated behavioral self-test suites wired into `make test`:

- Super Email Verification
- Support PIN
- Identity Verification - Didit
- Identity Verification - Stripe
- PowerDNS Manager
- QuickBooks Online Sync
- ownCloud Manager
- ownCloud Storage
- PowerDNS DNS Hosting

Provider/API-backed modules still require the live or sandbox validation documented in their own README before financially or operationally sensitive automation is enabled.

## Release packaging

The packaging helper supports both repository layouts currently present:

1. **Canonical layout** - `<module>/modules/` mirrors the WHMCS root.
2. **Historical flat addon layout** - the addon source directory itself represents `modules/addons/<slug>/`.

New modules should use the canonical layout. Flat-layout support exists so older modules can be packaged cleanly without a disruptive source-tree move.

`make package-all` currently builds the modules that already have explicit Makefile packaging targets:

- Revolut Gateway
- Sales Tracker
- Colete Online
- LogicBoxes Tools
- Super Email Verification
- Support PIN

The newer Didit, Stripe Identity, PowerDNS, QuickBooks and ownCloud modules are present in the repository but are **not yet wired into `make package-all`**. Until packaging targets are added, install them from their documented source layout.
