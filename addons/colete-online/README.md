# ServerSpan Colete Online for WHMCS

Native WHMCS admin integration for the [Colete-Online.ro API](https://docs.api.colete-online.ro/): compare courier prices, create courier orders and AWBs from WHMCS orders, download labels, and inspect shipment tracking history.

> **Status: Beta (`1.0.0-beta.1`).** The request/response model is covered by local tests and public API documentation, but production release requires a real Colete-Online staging account and a representative WHMCS 8.13/9.x installation.

## What it does

- Uses Colete-Online OAuth2 client-credentials authentication and caches the short-lived access token.
- Reads recent WHMCS orders and pre-fills the recipient from the order's client/contact.
- Loads saved sender addresses from Colete-Online.
- Compares live courier prices through `POST /order/price`.
- Creates the selected courier shipment through `POST /order`.
- Stores Colete-Online `uniqueId`, AWB, selected courier/service, price and tracking metadata.
- Downloads the authenticated AWB/label through an admin-only WHMCS bridge.
- Refreshes shipment history through `GET /order/status/{uniqueId}`.
- Supports multiple parcels, envelope/box type, COD/repayment, insurance, declared value, open-at-delivery, Saturday delivery, scheduled pickup and client references.
- Supports fixed-point/locker IDs for sender/recipient when a compatible Colete-Online service is selected.
- Automatically adds Colete-Online's base-currency display option for non-RON WHMCS orders.

The module is **administrator-operated**. It does not turn WHMCS checkout into an ecommerce shipping calculator. WHMCS is primarily a billing/service platform, so automatically treating every hosting/domain order as a physical shipment would be the wrong abstraction.

## Install

Extract the install ZIP into the WHMCS root. The result is:

```text
modules/
└── addons/
    └── serverspancoleteonline/
        ├── serverspancoleteonline.php
        ├── awb.php
        ├── lib/
        └── lang/
```

Then:

1. Go to **Configuration > System Settings > Addon Modules**.
2. Activate **ServerSpan Colete Online**.
3. Configure the administrator roles allowed to access the addon.
4. Enter the API Client ID and Client Secret generated in Colete-Online.
5. Keep **Staging Mode** enabled for initial testing.
6. Optionally set the default saved sender address/location ID and package dimensions.
7. Open **Addons > ServerSpan Colete Online** and run **API diagnostics**.

## API environment

The module uses the endpoints documented by Colete-Online:

```text
Authentication: https://auth.colete-online.ro/token
Production:     https://api.colete-online.ro/v1/
Staging:        https://api.colete-online.ro/v1/staging/
```

The authentication token is normally valid for roughly two hours. Colete-Online explicitly recommends caching it rather than requesting a token for every API call. This module follows that behaviour and encrypts the cached bearer token using WHMCS's own EncryptPassword/DecryptPassword service.

## Shipment workflow

From the module dashboard choose a WHMCS order and click **Ship**.

1. Select a saved Colete-Online sender.
2. Review/fix the recipient address imported from WHMCS.
3. Enter parcel weight and dimensions. More parcel rows can be added.
4. Configure optional courier services.
5. Click **Get live courier offers**.
6. Select the desired service.
7. Click **Create shipment / AWB**.
8. Download the AWB from the shipment page and refresh tracking when needed.

The module refuses to silently create a second shipment for the same WHMCS order. Additional shipments require an explicit confirmation checkbox.

## Address handling

WHMCS stores a general `address1` string, while courier APIs often want street and street number separately. The module attempts a conservative split when the WHMCS address ends in a number, but the administrator can correct all recipient fields before quoting or creating the shipment.

For Romanian addresses, use the county/county code expected by your Colete-Online account and the API's validation strategy. The module exposes `minimal` and `priceMinimal`, which are the currently documented/client-library strategies.

## Extra options

The integration currently supports the public Colete-Online option IDs used by the API ecosystem:

| Option | Behaviour |
|---|---|
| Open at delivery | Restrict/select compatible services |
| Saturday delivery | Optional or mandatory Saturday-capable service |
| Insurance | Insured value |
| Account repayment | COD/repayment to a bank account |
| Cash repayment | COD returned as cash/envelope where supported |
| Declared value | Declared value for eligible shipments |
| Scheduled pickup | Date and optional time interval |
| Client reference | Defaults to the WHMCS order number |
| Base currency | Added automatically when the WHMCS client currency is not RON |

Colete-Online's API rules make **declared value mutually exclusive with insurance/repayment options**. The module validates that locally before sending the request.

## Database tables

Activation creates:

- `mod_serverspan_coleteonline_shipments` - non-secret shipment/AWB/tracking metadata.
- `mod_serverspan_coleteonline_cache` - encrypted short-lived OAuth token cache.

The module deliberately does **not** duplicate recipient names, addresses, phone numbers or emails into its shipment table.

On deactivation the OAuth cache table is removed. Shipment history is retained deliberately so an accidental deactivate/reactivate does not destroy AWB references. To permanently purge module history after uninstalling:

```sql
DROP TABLE IF EXISTS mod_serverspan_coleteonline_shipments;
DROP TABLE IF EXISTS mod_serverspan_coleteonline_cache;
```

## Security and privacy

- API Client Secret uses a WHMCS `password` addon configuration field. At-rest protection of addon settings is controlled by the installed WHMCS version; WHMCS 9 exposes encrypted addon-setting support, while older installations should be reviewed according to their own security model.
- Access tokens are encrypted before being cached.
- AWB downloads require a logged-in WHMCS administrator whose role has access to this addon.
- API request bodies, bearer tokens and credentials are never written to Module Log by this module.
- Optional debug logging records only method, API path and HTTP status.
- Quote/create operations necessarily transmit the recipient contact/address and package information to Colete-Online.
- AWB responses are proxied directly to the authorized admin and are not stored as files by the module.

## Known beta limitations

- No automatic shipment generation hook. This is intentional until operators explicitly choose which WHMCS orders represent physical goods.
- Shipping-point/locker IDs can be supplied, but v1 does not yet include the full interactive locker map/browser.
- Colete-Online exposes a status-change notification extra option, but the public documentation does not define a stable callback payload contract clearly enough for this release. Tracking is refreshed explicitly through the documented status endpoint instead.
- No cancellation action is implemented because the public v1 documentation does not expose a shipment-cancellation endpoint.
- No customer-area shipment widget yet.

## Validation before production

Test at minimum in Colete-Online staging:

1. OAuth authentication and token reuse.
2. Saved sender address list.
3. Romanian domestic quote.
4. Multiple courier offers and correct RON prices.
5. Shipment creation with the selected `directId` service.
6. AWB PDF download.
7. Tracking history refresh.
8. Multi-parcel shipment.
9. COD/repayment and insurance separately.
10. A non-RON WHMCS client/order if used in your installation.
11. A locker/fixed-point service if you use shipping points.
12. Re-authentication after token expiration/401.

Only remove the Beta label after those tests succeed against your own account.

## Development

```bash
php addons/colete-online/tests/selftest.php
make test
make package-colete-online
```

## Relevant ServerSpan services

This module is maintained in the [ServerSpan WHMCS Modules](https://github.com/serverspan/whmcs-modules) collection. If the WHMCS installation or its automation workers need infrastructure, see [ServerSpan VPS hosting](https://www.serverspan.com/en/virtual-servers), [WHMCS-compatible reseller hosting](https://www.serverspan.com/en/webreseller), and the [ServerSpan sysadmin toolbox](https://www.serverspan.com/en/tools/index).

## References

- [Colete-Online API V1](https://docs.api.colete-online.ro/)
- [WHMCS Addon Modules](https://developers.whmcs.com/addon-modules/)
- [WHMCS Database / Capsule](https://developers.whmcs.com/advanced/db-interaction/)

Colete-Online and WHMCS are trademarks of their respective owners. This open-source integration is not an endorsement or official module unless explicitly stated by those vendors.
