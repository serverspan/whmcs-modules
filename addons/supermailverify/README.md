# ServerSpan Super Email Verification for WHMCS

Email-verification and anti-abuse controls for WHMCS registration, checkout, support and contact workflows.

**Version:** 1.0.0  
**Maturity:** Beta  
**Requires:** PHP 8.1+, WHMCS 8.6+  
**Install path:** `modules/addons/supermailverify/`

## What it does

- Configurable verification codes and three verification UX modes.
- Gmail dot/plus-address duplicate normalization.
- Disposable-domain blocking with an updateable public blocklist.
- Email and IP ban lists with temporary/permanent controls.
- Registration, checkout, ticket and contact-form verification gates.
- reCAPTCHA v3 and Cloudflare Turnstile support.
- Verification statistics and searchable admin lists.
- Reminder/deactivation/closure/deletion automation on the WHMCS daily cron.
- Outbound mail through WHMCS SMTP, Postmark, Mailgun, SendGrid or SparkPost.

## Installation

1. Build the install archive from this repository with `make package-supermailverify`, or copy this source directory to `modules/addons/supermailverify/`.
2. In WHMCS go to **System Settings > Addon Modules** and activate **Super Email Verification Pro**.
3. Grant the desired administrator roles access to the addon.
4. Configure verification mode, code policy, ban thresholds, mail provider and optional captcha keys.
5. In the addon admin area, optionally update the disposable-domain blacklist from its public source.

## Database changes

Activation creates:

```text
mod_sev_emails
mod_sev_email_bans
mod_sev_ip_bans
mod_sev_domains
```

Deactivation intentionally preserves these tables. Remove them manually only when performing a full uninstall and after confirming the stored verification/ban history is no longer required.

## Verification modes

- **static** - an unverified logged-in client is sent to the verification page until the code is accepted.
- **after** - verification happens after registration; the client sees reminders and checkout is blocked until verified.
- **modal** - verification is presented as a blocking client-area modal.

## Automation

The standard WHMCS `DailyCronJob` hook can perform configured reminder, deactivation, account-closure and deletion actions. Destructive automation is disabled unless its corresponding age threshold is configured.

Before enabling automatic account closure/deletion in production, validate the rules against a staging WHMCS database and confirm the safeguards around active services and unpaid invoices match your policy.

## External services

Depending on configuration, the addon can contact:

- the configured transactional mail provider;
- Google reCAPTCHA v3;
- Cloudflare Turnstile;
- the public disposable-email-domain list source when an administrator explicitly updates the list.

It does not send customer data or credentials to ServerSpan.

## Security notes

- State-changing administrator actions use WHMCS CSRF protection.
- Captcha secret keys and mail-provider credentials should be treated as production secrets.
- Do not publish unredacted module logs or configuration screenshots containing provider credentials.
- Verification is an anti-abuse control, not a substitute for MFA or administrator authentication.

## Feature map

| Feature | Location |
|---|---|
| Code generation and verification | Addon configuration + client verification page |
| Gmail duplicate normalization | Client validation hook |
| Email/IP bans | Addon admin area + validation hooks |
| Disposable domains | Restrictions admin area |
| Verification statistics | Statistics admin area |
| Mail provider selection | Addon configuration |
| Ticket/contact gates | WHMCS validation hooks |
| Captcha | Configuration + client-area hook output |
| Reminder/deactivation/cleanup | WHMCS daily cron hook |

## Validation status

The source is included in repository-wide PHP linting and CI. A dedicated behavioral self-test suite has not yet been added. Validate registration, verification, checkout gating, ticket/contact gating and any destructive cron policy in a non-production WHMCS installation before enforcing it globally.

## Repository metadata

- [`VERSION`](VERSION)
- [`module.json`](module.json)
- [`CHANGELOG.md`](CHANGELOG.md)
- [Repository module catalog](../../docs/MODULE-CATALOG.md)

## ServerSpan

Developed and maintained by [ServerSpan](https://www.serverspan.com/).

For WHMCS hosting operations, see [ServerSpan reseller hosting](https://www.serverspan.com/en/webreseller), [KVM/LXC VPS](https://www.serverspan.com/en/virtual-servers) and the [DevOps/sysadmin toolbox](https://www.serverspan.com/en/tools/index).
