# Addon Modules

WHMCS addon modules, admin/client-area extensions, operational utilities, and billing automation live here.

## Available modules

| Module | Version | Status | Description |
|---|---:|---|---|
| [ServerSpan Sales Tracker](sales-tracker/) | 1.0.0-beta.1 | Beta | Sales dashboards, product rankings, sales-agent comparison, self-vs-agent attribution, and custom date filters. |
| [ServerSpan Colete Online](colete-online/) | 1.0.0-beta.1 | Beta | Colete-Online courier quotes, shipment/AWB creation, label download, COD/options and tracking from WHMCS orders. |
| [ServerSpan Super Email Verification](supermailverify/) | 1.0.0 | Beta | Email verification codes with disposable-domain blocking, Gmail dot/plus-trick duplicate detection, email/IP ban lists, verified/unverified statistics, outbound mail via WHMCS SMTP, Postmark, Mailgun, SendGrid or SparkPost, reCAPTCHA v3/Turnstile, and cron-based reminders and account cleanup. |
| [ServerSpan Support PIN](supportpin/) | 1.0.0 | Beta | Client-generated security PINs for identity verification over any channel, with configurable length, one-time and expiring PIN options, admin verification page and dashboard widget, temporary staff access grants to client profiles, rate limiting, and audit logging. |
| [ServerSpan Identity Verification (Didit)](diditkyc/) | 1.0.0 | Beta | Hosted Didit KYC sessions (ID document, liveness, face match, AML) with HMAC-signed webhook status updates, admin session tracking and audit log, checkout gating until approved, client-group assignment on approval, and cron reconciliation of stale sessions. |
| [ServerSpan Identity Verification (Stripe)](stripekyc/) | 1.0.0 | Beta | Stripe Identity hosted verification sessions (ID authenticity, optional selfie match and live capture) with signed webhook updates, open-session reuse, admin session tracking, GDPR redaction, checkout gating until verified, and client-group assignment on verification. |
| [ServerSpan PowerDNS Manager](pdnsmanager/) | 1.1.0 | Beta | Self-service DNS zone management for client domains on PowerDNS: multi-value-safe record editor (A/AAAA/CNAME/MX/TXT/SRV/CAA/NS/TLSA/PTR), protected SOA/apex-NS, one-click DNSSEC with DS display, BIND zone import/export, zone templates with TLD/product matching, register/transfer/delete lifecycle automation, multi-server routing via WHMCS server records, and DoH nameserver checks. |
| [ServerSpan QuickBooks Sync](qbosync/) | 1.1.0 | Beta | Sync clients, invoices, payments and refunds into QuickBooks Online with OAuth2 (race-safe token refresh), requestid-idempotent creates, dual-level tax mapping, gateway/account assignment, queued retries with 429 backoff and full audit log. |
| [ServerSpan ownCloud Manager](ocmanager/) | 1.0.0 | Beta | Admin-side ownCloud management across all configured servers: users (create/edit/quota/enable/disable/delete, optional WHMCS order on create), groups, sub-admins, reseller group limits, and audit log via the OCS Provisioning API. |
| [ServerSpan EuPlatesc Manager](epmanager/) | 1.0.0 | Beta | Backoffice for the EuPlătesc gateway: capture, partial capture, refund, reversal, recurring cancel and invoice-id update actions, settlement reporting with drill-down, saved-card management, card art, MID check, and IPN log. |

Modules in this directory are self-contained, document every database/schema change, provide a safe uninstall path where practical, and avoid modifying WHMCS core files.

If you operate WHMCS for a hosting business, see [ServerSpan WHMCS-compatible reseller hosting](https://www.serverspan.com/en/webreseller) and the [ServerSpan DevOps & sysadmin toolbox](https://www.serverspan.com/en/tools/index).
