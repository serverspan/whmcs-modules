# ServerSpan ownCloud Manager (admin addon)

A WHMCS addon module developed by [ServerSpan](https://www.serverspan.com) that recreates
the admin side of the "ownCloud WHMCS Module" with original code: manage ownCloud users,
groups, quotas and reseller group limits across every configured server, without logging
into ownCloud. Pairs with the
[ServerSpan ownCloud Storage provisioning module](../../servers/ocstorage/).

## Directory layout

```
modules/addons/ocmanager/
├── ocmanager.php            # module config, activation, admin area (Users/Groups/Limits/Log)
├── lib/
│   └── Functions.php        # OCS client over WHMCS server records, logging, quota helpers
└── README.md
```

## Installation

1. Copy the folder to `modules/addons/ocmanager/`.
2. Add ownCloud backends under **System Settings > Servers** with module type
   *ServerSpan ownCloud Storage* (hostname = ownCloud URL, username/password =
   ownCloud admin account, Secure for HTTPS).
3. In WHMCS admin go to **System Settings > Addon Modules**, activate
   *ServerSpan ownCloud Manager* and grant admin role access.

Requires WHMCS 8.6+ / PHP 7.4+ with curl, and ownCloud's Provisioning API
(enabled by default).

## Feature map

| Feature | Where |
|---|---|
| List/search users with pagination, per-user manage panel | Users tab |
| Create user with quota, email, groups (groups auto-created) | Users tab |
| Edit quota / email / display name / password (one attribute per PUT) | Users tab > Manage |
| Enable / disable / delete user | Users tab > Manage |
| Group membership add/remove, sub-admin promote/demote | Users tab > Manage |
| Place a WHMCS order while adding a user (optional accept+autosetup) | Users tab > Add form |
| List groups with members and sub-admins, create/delete groups | Groups tab |
| Reseller group limits (per server + group) | Group Limits tab |
| Multi-server: every tab has a server picker, default configurable | All tabs |
| Audit log of all management actions | Log tab |

## Notes

- Credentials come exclusively from WHMCS server records — no duplicate API
  configuration in the addon.
- ownCloud has no native per-group quota. Limits recorded in **Group Limits**
  (`mod_oc_grouplimits`) are applied at provisioning time by the server module's
  reseller mode. The original product enforced limits with a custom ownCloud-side
  app, which is outside the scope of this WHMCS-side package.
- Deactivation preserves all `mod_oc_*` tables.
- Admin actions are CSRF-protected with the WHMCS admin token.

## About

Developed by [ServerSpan](https://www.serverspan.com). If you operate WHMCS for a
hosting business, see [ServerSpan WHMCS-compatible reseller hosting](https://www.serverspan.com/en/webreseller)
and the [ServerSpan DevOps & sysadmin toolbox](https://www.serverspan.com/en/tools/index).
