# ServerSpan EuPlătesc Manager (admin addon)

A WHMCS addon module developed by [ServerSpan](https://www.serverspan.com): the
backoffice for the [ServerSpan EuPlătesc gateway](../../payments/euplatesc/).
Transaction actions, settlement reporting, saved-card management and the IPN log —
all from the WHMCS admin area, reading credentials from the gateway configuration
(single credential store).

## Directory layout

```
modules/addons/epmanager/
├── epmanager.php            # module config, activation, admin tabs
├── lib/
│   └── Functions.php        # gateway-lib loader, action runner, logging
└── README.md
```

## Installation

1. Install and configure the EuPlătesc gateway first (including the backoffice
   **User Key** and **User API** — the backoffice methods require them).
2. Copy the folder to `modules/addons/epmanager/`, activate under
   **System Settings > Addon Modules**, grant admin role access.

Requires WHMCS 8.6+ / PHP 7.4+.

## Feature map

| Feature | Where |
|---|---|
| Merchant info (Check MID) and mode/MID display | Dashboard |
| Captured totals per currency, IPN events today | Dashboard |
| Transaction list (WHMCS `tblaccounts` filtered to the gateway) | Transactions |
| Capture, partial capture, refund, reversal, cancel recurring, update invoice id | Transactions row actions |
| Transaction status lookup and card art viewer by EP ID | Transactions > Status tool |
| Settlement invoices by range + per-invoice transaction drill-down | Settlements |
| Saved cards per client with remove | Saved Cards |
| IPN log (signature failures, mismatches, recorded payments, ws actions) | IPN Log |

## Notes

- The backoffice layer posts to the EuPlătesc manager web service with the
  gateway's MID, user key and user API. The ws action names live in one map at
  the top of `modules/gateways/euplatesc/lib/EpApi.php` — if your panel's API
  doc names an action differently, it is a one-line edit.
- Refunds and partial captures always carry the amount you type; reversal and
  full capture take no amount.
- Deactivation preserves `mod_ep_log`.

## About

Developed by [ServerSpan](https://www.serverspan.com). If you operate WHMCS for a
hosting business, see [ServerSpan WHMCS-compatible reseller hosting](https://www.serverspan.com/en/webreseller)
and the [ServerSpan DevOps & sysadmin toolbox](https://www.serverspan.com/en/tools/index).
