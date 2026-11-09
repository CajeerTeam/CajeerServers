# Organizations, Workspaces & Ownership (0.2.2)

CajeerServers remains a web resource, not an OS or mandatory agent. Release 0.2.2 introduces **working PostgreSQL-backed tenancy** for existing CMDB assets. Full Inventory CRUD remains 0.2.3.

## Upgrade from 0.2.1

1. Back up PostgreSQL and stop writes during migration.
2. Apply **only new migration** `migrations/pgsql/0009_organizations_workspaces.sql` using `php bin/cajeer migrate`.
3. Run `php bin/cajeer migrate:validate` and `php bin/cajeer release:check --strict` in the deployed environment.
4. Open the admin `/workspaces` page. Existing assets are attached to organization `default`, workspace `default`; asset UUID, source, name and attributes remain unchanged.
5. **Existing active administrators** are given `owner` in the default workspace; ordinary users are **not** silently granted access. New first administrators are added to the default workspace transactionally by the installer.

Do not edit old SQL migrations: the migration runner checks checksums. The new uniqueness constraint on `(workspace_id,source,external_id)` replaces old global `(source,external_id)`. No existing asset data is destroyed.

## Permission model

Every endpoint requires **PHP server session**. API bearer tokens cannot use the workspace APIs. Mutating requests also require CSRF. Global RBAC and per-workspace membership are **both mandatory**:

| Global scope | Operations | Per-workspace role |
|---|---|---|
| `workspaces.read` | List visible organizations/spaces, workspace members and scoped assets | Any active member |
| `organizations.manage` | Create organization (including default workspace) / rename | Create: RBAC; rename: owner in organization |
| `workspaces.manage` | Create workspace / rename | Owner in organization / owner or manager of workspace |
| `workspaces.members.manage` | Grant, change, remove workspace membership | Owner only |
| `inventory.ownership.manage` | Assign asset owner / transfer asset | Source manager or owner / source owner and destination manager or owner |

Every organization is initially created with its own `default` workspace and creator as owner. A workspace is created with the creator as owner. A current member can see only their organizations, workspaces and resources. Requests for IDs outside their membership return `404`, preventing enumeration. Roles are `owner`, `manager`, and `viewer`.

Membership changes are serialized using a PostgreSQL workspace row lock. Removing or downgrading the last active workspace owner is forbidden. Removing a member with assigned assets is forbidden. Disabling a user owning assets or the only owner of a workspace is forbidden. Revoking the built-in administrator role from the sole owner is also prevented; reassign ownership first.

## API paths (relative to `/api/v1`)

```
GET    /organizations
POST   /organizations
PATCH  /organizations/{orgId}
GET    /workspaces?organization_id=UUID
POST   /workspaces
PATCH  /workspaces/{workspaceId}
GET    /workspaces/{workspaceId}/members
PUT    /workspaces/{workspaceId}/members/{userId}
DELETE /workspaces/{workspaceId}/members/{userId}
GET    /workspaces/{workspaceId}/assets?limit=50&offset=0
PATCH  /workspaces/{workspaceId}/assets/{assetId}/owner
POST   /workspaces/{workspaceId}/assets/{assetId}/transfer
```

**Representative JSON bodies:**

```json
{"slug":"acme-prod","name":"Production"}
```

For creating workspaces use `{"organization_id":"UUID","slug":"server-east","name":"East"}`. Membership set uses `{"role":"viewer"}` (also `owner`, `manager`). Owner assignment uses `{"owner_user_id":"UUID"}` or `null`. Asset transfer uses `{"destination_workspace_id":"UUID"}`.

Asset reads intentionally exclude arbitrary JSON attributes, IP addresses and credentials. An owner must be an **active member** of that exact workspace; PostgreSQL also enforces this on asset inserts/owner changes with a trigger. Transfers preserve asset ID, clear owner, and are restricted to workspaces within the same organization. Identical `(source,external_id)` can exist in different workspaces but not within the same workspace.

## Operations and validation

```bash
php tests/workspaces-smoke.php
php tests/security-smoke.php
php bin/cajeer migrate
php bin/cajeer migrate:validate
php bin/cajeer release:check --strict
```

Real PostgreSQL integration (**only isolated test database named `cajeer_test_*`**):

```bash
CAJEER_PG_TEST=1 \
CAJEER_PG_TEST_DATABASE=cajeer_test_workspaces \
CAJEER_PG_TEST_USERNAME=cajeer \
CAJEER_PG_TEST_PASSWORD='test-only-secret' \
php tests/workspaces-postgres-integration.php
```

The test creates and drops its own random PostgreSQL schema. It tests upgrade/backfill, RBAC permission, workspace isolation, membership roles, last-owner protection, owner assignment, cross-org transfer denial and audit. **Do not use production credentials.** Also run the test on PHP 8.4 and PHP 8.5. Nginx should serve Nuxt and PHP on one origin for session cookies and CSRF.

## Scope limits

This release does **not** implement full create/update/delete of inventory assets, provider connectors, Proxmox/VMmanager actions, a credentials vault, or OS-level agents. Those remain on the release roadmap. `composer.lock` and `admin/package-lock.json` must be resolved with real package registries, never generated as placeholder content.
