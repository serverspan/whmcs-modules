# RadiusSphere server module

Install this directory as `modules/servers/radiussphere`. The paired RadiusSphere addon must be activated first.

## Product configuration

Assign the product to the `RadiusSphere` server module, then set:

- **Cluster ID** — a control-plane cluster.
- **Policy ID** — an enabled versioned policy.
- **Identity realm** — optional realm metadata.

The module records idempotent commands instead of embedding FreeRADIUS credentials in each WHMCS product. The addon command worker applies commands through a validated backend driver.

## Current foundation behavior

WHMCS lifecycle actions create a service link and queue a durable command. The driver dispatcher is intentionally absent until the SQL backend driver, policy editor, and cluster credential store are committed.
