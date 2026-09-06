# RadiusSphere addon

RadiusSphere is an independent enterprise FreeRADIUS control plane for WHMCS. This addon owns clusters, policy revisions, identity/service links, durable provisioning commands, and audit records.

## Install

1. Copy this directory to `modules/addons/radiussphere`.
2. Activate **RadiusSphere** in WHMCS Addon Modules and grant administrator access.
3. Install the paired `servers/radiussphere` module.
4. Configure a cluster and policy before assigning RadiusSphere to a WHMCS product.

## Safety

Deactivation retains all `mod_radiussphere_*` data. Do not delete the tables until services are migrated and audit/retention obligations are satisfied.

## Foundation scope

This first commit creates the control-plane schema and durable command model. Cluster, policy, NAS, realm, session, and driver CRUD are intentionally not represented by unsafe direct table editing. The SQL FreeRADIUS driver and its validated operations are the next increment.
