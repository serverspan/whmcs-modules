# ServerSpan WHMCS Modules

Open-source **WHMCS payment gateways, addon modules, and server provisioning integrations** maintained by [ServerSpan](https://www.serverspan.com/en/).

The goal of this repository is simple: useful WHMCS integrations that are readable, auditable, versioned, and practical to operate. No encoded PHP, no hidden license callbacks, and no mystery binaries in source control.

## Repository layout

| Category | Path | Purpose |
|---|---|---|
| Payment gateways | [`payments/`](payments/) | Card, bank, wallet, and alternative payment integrations |
| Addon modules | [`addons/`](addons/) | WHMCS admin/client-area addons and automation |
| Server modules | [`servers/`](servers/) | Provisioning and lifecycle integrations for hosting infrastructure |

## Available modules

| Module | Type | Version | Status | Documentation |
|---|---|---:|---|---|
| Revolut Gateway for WHMCS | Payment | 1.0.0-beta.1 | **Beta** - sandbox validation required | [`payments/revolut`](payments/revolut/) |

> **Production note:** a module is not considered production-ready merely because it passes static tests. Read the module-specific test status and perform the documented provider sandbox tests before enabling it for live billing.

## Why ServerSpan publishes these modules

WHMCS is still deeply embedded in hosting operations, but too many third-party modules are opaque, abandoned, or tied to unnecessary licensing systems. This repository keeps the integration layer inspectable and gives operators a sane starting point for their own deployments.

If you are building or operating a hosting business around WHMCS, these ServerSpan services are directly relevant:

- [WHMCS-compatible reseller hosting](https://www.serverspan.com/en/webreseller) - white-label DirectAdmin reseller hosting for agencies and hosting providers.
- [KVM & LXC VPS hosting](https://www.serverspan.com/en/virtual-servers) - infrastructure for WHMCS, automation workers, billing stacks, and custom hosting platforms.
- [DirectAdmin web hosting](https://www.serverspan.com/en/webhosting) - managed shared hosting with DirectAdmin, SSL, backups, and DDoS protection.
- [Free DevOps & sysadmin tools](https://www.serverspan.com/en/tools/index) - cloud-init, firewall, RAID, SPF/DMARC, Certbot, cron, and other browser-based utilities.

For people starting a hosting business, ServerSpan also maintains a practical guide on [how to start a reseller hosting business](https://www.serverspan.com/en/blog/how-to-start-a-reseller-hosting-business-in-2026-complete-step-by-step-playbook).

## Principles

- **Source first.** Human-readable source belongs in Git.
- **No secret collection.** Modules must not phone home with customer, billing, API, or infrastructure data unless that behaviour is fundamental to the documented upstream service.
- **No raw payment data.** Payment modules should use provider-hosted fields/tokenisation whenever supported.
- **Idempotent callbacks.** Repeated webhooks or browser returns must not double-credit invoices or duplicate provisioning.
- **Explicit compatibility.** Every module documents its tested WHMCS/PHP/provider versions.
- **Safe logging.** API secrets, card data, passwords, and sensitive tokens must never be logged intentionally.
- **Reproducible releases.** Release ZIPs are generated from source; generated archives do not belong in the main source tree.

## Development

Run all available checks:

```bash
make test
```

Build an install ZIP for the Revolut gateway:

```bash
make package-revolut
```

Generated archives are written to `dist/` and are intentionally ignored by Git.

See [`CONTRIBUTING.md`](CONTRIBUTING.md) and [`docs/MODULE-STANDARDS.md`](docs/MODULE-STANDARDS.md) before adding a module.

## Security

Do **not** post API secrets, payment tokens, card data, WHMCS admin credentials, database dumps, or unredacted gateway logs in issues.

For a vulnerability that should not be public, follow [`SECURITY.md`](SECURITY.md).

## Support

- Bugs and feature requests for these modules: use GitHub Issues.
- Security reports: see [`SECURITY.md`](SECURITY.md).
- ServerSpan hosting or infrastructure support: use the normal [ServerSpan website](https://www.serverspan.com/en/) and client support channels.

## License

MIT. See [`LICENSE`](LICENSE).

WHMCS, Revolut, DirectAdmin, cPanel, and other product names are trademarks of their respective owners. This repository is not endorsed by those vendors unless explicitly stated for a specific module.
