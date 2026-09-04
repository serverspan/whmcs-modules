# Module Catalog

This page is the operational inventory for `serverspan/whmcs-modules`. It answers the questions that matter before installing a module: where it installs, what it talks to, what data it creates, whether it runs hooks/cron, and what still needs live validation.

## Catalog

| Module | Install path | Upstream dependency | Custom schema | Hooks / cron | Validation status |
|---|---|---|---|---|---|
| [Revolut Gateway](../payments/revolut/) | `modules/gateways/` + callback/helper files | Revolut Merchant API | No module-owned operational tables | Gateway callbacks + webhook handling | Static/self-tests pass; provider sandbox E2E still required before production billing. |
| [Sales Tracker](../addons/sales-tracker/) | `modules/addons/serverspansalestracker/` | None | None | None | Read-only analytics; live WHMCS data comparison still recommended. |
| [Colete Online](../addons/colete-online/) | `modules/addons/serverspancoleteonline/` | Colete-Online API | `mod_serverspan_coleteonline_shipments`, `mod_serverspan_coleteonline_cache` | Admin-operated; no fake/undocumented cancellation automation | Static/self-tests pass; Colete-Online staging quote/AWB/label/tracking flow still required. |
| [LogicBoxes Tools](../addons/logicboxes-tools/) | `modules/addons/serverspanlogicboxestools/` | LogicBoxes/ResellerClub HTTP API | Account, mapping, job, audit, cache/lock and pricing state tables | Client lifecycle hooks + daily automation | Static/self-tests and repeated-include regressions pass; test-account validation required before unattended financial writes. |
| [Super Email Verification](../addons/supermailverify/) | `modules/addons/supermailverify/` | Optional mail providers + optional reCAPTCHA/Turnstile | `mod_sev_emails`, `mod_sev_email_bans`, `mod_sev_ip_bans`, `mod_sev_domains` | Registration/checkout/ticket/contact hooks + daily cron | PHP linted in CI; live WHMCS flow validation recommended before enforcing account deletion/deactivation automation. |
| [Support PIN](../addons/supportpin/) | `modules/addons/supportpin/` | None | `mod_pin_pins`, `mod_pin_grants`, `mod_pin_log` | Client/admin hooks + daily cleanup | PHP linted in CI; live WHMCS role/access validation recommended before enabling staff restrictions. |

## Maturity labels

- **Beta** - source is published and repository checks pass, but one or more real WHMCS/provider validation paths remain before we recommend unattended production use.
- **Stable** - the documented supported workflow has been exercised against the stated WHMCS/provider versions and no known release-blocking issues remain.

A semantic version without a `-beta` suffix does not override the maturity label. Older modules entered the repository before the current versioning policy and may still be marked Beta at `1.0.0`.

## Automated checks

`make test` currently performs repository-wide PHP linting plus dedicated self-tests for:

- Revolut Gateway
- Sales Tracker
- Colete Online
- LogicBoxes Tools
- LogicBoxes repeated addon-entrypoint include regression
- LogicBoxes repeated hook-registration regression

`supermailverify` and `supportpin` are covered by repository-wide PHP linting but do not yet have dedicated behavioral self-test suites.

## Release packaging

`make package-all` builds every distributable module into `dist/`.

The packager supports both repository layouts currently present:

1. **Canonical layout** - `<module>/modules/` mirrors the WHMCS root.
2. **Historical flat addon layout** - the addon source directory itself represents `modules/addons/<slug>/`.

New modules must use the canonical layout. Flat-layout support exists only so older modules can be packaged cleanly without a disruptive source-tree move.
