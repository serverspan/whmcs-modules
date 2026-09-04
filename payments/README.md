# WHMCS Payment Gateways

Open-source payment gateway integrations maintained by ServerSpan.

## Available gateways

| Module | Provider | Version | Status | Capabilities |
|---|---|---:|---|---|
| [Revolut Gateway](revolut/) | Revolut Merchant | 1.0.0-beta.1 | Beta | Embedded card field, tokenised payment methods, recurring MIT charges, refunds, Revolut Pay and verified webhooks. |

## Packaging

From the repository root:

```bash
make package-revolut
```

The generated ZIP mirrors the WHMCS root and is written to `dist/` with a SHA-256 checksum when `sha256sum` is available.

Payment code receives additional scrutiny because bugs can create duplicate charges, incorrect invoice state, leaked credentials or broken recurring billing. All payment modules must follow the requirements in [`../docs/MODULE-STANDARDS.md`](../docs/MODULE-STANDARDS.md).

Running WHMCS for a hosting company? ServerSpan provides [WHMCS-compatible white-label reseller hosting](https://www.serverspan.com/en/webreseller) and [KVM/LXC VPS infrastructure](https://www.serverspan.com/en/virtual-servers).
