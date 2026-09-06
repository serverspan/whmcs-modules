# RadiusSphere architecture

RadiusSphere is an independent, clean-room enterprise control plane for FreeRADIUS-backed WHMCS services.

## Components

- `servers/radiussphere`: WHMCS provisioning lifecycle adapter.
- `addons/radiussphere`: operator console, configuration, migrations, cron, audit.
- Control-plane tables: clusters, policies/revisions, identities, service links, commands, audit events.
- Future drivers: FreeRADIUS SQL first; API/agent and CoA/Disconnect adapters later.

## Command model

Lifecycle events are converted into idempotent commands. A driver applies desired state to a cluster. Failed commands retain diagnostics and use retry/backoff. The service module must never expose or log backend secrets.

## Phases

1. Foundation: schema, lifecycle adapter, command outbox, audit model.
2. SQL driver: encrypted cluster credentials, preflight checks, `radcheck`/`radreply`/`radusergroup` materialization, reconciliation.
3. Operator console: cluster/policy CRUD, version diffs, NAS/realm/address resources, command replay.
4. Usage and live operations: accounting ingest, quotas, session explorer, optional CoA/Disconnect.
5. Hardening: RBAC, CSRF-protected forms, exports, retention, test suite, package/release automation.

## Source of truth

RadiusSphere policy and identity records are the desired state. FreeRADIUS SQL tables are driver-managed projections, not administrator-edited source data.
