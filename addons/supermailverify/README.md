# Super Email Verification Pro (independent recreation)

A WHMCS addon module that recreates the feature set of the "Super Email Verification Pro"
marketplace module with original code: email verification codes, anti-spam registration
control, disposable-domain blocking, email/IP ban lists, statistics and outbound mail tools.

## Directory layout

```
modules/addons/supermailverify/
├── supermailverify.php      # module config, activation, admin area, client area
├── hooks.php                # registration/checkout/ticket/contact hooks, cron automation
├── lib/
│   └── Functions.php        # settings, codes, bans, mailer, captcha helpers
├── templates/
│   └── verify.tpl           # client-area verification page
└── lang/
    ├── english.php
    └── romanian.php
```

## Installation

1. Copy the folder to `modules/addons/supermailverify/`.
2. In WHMCS admin go to **System Settings > Addon Modules**, activate
   *Super Email Verification Pro*. Activation creates four tables:
   `mod_sev_emails`, `mod_sev_email_bans`, `mod_sev_ip_bans`, `mod_sev_domains`.
3. Click **Configure**, set the verification type, code format, ban thresholds,
   mail provider and captcha keys, then grant admin role access.
4. Open the module (Addons > Super Email Verification Pro), go to **Restrictions**
   and click **Update Blacklist from Public List** to import the disposable-domain list.

Requires WHMCS 8.6+ / PHP 8.1+ (uses Capsule, `localAPI`, PHPMailer bundled with WHMCS).

## Verification modes

- **static** — logged-in but unverified clients are redirected to
  `index.php?m=supermailverify` until they enter the code.
- **after** — code is sent after registration; a banner nags the client and checkout
  is blocked until verified.
- **modal** — a full-screen popup appears on every client-area page until verified.

## Feature map

| Feature | Where |
|---|---|
| Code verification, configurable length/charset | Config fields + verify page |
| Gmail dot/plus-trick duplicate detection | `ClientDetailsValidation` hook |
| Mailing list: search, resend, manual verify, unverify, delete, pagination | Admin > Mailing List |
| Email bans, temporary/forever, search, pagination | Admin > Email Bans |
| IP bans, temporary/forever, pagination | Admin > Banned IPs |
| Disposable-domain blacklist, add/delete/update | Admin > Restrictions |
| Verified/unverified charts (today / 30 days / 12 months + totals) | Admin > Statistics |
| Send email to non-clients, CC, attachments, WHMCS signature | Admin > Tools |
| Mail providers: WHMCS SMTP, Postmark, Mailgun, SendGrid, SparkPost | Config + `lib/Functions.php` |
| Ban email after X invalid codes; ban IP after X emails/24h | Config fields |
| Ticket-open and contact-form verification gates | `TicketOpenValidation`, `ContactDetailsValidation` hooks |
| reCAPTCHA v3 / Cloudflare Turnstile | Config + footer injection |
| Reminder, auto-deactivate, auto-close, auto-delete (no services/unpaid invoices) | `DailyCronJob` hook |

The marketplace listing also mentions an Izipay card-token status button; that is
gateway-specific and intentionally not recreated.

## Notes

- The disposable-domain list comes from the public
  `disposable-email-domains/disposable-email-domains` blocklist on GitHub.
- Deactivation preserves all `mod_sev_*` tables so re-activation loses nothing.
  Drop them manually for a full reset.
- Automated actions (reminder/deactivate/close/delete) run on the standard WHMCS
  daily cron; no extra cron entry is needed.
- Admin actions are CSRF-protected with the WHMCS admin token.
