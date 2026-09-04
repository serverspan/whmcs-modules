# ServerSpan PowerDNS Manager (independent recreation)

A WHMCS addon module developed by [ServerSpan](https://www.serverspan.com) that recreates
the feature set of the "PowerDNS Manager" marketplace module with original code:
self-service DNS zone management for client domains on PowerDNS, with record editing,
DNSSEC, zone import/export, zone templates and registration lifecycle automation.
Multi-server aware: zones remember which backend they live on.

## Directory layout

```
modules/addons/pdnsmanager/
├── pdnsmanager.php          # module config, activation, admin area, client area
├── hooks.php                # register/transfer/delete lifecycle, product templates, sidebar
├── lib/
│   └── Functions.php        # PowerDNS API client, server resolver, records, DNSSEC, templates
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
3. Add your PowerDNS backends under **System Settings > Servers** with module type
   *ServerSpan PowerDNS DNS Hosting* (from the companion server module):
   - **Hostname** = API base URL, **Access Hash** = API key,
     **Nameservers 1–4** = the NS records new zones get.
   PowerDNS needs `api=yes` and `api-key=...` in `pdns.conf`; for DNSSEC also
   `gmysql-dnssec=yes`.
4. Multiple servers are supported: each zone stores its server in
   `mod_pdns_zones.server_id`, and every record/DNSSEC/import/export operation is
   routed to that backend. The addon's **Default WHMCS Server ID** setting is used
   for registrar-hook zone creation (registrar events carry no server context);
   service-driven creation uses the service's own server. The admin Zones tab also
   offers a per-zone server picker on manual creation.
5. Choose the zone creation method matching your PowerDNS version:
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
| Zone create/delete, per-zone server display, record view | Admin > Zones |
| Zone templates with variables, TLD/product matching, one batched PATCH | Admin > Templates |
| Zone on registration / transfer, template auto-apply, delete on domain deletion | Registrar hooks |
| Product-matched templates with service/server IP variables | `AfterModuleCreate` hook |
| Nameserver verification via Google/Cloudflare DoH | Client area > Check NS |
| Rectify mode for DNSSEC zones (auto/POST/PUT) | Config field |
| Single credential store via WHMCS server records, multi-server routing | `pdns_resolve_server()` |
| Activity log (zone and record events, actor, IP) | Admin > Log |

Template variables: `{domain}`, `{client.id}`, `{server.ip}`, `{server.hostname}`,
`{service.dedicated_ip}`, `{service.assigned_ip}`. Product matches win over TLD;
among TLD matches the longest suffix wins. Apex SOA/NS entries in templates are
ignored at apply time, and templates apply once per zone (`template_applied` flag).

## Notes

- Zone management in the client area is limited to domains the logged-in client
  owns (`tbldomains`), minus the admin-configured protected domains list.
- Upgrading from 1.0.0: the `server_id` column is added automatically
  (`pdns_ensure_schema`); existing zones fall back to the default server until
  edited. Remove the old API URL/key fields from the addon config after upgrading.
- Deactivation preserves all zones in PowerDNS and all `mod_pdns_*` tables.
- Admin and client actions are CSRF-protected with WHMCS tokens.

## About

Developed by [ServerSpan](https://www.serverspan.com). If you operate WHMCS for a
hosting business, see [ServerSpan WHMCS-compatible reseller hosting](https://www.serverspan.com/en/webreseller)
and the [ServerSpan DevOps & sysadmin toolbox](https://www.serverspan.com/en/tools/index).
