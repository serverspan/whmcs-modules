# Revolut Gateway for WHMCS

Independent, open-source WHMCS payment gateway built against the public **Revolut Merchant API / Checkout SDK** and the public **WHMCS Remote Input Gateway** APIs.

> **Status: Beta.** Local syntax/static tests pass, but a real Revolut Merchant Sandbox + WHMCS end-to-end validation is still required before production use.

Maintained by [ServerSpan](https://www.serverspan.com/en/).

This gateway is independently implemented against the public Revolut Merchant API and WHMCS gateway APIs.

## Implemented functionality

- Revolut-hosted **Card Field** embedded in the WHMCS remote-input iframe.
- Card numbers/CVV never pass through or get stored by WHMCS.
- Revolut merchant-saved card tokens stored in WHMCS for automatic billing.
- Automatic recurring captures using a saved Revolut payment method with `initiator: merchant`.
- Zero-value setup flow for **Add Payment Method** and **Update Payment Method**.
- Remote deletion of a Revolut saved card when WHMCS deletes the Pay Method.
- Best-effort deletion of the old Revolut card after a successful Pay Method update.
- One-time **Revolut Pay** checkout when enabled.
- WHMCS admin refunds through the Revolut order refund API, including partial refunds.
- Verified `ORDER_COMPLETED` webhooks for delayed/pending settlement reconciliation.
- Browser callback + webhook idempotency via `mod_revolutwhmcs_flows`.
- Exact ISO-4217 minor-unit conversion - no `float * 100` charging bug.
- Optional customer choice to **save or not save** the card on one-time invoice checkout.
- Required editable **cardholder name** field.
- Optional **Force 3D Secure** for card payments.
- Optional **Revolut transaction fee recording** in WHMCS.
- Revolut Checkout locale follows the WHMCS client language when Revolut supports it.
- Production and Sandbox modes.
- Pinned Merchant API version, default **`2026-04-20`**.
- HMAC-signed browser state and completion tokens.
- Replay-resistant checkout flow IDs and 5-minute webhook replay protection.

## Deliberate implementation choices

### Force 3DS vs Revolut Pay

Revolut documents `enforce_challenge: forced` as card-only. Therefore, when **Force 3D Secure** is enabled, this module hides Revolut Pay for that checkout and uses the forced challenge for the card flow. Disable Force 3DS if you want customers to choose Revolut Pay on the same invoice page.

### Card-saving choice

When **Let Customers Choose Card Saving** is enabled, invoice checkout shows a checkbox (checked by default). If the customer unticks it, the invoice can still be paid but no reusable WHMCS Pay Method is created.

**Add Payment Method** and **Update Payment Method** always request `savePaymentMethodFor: merchant`, because those workflows have no purpose without a reusable token.

### Fee recording

When **Record Revolut Fees** is enabled, the module sums fees reported by Revolut for captured/completed payments **only when the fee currency matches the WHMCS transaction currency**. It does not guess or convert fees reported in another settlement currency.

### Currency support

The module intentionally does not hard-code a stale currency whitelist. It sends the WHMCS invoice currency to Revolut and lets the current Merchant API enforce account/payment-method currency availability.

## Requirements

Recommended target:

- WHMCS **8.13.x or 9.0.x**.
- PHP **8.1+**.
- PHP cURL extension.
- HTTPS WHMCS installation.
- WHMCS database user permitted to create the module flow table on first use.
- Revolut Business account with an active Merchant account.
- Revolut Merchant API Secret key.
- Revolut Public key only if Revolut Pay is enabled.

WHMCS's Remote Input helper APIs originated in 7.9, but this implementation intentionally uses modern PHP and has **not** been made PHP-7-compatible merely to claim WHMCS 7.9/7.10 support.

## Files

```text
modules/gateways/revolutwhmcs.php
modules/gateways/revolutwhmcs/checkout.php
modules/gateways/revolutwhmcs/complete.php
modules/gateways/revolutwhmcs/lib/RevolutGateway.php
modules/gateways/revolutwhmcs/tools/register_webhook.php
modules/gateways/callback/revolutwhmcs.php
tests/selftest.php
```

## Installation

From this module directory, copy the `modules` directory into the root of the WHMCS installation, preserving paths.

Then activate **Revolut Gateway** in WHMCS under **Configuration -> System Settings -> Payment Gateways** (wording can vary slightly by WHMCS version).

Configure the following fields:

| Setting | Purpose |
|---|---|
| Secret API Key | Revolut Merchant API secret key (`sk_...`). Required. |
| Public API Key | Revolut public key. Required only for Revolut Pay. |
| Webhook Signing Secret | Secret (`wsk_...`) returned when registering the webhook. |
| Enable Revolut Pay | Adds one-time Revolut Pay alongside card checkout. |
| Let Customers Choose Card Saving | Allows one-time invoice payers to opt out of card token storage. |
| Force 3D Secure | Sets `enforce_challenge: forced`; hides Revolut Pay for that flow. |
| Record Revolut Fees | Records same-currency Revolut fees in WHMCS transaction fees. |
| Sandbox | Uses Revolut Merchant Sandbox and Checkout sandbox mode. |
| Merchant API Version | Leave at `2026-04-20` until the module is reviewed for a later API version. |
| API Timeout | Server-to-server cURL timeout, default 30 seconds. |

Deactivate/reactivate the gateway after changing the module type/functions if WHMCS previously detected an older copy of the module.

## Webhook registration

Callback URL:

```text
https://YOUR-WHMCS/modules/gateways/callback/revolutwhmcs.php
```

Subscribe at minimum to:

```text
ORDER_COMPLETED
```

After the gateway Secret API Key is configured, the included CLI helper can create the webhook:

```bash
php modules/gateways/revolutwhmcs/tools/register_webhook.php
```

It prints the returned webhook `signing_secret`. Put that value in **Webhook Signing Secret** in WHMCS.

The callback verifies:

- `Revolut-Request-Timestamp`.
- `Revolut-Signature` using HMAC-SHA256 over `v1.<timestamp>.<raw-body>`.
- A maximum timestamp skew of five minutes.
- The authoritative order state by re-fetching the order from Revolut before crediting WHMCS.

## First payment / saved-card flow

For a new card payment:

1. WHMCS invokes `revolutwhmcs_remoteinput()`.
2. The module emits only a signed local state form into the WHMCS remote-input iframe.
3. `checkout.php` creates the Revolut order server-side.
4. Revolut's Card Field iframe receives the card data directly.
5. If saving is enabled, `savePaymentMethodFor: merchant` tells Revolut to create a merchant-initiated reusable payment method.
6. On successful completion, the module re-fetches the order from Revolut.
7. The invoice is credited exactly once.
8. If a merchant-saved card exists, WHMCS stores only a compact token containing the Revolut customer ID, payment-method ID, and method type.

The compact WHMCS token looks conceptually like:

```text
rv1.<base64url-json>
```

It does **not** contain PAN, CVV, or other raw card credentials.

## Automatic recurring billing

When WHMCS later invokes `revolutwhmcs_capture()` with a saved gateway token, the module:

1. Creates a Revolut order for the WHMCS invoice.
2. Assigns the saved Revolut customer to the order.
3. Calls `POST /api/orders/{order_id}/payments` with the saved payment-method ID and `initiator: merchant`.
4. Treats `captured`/`completed` as success.
5. Treats Revolut's documented authentication/authorisation/capture intermediate states as pending rather than as a decline.
6. Lets the verified `ORDER_COMPLETED` webhook reconcile the invoice when capture completes asynchronously.

The order ID is the WHMCS transaction ID.

## Add / Update Payment Method

The module uses a Revolut **zero-amount order** for card setup. No fake €1 authorisation charge is required.

On update:

1. The new card is saved and the WHMCS Pay Method is updated first.
2. The previous Revolut saved card is deleted best-effort afterwards.
3. Cleanup also works when Revolut created a new customer object during the replacement-card setup flow.

## Delete Payment Method

The gateway implements the WHMCS remote-token deletion hook. Deleting the Pay Method in WHMCS attempts to delete the corresponding saved payment method at Revolut. A Revolut `404` is treated as already deleted and therefore safe to remove locally.

## Refunds

WHMCS's refund action calls:

```text
POST /api/orders/{original_order_id}/refund
```

The refund amount uses exact integer minor units. A fresh idempotency key is used for each WHMCS refund request so two legitimate equal-value partial refunds are not accidentally collapsed into one API operation.

## Security properties

- Secret API key is server-side only.
- Public key is exposed only to Revolut Pay as designed by Revolut.
- Raw card data is entered into Revolut's PCI-hosted field.
- Client/invoice/order bootstrap data is HMAC-SHA256 signed before leaving the WHMCS page.
- The signed state expires after 30 minutes.
- A signed random flow ID makes iframe/browser retries reuse the same Revolut order instead of creating duplicate orders from the same WHMCS invocation.
- Completion URLs are HMAC signed.
- Return URLs are restricted to the WHMCS host.
- Webhooks are HMAC verified with timestamp replay protection.
- Webhook/browser reconciliation is database-idempotent.
- Transaction IDs are checked before `addInvoicePayment()`.
- Server-side cURL keeps TLS peer/hostname verification enabled.
- No API secrets are logged intentionally.

## Local verification

Run:

```bash
php tests/selftest.php
```

The bundled suite currently passes **21 standalone self-tests** and checks:

- EUR/GBP-style 2-decimal conversion.
- JPY-style zero-decimal conversion.
- KWD-style 3-decimal conversion.
- Excess precision rejection.
- WHMCS/Revolut token round-trip.
- Signed browser state verification and tamper rejection.
- Revolut locale mapping.
- Revolut fee parsing.
- Webhook signature verification and tamper rejection.
- Safe return-URL host validation.

Also syntax-check the package with:

```bash
find modules -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Test status - read before production use

The source has been statically reviewed, PHP-syntax checked, and its standalone helpers have local tests. It has **not** been end-to-end charged against your actual WHMCS installation and your Revolut Merchant Sandbox account because those credentials/environment were not supplied here.

Before production, test at minimum:

1. Sandbox card payment without 3DS.
2. Sandbox card payment with 3DS.
3. Force-3DS mode.
4. Customer card-saving opt-out.
5. Add Payment Method (zero amount).
6. Update Payment Method and remote deletion of the old card.
7. Delete Pay Method from WHMCS.
8. WHMCS cron automatic recurring capture.
9. A deliberately delayed/pending payment followed by `ORDER_COMPLETED`.
10. One-time Revolut Pay, desktop and mobile redirect.
11. Full refund.
12. Two separate equal-value partial refunds.
13. Fee recording on a transaction where Revolut reports an acquiring fee.
14. WHMCS 8.13/9.0 client-area theme(s) actually used in production.

Do not skip the Sandbox pass. Payment-gateway code should not be promoted solely because it lints cleanly.

## API targets

Implementation target at build time:

- Revolut Merchant API: `2026-04-20`.
- Revolut Checkout Card Field.
- Revolut Payments module / Revolut Pay.
- WHMCS Remote Input Gateway (`remoteinput`, `remoteupdate`, `capture`).
- WHMCS tokenised remote storage deletion.
- WHMCS callback helpers (`checkCbInvoiceID`, `addInvoicePayment`, `invoiceSaveRemoteCard`, `createCardPayMethod`, `updateCardPayMethod`).

## Reference documentation

This implementation was built against the public documentation below:

- Revolut Merchant API: https://developer.revolut.com/docs/api/merchant
- Revolut Card Field SDK: https://developer.revolut.com/docs/sdks/merchant-web-sdk/payment-methods/card-field
- Revolut Payments module: https://developer.revolut.com/docs/sdks/merchant-web-sdk/initialisation/payments-module
- Revolut Pay SDK: https://developer.revolut.com/docs/sdks/merchant-web-sdk/payment-methods/revolut-pay
- Revolut subscription/saved-payment-method flow: https://developer.revolut.com/docs/guides/merchant/optimise-checkout/save-payment-methods/subscription-management
- Revolut webhook signature verification: https://developer.revolut.com/docs/guides/merchant/monitor-and-observe/webhooks/verify-the-payload-signature
- WHMCS Remote Input Gateway: https://developers.whmcs.com/payment-gateways/remote-input-gateway/
- WHMCS Tokenised Remote Storage: https://developers.whmcs.com/payment-gateways/tokenised-remote-storage/
- WHMCS gateway callbacks/refunds: https://developers.whmcs.com/payment-gateways/callbacks/ and https://developers.whmcs.com/payment-gateways/refunds/

## License

MIT - see the repository root [`LICENSE`](../../LICENSE).

## ServerSpan

This module is maintained by **ServerSpan**. If WHMCS is part of a hosting business or billing stack, the most relevant ServerSpan services are:

- [WHMCS-compatible white-label reseller hosting](https://www.serverspan.com/en/webreseller) for agencies and hosting providers.
- [KVM & LXC VPS hosting](https://www.serverspan.com/en/virtual-servers) for self-managed WHMCS, automation, and hosting infrastructure.

These links are not required for the module to function; the gateway has no ServerSpan licensing callback or telemetry dependency.
