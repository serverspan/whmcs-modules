# ServerSpan Sales Tracker for WHMCS

A read-only WHMCS addon for **sales reporting, product rankings, sales-agent comparison, self-vs-agent order attribution, and date-based performance analysis**.

This is an open-source ServerSpan implementation built against WHMCS's public addon-module interfaces and current order schema. It does not patch WHMCS core files, add encoded PHP, call a licensing server, or send reporting data to ServerSpan.

## Features

- Sales dashboard with responsive inline graphs.
- Custom **From / To** date filtering.
- Order-status filtering using the statuses configured in WHMCS.
- Currency filtering with **no mixed-currency revenue totals**.
- Optional **Paid invoices only** view.
- Sales-value trend chart.
- Order-count trend chart.
- Top-selling hosting products by units sold.
- Agent sales comparison by order count, sales value, average order, and share.
- Top sales agents ranking.
- Per-agent detail pages with trend, products, and matching orders.
- Self/web vs. agent-driven order breakdown.
- Recent matching order records.
- No external charting CDN or JavaScript framework.
- No custom database tables and no uninstall residue.

## How agent attribution works

Modern WHMCS orders expose both `requestor_id` and `admin_requestor_id`. WHMCS itself uses this information on the order page to show whether an order was placed by a user or an administrator.

This module defines:

- **Agent order:** `tblorders.admin_requestor_id > 0`
- **Self / web order:** no administrator requestor is recorded

This is substantially more reliable than trying to infer the salesperson from activity-log text.

Orders placed through unusual custom/API workflows that do not populate `admin_requestor_id` will therefore appear under **Self / web**. That is intentional; the module does not invent attribution that WHMCS did not record.

## Metric definitions

### Sales value

Sales value is the sum of `tblorders.amount` for orders matching the selected filters, grouped by the **order date**.

It is an order-performance metric, not a bank/cash-receipts accounting report. Enable **Paid invoices only** when you only want orders whose linked invoice currently has the `Paid` status.

### Top products

Products are ranked by the number of `tblhosting` service records attached to matching orders and grouped by `tblproducts`. Product addons and domains are intentionally not disguised as hosting products.

### Currency handling

WHMCS order amounts are denominated in the client's configured currency. The dashboard requires one currency at a time and never adds EUR, USD, RON, etc. into one meaningless total.

## Compatibility

Target:

- PHP 8.1+
- WHMCS 8.13+
- WHMCS 9.x architecture expected to be compatible

Current validation status:

| Validation | Status |
|---|---|
| PHP syntax / static module loading | Tested |
| Pure analytics self-tests | Tested |
| Real WHMCS 8.13 installation | Not yet exercised |
| Real WHMCS 9.x installation | Not yet exercised |
| Large production dataset benchmark | Not yet exercised |

Until it is exercised against a real WHMCS installation, the module is deliberately marked **beta**.

## Installation

Copy the contents of the release ZIP into the root of your WHMCS installation. The installed path will be:

```text
modules/addons/serverspansalestracker/
├── serverspansalestracker.php
├── assets/
│   └── admin.css
└── lib/
    ├── Renderer.php
    ├── SalesAnalytics.php
    └── SalesRepository.php
```

Then:

1. Open **Configuration / System Settings > Addon Modules** in WHMCS.
2. Activate **ServerSpan Sales Tracker**.
3. Configure which administrator roles may access it.
4. Open **Addons > ServerSpan Sales Tracker**.

Activation creates **no database tables**.

## Configuration

The addon exposes only presentation defaults:

- **Default Date Range:** 7, 30, 90, or 365 days.
- **Paid Only by Default:** open the report with the Paid filter enabled.
- **Top Results:** number of products/agents to show in ranking tables.

All report filters remain selectable directly on the dashboard.

## Database access

The module performs read-only queries against existing WHMCS tables including:

- `tblorders`
- `tblclients`
- `tblcurrencies`
- `tblinvoices`
- `tbladmins`
- `tblorderstatuses`
- `tblhosting`
- `tblproducts`
- `tblproductgroups`

It does **not** alter these tables.

## Deactivation / uninstall

Deactivate the addon in WHMCS and delete:

```text
modules/addons/serverspansalestracker/
```

There is no module-owned data to migrate or remove.

## Security and privacy

- Admin-area only.
- Respects WHMCS addon-role access controls.
- Read-only reporting queries.
- No client-area endpoint.
- No cron/background worker.
- No telemetry or remote API.
- No customer data is intentionally logged.
- Errors shown to administrators are generic; detailed sanitized errors go to the WHMCS Module Log.

## ServerSpan

This addon is maintained as part of the open-source **ServerSpan WHMCS Modules** collection.

If WHMCS is part of your hosting stack, these ServerSpan resources are directly relevant:

- [WHMCS-compatible reseller hosting](https://www.serverspan.com/en/webreseller) for white-label hosting businesses and agencies.
- [KVM & LXC VPS hosting](https://www.serverspan.com/en/virtual-servers) for WHMCS, automation workers, billing infrastructure, and hosting control planes.
- [DirectAdmin web hosting](https://www.serverspan.com/en/webhosting) for managed shared-hosting workloads.
- [ServerSpan DevOps & sysadmin tools](https://www.serverspan.com/en/tools/index) for common infrastructure operations.

For operators building a hosting business around WHMCS, see ServerSpan's [reseller hosting business guide](https://www.serverspan.com/en/blog/how-to-start-a-reseller-hosting-business-in-2026-complete-step-by-step-playbook).

## License

MIT. See the repository-level `LICENSE`.

WHMCS is a trademark of WHMCS Limited. ServerSpan is not affiliated with or endorsed by WHMCS Limited.
