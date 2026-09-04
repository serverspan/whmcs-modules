# ServerSpan QuickBooks Sync (independent recreation)

A WHMCS addon module developed by [ServerSpan](https://www.serverspan.com) that recreates
the feature set of the "QuickBooks Online For WHMCS" marketplace module with original
code: sync WHMCS clients, invoices, payments and refunds into QuickBooks Online —
manually or on cron — with tax, gateway and currency handling and full audit logs.

## Directory layout

```
modules/addons/qbosync/
├── qbosync.php              # module config, activation, admin area (Dashboard/Sync/Mapping/Queue/Log)
├── hooks.php                # auto-queue on client/invoice events, cron queue runner + token refresh
├── lib/
│   └── Functions.php        # OAuth2, QBO API client, sync engine, queue
└── README.md
```

## Installation

1. Copy the folder to `modules/addons/qbosync/`.
2. Create an app at the [Intuit Developer portal](https://developer.intuit.com)
   (scope `com.intuit.quickbooks.accounting`) and register this redirect URI:
   `https://your-whmcs/admin/addonmodules.php?module=qbosync&qbo_oauth=callback`
   (register it exactly, including the query string).
3. In WHMCS admin go to **System Settings > Addon Modules**, activate
   *ServerSpan QuickBooks Sync*, enter the Client ID/Secret and pick the
   environment (sandbox for testing, production for live).
4. Open the module, click **Connect to QuickBooks**, authorize, done.

Requires WHMCS 8.6+ / PHP 8.1+ with curl.

## Feature map

| Feature | Where |
|---|---|
| OAuth2 connect/disconnect, token auto-refresh + rotation | Dashboard + `DailyCronJob` |
| Client → QBO Customer (match by email, unique DisplayName) | Sync engine |
| Profile edits sparse-update the QBO customer (no duplicates) | `customer_update` queue entity |
| WHMCS invoice → QBO Invoice (lines, DocNumber, dates, currency, BillEmail) | Sync engine |
| Unpaid synced invoices update in place via SyncToken | `qbo_update_invoice()` |
| Dual-level taxes via a `combined` TaxCode mapping (TPS+TVQ style) | Mapping tab |
| Transaction → QBO Payment linked via LinkedTxn, gateway→method/account mapping | Sync engine + Mapping |
| Gateway fees recorded in the payment note | Sync engine |
| Refunded invoice → QBO Credit Memo | Sync engine |
| WHMCS item types → QBO Service items (auto-created) | Sync engine |
| Manual sync by type and date range, idempotent | Manual Sync tab |
| Queue with retries, stale-job recovery, 429 backoff | Queue tab + cron |
| Relation assignment WHMCS↔QBO (gateways, tax rules, combined, non-taxable) | Mapping tab |
| Full audit log | Log tab |

## Production hardening

- **Token refresh is race-safe**: the refresh runs under `lockForUpdate` in a
  transaction, so concurrent cron/admin processes can't invalidate each other
  with a rotated refresh token (`invalid_grant`). Tokens are memoized per request.
- **401 resilience**: the API client force-refreshes once and retries on a 401.
- **Create idempotency**: every create carries a QBO `requestid`
  (`whmcs-<entity>-<id>`), so a retried POST returns the original entity instead
  of duplicating it.
- **Rate limiting**: on HTTP 429 the job returns to pending *without* consuming
  a retry attempt and the batch pauses until the next run.
- **Stale recovery**: queue jobs stuck in `processing` for over 10 minutes
  (crashed worker) are re-queued automatically.
- Tokens are stored encrypted with WHMCS `encrypt()`; the refresh token rotates
  on every use and the module always persists the latest value.

## Notes

- QBO enforces unique customer DisplayName across customers/employees/vendors —
  the module appends `[WHMCS-<id>]` to guarantee uniqueness.
- Invoices sync as `GlobalTaxCalculation = TaxExcluded` with per-line TaxCodeRef,
  matching WHMCS's tax-excluded pricing.
- Paid invoices are immutable in the sync (QBO accounting practice): corrections
  after payment flow through payments and credit memos, not invoice edits.
- Deactivation preserves all `mod_qbo_*` tables; drop them manually to reset.

## About

Developed by [ServerSpan](https://www.serverspan.com). If you operate WHMCS for a
hosting business, see [ServerSpan WHMCS-compatible reseller hosting](https://www.serverspan.com/en/webreseller)
and the [ServerSpan DevOps & sysadmin toolbox](https://www.serverspan.com/en/tools/index).
