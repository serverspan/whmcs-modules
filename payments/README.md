# Payment Gateways

WHMCS payment gateway modules maintained in this repository.

| Module | Provider | Version | Status |
|---|---|---:|---|
| [Revolut Gateway](revolut/) | Revolut Merchant | 1.0.0-beta.1 | Beta - sandbox validation required |

Payment code receives extra scrutiny because mistakes can create duplicate charges, incorrect invoice state, leaked credentials, or broken recurring billing. New gateways must follow the security and idempotency requirements in [`../docs/MODULE-STANDARDS.md`](../docs/MODULE-STANDARDS.md).

Running WHMCS for a hosting company? ServerSpan offers [WHMCS-compatible white-label reseller hosting](https://www.serverspan.com/en/webreseller).
