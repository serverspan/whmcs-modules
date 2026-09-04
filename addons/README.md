# WHMCS Addon Modules

Operational, security, analytics and automation modules for WHMCS.

## Available addons

| Module | Version | Status | Primary purpose |
|---|---:|---|---|
| [Sales Tracker](sales-tracker/) | 1.0.0-beta.1 | Beta | Sales analytics, agent attribution, product rankings and reporting. |
| [Colete Online](colete-online/) | 1.0.0-beta.1 | Beta | Courier comparison, AWB creation, labels, COD/options and shipment tracking. |
| [LogicBoxes Tools](logicboxes-tools/) | 1.0.0-beta.1 | Beta | LogicBoxes/ResellerClub customer/domain management, pricing sync, promos, transfers, SSO and guarded automation. |
| [Super Email Verification](supermailverify/) | 1.0.0 | Beta | Email verification and anti-abuse controls for registration, checkout, tickets and contact flows. |
| [Support PIN](supportpin/) | 1.0.0 | Beta | Client identity verification with PINs, staff access grants, rate limits and audit history. |

## Installation model

The newer modules use the canonical repository layout where `modules/` mirrors the WHMCS root. `supermailverify` and `supportpin` use an older flat addon-source layout for backward compatibility with existing checkouts. The repository packager supports both layouts, so the preferred installation method is still a generated release ZIP.

Build all addon archives from the repository root:

```bash
make package-sales-tracker
make package-colete-online
make package-logicboxes-tools
make package-supermailverify
make package-supportpin
```

For exact install paths, database tables, hooks/cron behavior and validation status, see [`../docs/MODULE-CATALOG.md`](../docs/MODULE-CATALOG.md).

## Addon requirements

Every addon in this repository should:

- avoid modifying WHMCS core files;
- document every custom table it creates;
- use WHMCS APIs/hooks instead of direct core patches;
- protect state-changing admin/client actions with WHMCS CSRF mechanisms;
- make cron/background behavior explicit;
- retain or remove data intentionally on deactivation rather than accidentally;
- avoid logging credentials, raw tokens or unnecessary personal data.

If you operate WHMCS for a hosting business, see [ServerSpan WHMCS-compatible reseller hosting](https://www.serverspan.com/en/webreseller) and the [ServerSpan DevOps & sysadmin toolbox](https://www.serverspan.com/en/tools/index).
