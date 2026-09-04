# Changelog

## 1.0.0-beta.1 - 2026-09-04

Initial clean-room release.

- Unlimited LogicBoxes-compatible reseller accounts.
- Encrypted API-key storage using WHMCS encryption APIs.
- Account connectivity and funds-threshold checks.
- Customer import/export and optional event-driven customer synchronization.
- Domain discovery/import with persistent LogicBoxes order mappings.
- TLD selling-price previews and application through WHMCS `CreateOrUpdateTLD`.
- Existing-domain recurring-price preview/application through `UpdateClientDomain`.
- Registrar promotion discovery/cache with explicit preview data.
- RAA/verification status discovery and transfer monitoring primitives.
- Domain/customer move planning with guarded server-side operations.
- Durable jobs, per-item before/after snapshots, audit log, and resumable batch state.
- Daily automation hook with lock protection and conservative batch limits.
- No license callbacks, encoded PHP, remote code loading, telemetry, or customer-password synchronization.
