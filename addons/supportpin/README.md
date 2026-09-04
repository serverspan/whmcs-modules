# ServerSpan Support PIN for WHMCS

Client-generated support PINs for verifying account ownership before staff disclose or change sensitive account information.

**Version:** 1.0.0  
**Maturity:** Beta  
**Requires:** PHP 8.1+, WHMCS 8.6+  
**Install path:** `modules/addons/supportpin/`

## What it does

- Client PIN generation and AJAX regeneration.
- Configurable PIN length from 4 to 8 digits.
- Optional expiration and one-time-use behavior.
- Administrator verification page.
- WHMCS administrator dashboard widget.
- Optional staff restriction: client profile access requires a recent successful PIN verification.
- Temporary staff access grants with configurable duration and exempt admin roles.
- Failed-verification rate limiting per administrator.
- Audit logging for PIN generation, verification and grants.
- Daily cleanup of expired PINs, stale grants and old audit entries.

## Installation

1. Build the install archive with `make package-supportpin`, or copy this source directory to `modules/addons/supportpin/`.
2. In WHMCS go to **System Settings > Addon Modules** and activate **ServerSpan Support PIN**.
3. Grant the required administrator roles access.
4. Configure PIN length, expiration, one-time behavior and optional staff restrictions.
5. Optionally add the **Support PIN Verification** widget to the WHMCS administrator dashboard.

## How verification works

The client opens **Support PIN** in the client area and generates a PIN. Regenerating immediately invalidates the previous PIN.

Staff enter the PIN in the addon page or dashboard widget. A valid match identifies the WHMCS client and, when staff restrictions are enabled, creates a time-limited access grant for that administrator.

The PIN is not stored as plaintext for lookup. The module keeps encrypted material plus a salted lookup hash; audit records retain only limited PIN information rather than the full secret.

## Database changes

Activation creates:

```text
mod_pin_pins
mod_pin_grants
mod_pin_log
```

Deactivation preserves these tables so existing PIN/audit state is not silently destroyed. Remove them manually only for a deliberate full uninstall.

## Hooks and cron

- Client-area navigation exposes the Support PIN page.
- Admin dashboard hooks expose the verification widget.
- Optional administrator-area hooks restrict client profile access until a valid grant exists.
- The daily cron removes expired PINs, expired grants and audit records older than the retention window.

## Security notes

- Treat Support PIN as an additional identity-verification factor for support workflows, not as a replacement for WHMCS authentication or MFA.
- Full Administrator is exempt by default from the optional staff restriction; review `Exempt Admin Role IDs` for your organization.
- State-changing client/admin forms use WHMCS CSRF protection.
- Failed verification attempts are rate-limited per administrator when configured.
- Do not ask clients to send a Support PIN in long-lived public channels or tickets if your policy treats the PIN as a short-lived support secret.

## Feature map

| Feature | Location |
|---|---|
| Generate/regenerate PIN | Client area |
| PIN length/expiry/one-time policy | Addon configuration |
| Verify PIN | Addon admin page + dashboard widget |
| Temporary staff grants | `mod_pin_grants` + administrator hooks |
| Staff restriction | Administrator-area hook |
| Rate limiting | Verification workflow |
| Audit history | Addon admin log |
| Cleanup | WHMCS daily cron |

## Validation status

The source is included in repository-wide PHP linting and CI. A dedicated behavioral self-test suite has not yet been added. Validate administrator role exemptions and the profile-restriction workflow in staging before enabling staff restrictions in production.

## Repository metadata

- [`VERSION`](VERSION)
- [`module.json`](module.json)
- [`CHANGELOG.md`](CHANGELOG.md)
- [Repository module catalog](../../docs/MODULE-CATALOG.md)

## ServerSpan

Developed and maintained by [ServerSpan](https://www.serverspan.com/).

For WHMCS hosting operations, see [ServerSpan reseller hosting](https://www.serverspan.com/en/webreseller), [KVM/LXC VPS](https://www.serverspan.com/en/virtual-servers) and the [DevOps/sysadmin toolbox](https://www.serverspan.com/en/tools/index).
