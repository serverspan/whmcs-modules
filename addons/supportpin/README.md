# ServerSpan Support PIN (independent recreation)

A WHMCS addon module developed by [ServerSpan](https://www.serverspan.com) that recreates
the feature set of the "Support Pin" marketplace module with original code. Clients
generate a security PIN in the client area; staff verify it over phone, live chat or any
other channel before giving support — protecting accounts against social engineering.

## Directory layout

```
modules/addons/supportpin/
├── supportpin.php           # module config, activation, admin area, client area
├── hooks.php                # dashboard widget, staff restriction, sidebar, cron cleanup
├── lib/
│   └── Functions.php        # settings, PIN issue/verify, access grants, audit log
├── templates/
│   └── pin.tpl              # client-area PIN page (AJAX regeneration)
└── lang/
    ├── english.php
    └── romanian.php
```

## Installation

1. Copy the folder to `modules/addons/supportpin/`.
2. In WHMCS admin go to **System Settings > Addon Modules**, activate
   *ServerSpan Support PIN*. Activation creates three tables:
   `mod_pin_pins`, `mod_pin_grants`, `mod_pin_log`.
3. Click **Configure** to set PIN length, expiry, one-time use and the optional
   staff access restriction, then grant admin role access.
4. Optionally add the **Support PIN Verification** widget to the admin dashboard
   (Add/Remove Widgets on the dashboard).

Requires WHMCS 8.6+ / PHP 8.1+.

## How it works

- The client opens **Support PIN** in the client area (also linked in the primary
  sidebar) and clicks Generate. The PIN is shown immediately and can be regenerated
  at any time via AJAX; regenerating invalidates the old PIN instantly.
- Staff enter the PIN on the module page (**Addons > ServerSpan Support PIN**) or in
  the dashboard widget. A match shows the client's name and email with a direct link
  to the client profile.
- PINs are stored encrypted (WHMCS `encrypt()`) plus a salted SHA-256 lookup hash.
  Only the last two digits are kept in the audit log.

## Feature map

| Feature | Where |
|---|---|
| Client PIN generation, AJAX regeneration | Client area page + sidebar link |
| Configurable PIN length (4–8 digits) | Config field |
| Expiry after X hours (0 = never) | Config field + cleanup cron |
| One-time PIN (invalid after first use) | Config field |
| Admin verification page | Addons > ServerSpan Support PIN |
| Admin dashboard widget | `AdminHomeWidgets` hook |
| Staff restriction: client profiles locked until PIN verified | Config + `AdminAreaPage` hook |
| Temporary access grants (configurable minutes, exempt admin roles) | `mod_pin_grants` |
| Failed-attempt rate limiting per admin | Config field |
| Audit log of generations, verifications and grants | Admin > Log tab |
| Works for contacts / sub-accounts (per WHMCS user) | `tblusers` based |

## Notes

- The staff restriction covers the `clients*.php` profile screens. Admins whose role
  ID is listed under **Exempt Admin Role IDs** (default: `1`, Full Administrator)
  bypass it.
- The daily cron removes expired PINs, stale grants and log entries older than 90 days.
- Deactivation preserves all `mod_pin_*` tables; drop them manually for a full reset.
- Admin actions are CSRF-protected with the WHMCS admin token.

## About

Developed by [ServerSpan](https://www.serverspan.com). If you operate WHMCS for a
hosting business, see [ServerSpan WHMCS-compatible reseller hosting](https://www.serverspan.com/en/webreseller)
and the [ServerSpan DevOps & sysadmin toolbox](https://www.serverspan.com/en/tools/index).
