# WHMCS Server Provisioning Modules

This category is reserved for true WHMCS provisioning/lifecycle modules - integrations where WHMCS can create, suspend, unsuspend, terminate, change or query a provisioned service.

Typical fits include VPS platforms, hosting control panels, DNS platforms, storage services and other infrastructure APIs.

## Published modules

None yet.

That is intentional: operational addons such as Colete Online or LogicBoxes Tools belong under [`../addons/`](../addons/) because they do not implement the WHMCS service provisioning lifecycle.

## Requirements for future server modules

Server modules must:

- make create/suspend/unsuspend/terminate semantics explicit;
- resist duplicate provisioning callbacks and retries;
- validate remote resource identifiers before destructive operations;
- separate upstream/API errors from customer-facing messages;
- avoid leaking infrastructure credentials in module logs;
- document what happens when WHMCS and the upstream platform disagree about state.

Need infrastructure for a custom WHMCS provisioning stack? See ServerSpan [KVM & LXC VPS hosting](https://www.serverspan.com/en/virtual-servers).
