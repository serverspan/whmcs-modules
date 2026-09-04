# ServerSpan PowerDNS DNS Hosting (provisioning module)

A WHMCS server (provisioning) module developed by [ServerSpan](https://www.serverspan.com)
that sells DNS hosting as a product: every service gets its own zone on a PowerDNS
authoritative server. Pairs with the
[ServerSpan PowerDNS Manager addon](../../addons/pdnsmanager/) for client-facing record
management, DNSSEC and zone templates.

## Directory layout

```
modules/servers/pdnshosting/
├── pdnshosting.php          # module functions (create/terminate/suspend/test/client area)
├── lib/
│   └── Psrv.php             # PowerDNS API client and zone/template helpers (psrv_ prefix)
├── overview.tpl             # service detail panel linking to the DNS manager
└── README.md
```

## Installation

1. Copy the folder to `modules/servers/pdnshosting/`.
2. In WHMCS admin go to **System Settings > Servers**, add a server of type
   *ServerSpan PowerDNS DNS Hosting*:
   - **Hostname**: the PowerDNS API base URL (e.g. `https://dns-master.example.com`)
   - **Access Hash**: the PowerDNS API key
   - **Nameservers 1–4**: the NS records new zones get
3. Create a product with this module. Module settings per product:
   zone template ID (from the addon, optional), zone type (Native/Master),
   creation method (`rrsets` for PDNS 4.3+, `nameservers` for legacy),
   rectify mode, and the PowerDNS server ID (default `localhost`).
4. Install the pdnsmanager addon alongside it for the client record editor.

Requires WHMCS 8.6+ / PHP 7.4+ with curl.

## Behavior

| WHMCS action | Result |
|---|---|
| Create | Zone created for the service domain; configured template applied in one batched PATCH |
| Terminate | Zone deleted from PowerDNS |
| Suspend / Unsuspend | No-op success — PowerDNS has no zone-suspension concept (documented; override in `pdnshosting_SuspendAccount` if you want NS/A replacement) |
| Test Connection | Fetches the server object via the API |
| Client area | Panel with a Manage DNS Records link into the addon |

## Notes

- Credentials live on the WHMCS server record (hostname = API URL, access hash =
  API key), not in product settings, so multiple products can share one server.
- The `psrv_` function prefix is deliberate: the pdnsmanager addon's files load on
  every page, and shared prefixes would fatal with "cannot redeclare function".
- Template application reuses the addon's `mod_pdns_templates` table when present
  and silently skips when the addon is absent.

## About

Developed by [ServerSpan](https://www.serverspan.com). If you operate WHMCS for a
hosting business, see [ServerSpan WHMCS-compatible reseller hosting](https://www.serverspan.com/en/webreseller)
and the [ServerSpan DevOps & sysadmin toolbox](https://www.serverspan.com/en/tools/index).
