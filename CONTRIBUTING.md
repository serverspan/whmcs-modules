# Contributing

Contributions are welcome when they keep the repository understandable and operationally safe.

## Before opening a pull request

1. Put the module in the correct top-level category: `payments/`, `addons/`, or `servers/`.
2. Use a short lowercase slug for the module directory.
3. Include `README.md`, `CHANGELOG.md`, `VERSION`, and `module.json` for every publishable module.
4. Document supported WHMCS, PHP, and upstream API versions.
5. Add tests for logic that can be exercised without a live WHMCS/provider account.
6. Run `make test` from the repository root.
7. Do not commit credentials, `.env` files, production logs, database exports, vendor secrets, or generated release archives.

## Code expectations

- Prefer boring, explicit PHP over framework-heavy abstractions.
- Do not modify WHMCS core files.
- Escape HTML output and validate external input.
- Use integer minor units for payment amounts when the provider API expects them.
- Webhooks and callbacks must be authenticated when the provider supports authentication.
- Repeated callbacks must be idempotent.
- HTTP clients must verify TLS certificates.
- Never intentionally log API keys, passwords, card data, CVV, or full payment tokens.
- Timeouts and error handling must be explicit.

See [`docs/MODULE-STANDARDS.md`](docs/MODULE-STANDARDS.md) for the full checklist.

## Commit style

Use short imperative Conventional Commit-style subjects where practical:

```text
feat(revolut): add webhook reconciliation
fix(revolut): prevent duplicate invoice credit
chore(ci): test PHP 8.3
```

## Security reports

Do not disclose vulnerabilities in a public issue. Follow [`SECURITY.md`](SECURITY.md).
