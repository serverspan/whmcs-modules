# ServerSpan ownCloud Storage (provisioning module)

A WHMCS server (provisioning) module developed by [ServerSpan](https://www.serverspan.com)
that sells ownCloud storage as a product: every service gets an ownCloud user with a
quota and optional group, managed through the OCS Provisioning API. Pairs with the
[ServerSpan ownCloud Manager addon](../../addons/ocmanager/) for admin-side user/group
management.

## Directory layout

```
modules/servers/ocstorage/
├── ocstorage.php            # module functions (create/suspend/terminate/password/package)
├── lib/
│   └── OcApi.php            # OCS Provisioning API client (oca_ prefix)
├── overview.tpl             # client service panel (quota usage, status, login link)
└── README.md
```

## Installation

1. Copy the folder to `modules/servers/ocstorage/`.
2. In WHMCS admin go to **System Settings > Servers**, add a server of type
   *ServerSpan ownCloud Storage*:
   - **Hostname**: ownCloud base URL (e.g. `https://cloud.example.com`)
   - **Username / Password**: an ownCloud admin account
   - Tick **Secure** for HTTPS.
3. Create a product with this module; per-product options: quota, group
   (optional), reseller mode, reseller group limit.
4. ownCloud needs the Provisioning API enabled (it is by default).

Requires WHMCS 8.6+ / PHP 7.4+ with curl.

## Behavior

| WHMCS action | ownCloud result |
|---|---|
| Create | User created with quota/email/display name; group created if missing; generated username/password are written back to the service |
| Suspend / Unsuspend | User disabled / enabled |
| Terminate | User deleted (already-deleted counts as success) |
| Change Password | Password updated (one attribute per PUT, per the OCS contract) |
| Change Package | Quota updated; group membership moved when the group option changed |
| Reseller mode | User gets their own `reseller-<user>` group, sub-admin rights over it, and the group limit is recorded for the addon |
| Client area | Quota usage bar, status, ownCloud login link |

## Notes

- ownCloud has no native per-group quota. Reseller group limits are recorded in
  the addon's `mod_oc_grouplimits` table and enforced at provisioning time by
  the product configuration; the original module's ownCloud-side app is not part
  of this package.
- The `oca_` prefix is deliberate: the ocmanager addon loads on every page.
- Empty username/password on the service are allowed — the module generates them
  and stores them back on the service record.

## About

Developed by [ServerSpan](https://www.serverspan.com). If you operate WHMCS for a
hosting business, see [ServerSpan WHMCS-compatible reseller hosting](https://www.serverspan.com/en/webreseller)
and the [ServerSpan DevOps & sysadmin toolbox](https://www.serverspan.com/en/tools/index).
