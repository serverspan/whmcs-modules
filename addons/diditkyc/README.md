# ServerSpan Identity Verification (Didit)

A WHMCS addon module developed by [ServerSpan](https://www.serverspan.com) that integrates
[Didit](https://www.didit.me) hosted KYC sessions into the WHMCS client lifecycle:
clients verify their identity in Didit's hosted flow (ID document, liveness, face match,
AML), and staff track every session from the WHMCS admin area.

## Directory layout

```
modules/addons/diditkyc/
├── diditkyc.php             # module config, activation, admin area, client area + webhook endpoint
├── hooks.php                # checkout gate, unverified banner, sidebar link, cron reconciliation
├── lib/
│   └── Functions.php        # Didit API client, webhook signature verification, outcome handling
├── templates/
│   └── kyc.tpl              # client-area verification page
└── lang/
    ├── english.php
    └── romanian.php
```

## Installation

1. Copy the folder to `modules/addons/diditkyc/`.
2. In WHMCS admin go to **System Settings > Addon Modules**, activate
   *ServerSpan Identity Verification (Didit)*. Activation creates two tables:
   `mod_didit_sessions`, `mod_didit_log`.
3. In the Didit Business Console create/publish a **KYC workflow**, then copy the
   workflow UUID and an API key (needs `read:sessions` + `write:sessions`) into the
   module configuration.
4. In **Didit Console > API & Webhooks**, add a webhook destination pointing to
   `https://your-whmcs/index.php?m=diditkyc&action=webhook`, subscribe it to
   `status.updated` with version `v3`, and paste the destination's
   `secret_shared_key` into the module's **Webhook Secret** field.
5. Test the endpoint with the console's **Try Webhook** scenarios before going live.

Requires WHMCS 8.6+ / PHP 8.1+.

## How it works

- The client opens **Identity Verification** in the client area (sidebar link or
  banner) and clicks Start. The module creates a Didit session server-side
  (`vendor_data = whmcs-user-<id>`, so Didit reuses an unfinished session instead of
  duplicating) and redirects to the hosted verification URL.
- When the client finishes, Didit redirects back (`callback_method = both`) and the
  module re-syncs the session status from the API. The authoritative update path is
  the `status.updated` webhook, verified with HMAC-SHA256 (`X-Signature-V2`, with
  `X-Signature` and `X-Signature-Simple` fallbacks, 5-minute timestamp window).
- The daily cron re-polls sessions stuck in non-terminal states for over an hour,
  so a missed webhook never leaves a client in limbo.

## Feature map

| Feature | Where |
|---|---|
| Hosted Didit KYC session creation and redirect | Client area page |
| Idempotent sessions via `vendor_data` | `didit_create_session()` |
| HMAC-signed webhook receiver (3 signature variants) | `index.php?m=diditkyc&action=webhook` |
| Session list with status badges, search, refresh, hosted-link | Admin > Sessions |
| Status counters (Approved / Pending / In Review / Declined) | Admin > Sessions header |
| Audit log of creations, syncs and webhooks | Admin > Log |
| Block checkout until Approved | `ShoppingCartValidateCheckout` hook |
| Banner for unverified clients | `ClientAreaHeaderOutput` hook |
| Move approved clients to a client group | Config: Verified Client Group ID |
| Mark declined clients Inactive/Closed | Config: On Declined Verification |
| Webhook-miss reconciliation | `DailyCronJob` hook |
| Expected name + email prefill, WHMCS-language → Didit locale mapping | Session payload |

## Notes

- The API key stays server-side only; clients only ever see the hosted session URL.
- Decision data is stored per session (`decision_json`) when a terminal status arrives.
- Deactivation preserves all `mod_didit_*` tables; drop them manually for a full reset.
- Admin actions are CSRF-protected with the WHMCS admin token; client forms use the
  client-area CSRF token.
- Didit gives 500 free verifications/month per application — see
  [didit.me](https://www.didit.me) for pricing.

## About

Developed by [ServerSpan](https://www.serverspan.com). If you operate WHMCS for a
hosting business, see [ServerSpan WHMCS-compatible reseller hosting](https://www.serverspan.com/en/webreseller)
and the [ServerSpan DevOps & sysadmin toolbox](https://www.serverspan.com/en/tools/index).
