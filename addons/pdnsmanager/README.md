# ServerSpan PowerDNS Manager (independent recreation)

A WHMCS addon module developed by [ServerSpan](https://www.serverspan.com) that recreates
the feature set of the "PowerDNS Manager" marketplace module with original code:
self-service DNS zone management for client domains on a PowerDNS authoritative server,
with record editing, DNSSEC, zone import/export, zone templates and registration
lifecycle automation.

## Directory layout

```
modules/addons/pdnsmanager/
├── pdnsmanager.php          # module config, activation, admin area, client area
├── hooks.php                # register/transfer/delete lifecycle, product templates, sidebar
├── lib/
│   └── Functions.php        # PowerDNS API client, records, DNSSEC, zonefile parser, templates
├── templates/
│   ├── zones.tpl            # client-area zone list (with NS check)
│   └── records.tpl          # client-area record editor, import/export, DNSSEC
└── lang/
    ├── english.php
    └── romanian.php
```

## Installation

1. Copy the folder to `modules/addons/pdnsmanager/`.
2. In WHMCS admin go to **System Settings > Addon Modules**, activate
   *ServerSpan PowerDNS Manager*. Activation creates four tables:
   `mod_pdns_zones`, `mod_pdns_templates`, `mod_pdns_assignments`, `mod_pdns_log`.
3. Configure the PowerDNS API URL, API key, server ID and your nameservers
   (up to 5). PowerDNS needs `api=yes` and `api-key=...` in `pdns.conf`;
   for DNSSEC also set `gmysql-dnssec=yes`.
4. Choose the zone creation method matching your PowerDNS version:
   `rrsets` for 4.3+, `nameservers` for 4.2 and older (fixes the
   "Nameservers list must be given" error).

Requires WHMCS 8.9+ / PHP 7.4+ with curl.

## Feature map

| Feature | Where |
|---|---|
| Record editor: A, AAAA, CNAME, MX, TXT, SRV, CAA, NS, TLSA, PTR | Client area > records |
| Multi-value-safe editing (whole set replaced per name+type) | `pdns_save_rrset()` |
| Protected SOA (read-only everywhere) and apex NS (admin-only) | Editor + save guard |
| DNSSEC one-click enable/disable, click-to-check status, DS records | Client area + cryptokeys API |
| BIND/RFC1035 zone import (multi-line DKIM-safe) | Client area > Import |
| Zone export as standard zone file | Client area > Export |
| Zone create/delete, client list, record view | Admin > Zones |
| Zone templates with variables, TLD/product matching, one batched PATCH | Admin > Templates |
| Zone on registration / transfer, template auto-apply, delete on domain deletion | Registrar hooks |
| Product-matched templates with service/server IP variables | `AfterModuleCreate` hook |
| Nameserver verification via Google/Cloudflare DoH | Client area > Check NS |
| Rectify mode for DNSSEC zones (auto/POST/PUT) | Config field |
| Activity log (zone and record events, actor, IP) | Admin > Log |

Template variables: `{domain}`, `{client.id}`, `{server.ip}`, `{server.hostname}`,
`{service.dedicated_ip}`, `{service.assigned_ip}`. Product matches win over TLD;
among TLD matches the longest suffix wins. Apex SOA/NS entries in templates are
ignored at apply time, and templates apply once per zone (`template_applied` flag).

## Notes

- Zone management in the client area is limited to domains the logged-in client
  owns (`tbldomains`), minus the admin-configured protected domains list.
- The original module's dual-mode server (provisioning) variant is not recreated
  here; this package is the addon module.
- Deactivation preserves all zones in PowerDNS and all `mod_pdns_*` tables.
- Admin and client actions are CSRF-protected with WHMCS tokens.

## About

Developed by [ServerSpan](https://www.serverspan.com). If you operate WHMCS for a
hosting business, see [ServerSpan WHMCS-compatible reseller hosting](https://www.serverspan.com/en/webreseller)
and the [ServerSpan DevOps & sysadmin toolbox](https://www.serverspan.com/en/tools/index).
