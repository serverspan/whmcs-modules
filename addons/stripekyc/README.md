# ServerSpan Identity Verification (Stripe)

A WHMCS addon module developed by [ServerSpan](https://www.serverspan.com) that integrates
[Stripe Identity](https://stripe.com/identity) hosted verification into the WHMCS client
lifecycle: clients verify their identity in Stripe's hosted flow (government ID
authenticity, optional selfie match), and staff track every session from the WHMCS
admin area.

## Directory layout

```
modules/addons/stripekyc/
├── stripekyc.php            # module config, activation, admin area, client area + webhook endpoint
├── hooks.php                # checkout gate, unverified banner, sidebar link, cron reconciliation
├── lib/
│   └── Functions.php        # Stripe API client, webhook signature verification, session handling
├── templates/
│   └── kyc.tpl              # client-area verification page
└── lang/
    ├── english.php
    └── romanian.php
```

## Installation

1. Copy the folder to `modules/addons/stripekyc/`.
2. In WHMCS admin go to **System Settings > Addon Modules**, activate
   *ServerSpan Identity Verification (Stripe)*. Activation creates two tables:
   `mod_stripekyc_sessions`, `mod_stripekyc_log`.
3. Copy your Stripe **secret key** (`sk_live_...`, or `sk_test_...` while testing —
   test mode simulates the checks) into the module configuration.
4. In **Stripe Dashboard > Developers > Webhooks**, add an endpoint pointing to
   `https://your-whmcs/index.php?m=stripekyc&action=webhook`, listening to all
   `identity.verification_session.*` events, and paste the endpoint's signing
   secret (`whsec_...`) into the module's **Webhook Signing Secret** field.

Requires WHMCS 8.6+ / PHP 8.1+.

## How it works

- The client opens **Identity Verification** in the client area (sidebar link or
  banner) and clicks Start. The module creates a Stripe `VerificationSession`
  server-side (`type=document`, `client_reference_id = whmcs-user-<id>`,
  `metadata[user_id]`, email prefilled, `return_url` back to the module page) and
  redirects to the hosted URL.
- If an open session already exists (`requires_input`/`processing`), the module
  retrieves it and reuses its fresh URL instead of creating duplicates — per
  Stripe's best practice. Hosted URLs expire; retrieval always returns a fresh one.
- The authoritative update path is the webhook, verified against the
  `Stripe-Signature` header (HMAC-SHA256 over `t.body`, 5-minute tolerance).
  The daily cron re-polls stale non-terminal sessions as a fallback.

## Feature map

| Feature | Where |
|---|---|
| Hosted Stripe Identity session creation and redirect | Client area page |
| Open-session reuse with fresh URL retrieval | `sk_start_session()` |
| Signed webhook receiver (`identity.verification_session.*`) | `index.php?m=stripekyc&action=webhook` |
| Session list with status badges, search, refresh, last-error display | Admin > Sessions |
| Status counters (Verified / Processing / Requires Input / Canceled) | Admin > Sessions header |
| GDPR redaction of verified sessions (`/redact`) | Admin > Sessions |
| Audit log of creations, syncs, webhooks, redactions | Admin > Log |
| Block checkout until verified | `ShoppingCartValidateCheckout` hook |
| Banner for unverified clients | `ClientAreaHeaderOutput` hook |
| Move verified clients to a client group | Config: Verified Client Group ID |
| Optional selfie match and live-capture enforcement | Config fields |
| Webhook-miss reconciliation | `DailyCronJob` hook |

## Notes

- The secret key stays server-side only; clients only ever see the hosted session URL.
- The module stores no PII from verification results — only status, timestamps and
  `last_error.reason`. Use the Redact action to erase a session's PII at Stripe.
- Sessions created outside the module (e.g. in the Stripe Dashboard) are adopted
  locally when a webhook arrives with `metadata.user_id` set.
- Deactivation preserves all `mod_stripekyc_*` tables; drop them manually for a reset.
- Admin actions are CSRF-protected with the WHMCS admin token; client forms use the
  client-area CSRF token.

## About

Developed by [ServerSpan](https://www.serverspan.com). If you operate WHMCS for a
hosting business, see [ServerSpan WHMCS-compatible reseller hosting](https://www.serverspan.com/en/webreseller)
and the [ServerSpan DevOps & sysadmin toolbox](https://www.serverspan.com/en/tools/index).
