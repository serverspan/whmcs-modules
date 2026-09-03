# Security Policy

## Supported code

Security fixes are applied to the latest maintained release of each module and to the current `main` branch. Older versions may require upgrading before a fix can be applied.

## Reporting a vulnerability

Please do **not** create a public GitHub issue for a vulnerability that could expose credentials, payment information, customer data, WHMCS access, or infrastructure control.

Send a private report to **contact@serverspan.com** with the subject prefix:

```text
[SECURITY][whmcs-modules]
```

Include:

- affected module and version;
- WHMCS and PHP versions;
- a concise description of the issue;
- reproduction steps or a minimal proof of concept;
- expected impact;
- suggested remediation, if known.

Never send real card data, CVV values, unredacted API keys, or customer passwords.

## Scope

Security reports should concern code in this repository. General ServerSpan hosting abuse reports should use the appropriate ServerSpan support/abuse channels instead.
