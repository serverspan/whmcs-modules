# ServerSpan EuPlătesc Gateway

A WHMCS payment gateway module developed by [ServerSpan](https://www.serverspan.com)
for [EuPlătesc](https://www.euplatesc.ro), the Romanian card processor: hosted payment
page with signed requests, verified IPN callback, recurring and installment support.
Pairs with the [ServerSpan EuPlătesc Manager addon](../../addons/epmanager/) for
captures, refunds, reversals and reporting.

Written in PHP from the public EuPlătesc API contract (the reference Node.js library is
at `vladutilie/euplatesc`, MIT).

## Directory layout

```
modules/gateways/
├── euplatesc.php                 # gateway module (config + payment form)
├── callback/
│   └── euplatesc.php             # IPN handler (signature-verified)
└── euplatesc/
    └── lib/
        └── EpApi.php             # signing, response verification, backoffice client
```

## Installation

1. Copy `euplatesc.php` to `modules/gateways/`, `callback/euplatesc.php` to
   `modules/gateways/callback/`, and `euplatesc/lib/EpApi.php` to
   `modules/gateways/euplatesc/lib/`.
2. In WHMCS admin go to **System Settings > Payment Gateways**, activate
   *EuPlătesc (ServerSpan)* and enter your Merchant ID and Secret Key from the
   EuPlătesc panel (Integration Parameters), plus the test credentials and
   backoffice User Key/User API (Account permissions) if you want captures,
   refunds and reporting from the Manager addon.
3. The IPN/silent URL is `https://your-whmcs/modules/gateways/callback/euplatesc.php`.

Requires WHMCS 8.6+ / PHP 7.4+.

## How it works

- The payment form posts to the EuPlătesc hosted page with the signed field set
  (`amount, curr, invoice_id, order_desc, merch_id, timestamp, nonce` +
  `recurent_freq`/`recurent_exp` when recurring is enabled). Signing follows the
  documented scheme: length-prefixed concatenation, empty values as `-`,
  HMAC-SHA1 with the hex-packed secret key, uppercase hex output.
- The IPN callback verifies `fp_hash` over the response fields before trusting
  anything, checks the invoice exists and the amount matches, then records the
  payment idempotently on `ep_id` (duplicate IPNs are safe).
- Success/failure redirects return the client to the WHMCS invoice.

## Feature map

| Feature | Where |
|---|---|
| Signed hosted-payment redirect (card) | `euplatesc_link()` |
| Full billing detail passthrough | Payment field builder |
| Installments filter, payment page language | Config fields |
| Recurring transactions (`recurent_freq`/`recurent_exp`) | Config toggle |
| Signature-verified IPN, idempotent `addInvoicePayment` | Callback |
| Live/test credential pairs with mode toggle | Config fields |

## About

Developed by [ServerSpan](https://www.serverspan.com). If you operate WHMCS for a
hosting business, see [ServerSpan WHMCS-compatible reseller hosting](https://www.serverspan.com/en/webreseller)
and the [ServerSpan DevOps & sysadmin toolbox](https://www.serverspan.com/en/tools/index).
