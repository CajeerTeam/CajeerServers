<?php
declare(strict_types=1);
namespace Cajeer\Servers\Security;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use PDO;

/** Persistent deny-by-default RBAC; all privileges are resolved from the DB on each request. */
final class Rbac
{
    public const PERMISSIONS = [
        'security.users.read', 'security.users.manage',
        'security.roles.read', 'security.roles.manage', 'security.roles.grant_administrator',
        'security.audit.read', 'security.webhooks.manage',
        'admin.dashboard.read', 'admin.jobs.read', 'admin.jobs.execute', 'admin.scheduler.read',
        'organizations.manage', 'workspaces.read', 'workspaces.manage', 'workspaces.members.manage', 'inventory.ownership.manage', 'inventory.assets.read', 'inventory.assets.create', 'inventory.assets.update', 'inventory.assets.delete', 'inventory.network.read', 'inventory.network.manage', 'inventory.relationships.read', 'inventory.relationships.manage', 'inventory.catalog.read', 'inventory.catalog.manage', 'inventory.history.read', 'inventory.reconcile.manage', 'inventory.history.restore', 'inventory.transfer.read', 'inventory.transfer.manage', 'inventory.credentials.read', 'inventory.credentials.manage', 'inventory.credentials.scope', 'vault.credentials.read', 'vault.credentials.create', 'vault.credentials.update', 'vault.credentials.delete', 'vault.credentials.use', 'connectors.targets.read', 'connectors.targets.manage', 'connectors.credentials.read', 'connectors.credentials.manage',
    ];

    public function __construct(private readonly DatabaseManager $database, private readonly AuditLogger $audit) {}

    /** @param array<string,mixed> $principal */
    public function requirePermission(array $principal, string $permission, ?PDO $pdo = null): void
    {
        Permission::assertKnown($permission);
        if (($principal['mode'] ?? null) !== 'session' || !isset($principal['user']['id'])) {
            throw new HttpException(403, 'Session authentication required.', 'session_required');
        }
        $this->requireUserPermission((string)$principal['user']['id'], $permission, $pdo);
    }

    /** Internal worker authorization; never authenticates an HTTP request. */
    public function requireUserPermission(string $user, string $permission, ?PDO $pdo = null): void
    {
        Permission::assertKnown($permission);
        $pdo ??= $this->database->connection();
        $query = $pdo->prepare(<<<'SQL'
SELECT EXISTS (
 SELECT 1 FROM users u
 JOIN user_roles ur ON ur.user_id = u.id
 JOIN role_permissions rp ON rp.role_id = ur.role_id
 JOIN permissions p ON p.id = rp.permission_id
 WHERE u.id = :user AND u.is_active = true AND p.scope = :permission
)
SQL);
        $query->execute(['user' => $user, 'permission' => $permission]);
        $allowed = $query->fetchColumn();
        if (!in_array($allowed, [true, 't', '1', 1], true)) {
            $this->audit->record($pdo, $user, 'security.permission.denied', 'denied', 'user', $user, ['permission' => $permission]);
            throw new HttpException(403, 'Insufficient permission.', 'permission_denied');
        }
    }

    /** @param array<string,mixed> $principal @return list<string> */
    public function permissionsFor(array $principal): array
    {
        if (($principal['mode'] ?? null) !== 'session' || !isset($principal['user']['id'])) {
            throw new HttpException(403, 'Session authentication required.', 'session_required');
        }
        $stmt = $this->database->connection()->prepare(<<<'SQL'
SELECT DISTINCT p.scope FROM permissions p
JOIN role_permissions rp ON rp.permission_id=p.id
JOIN user_roles ur ON ur.role_id=rp.role_id
JOIN users u ON u.id=ur.user_id
WHERE u.id=:user AND u.is_active=true ORDER BY p.scope
SQL);
        $stmt->execute(['user' => $principal['user']['id']]);
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN), 'is_string'));
    }

    /** @return list<array<string,mixed>> */
    public function roles(): array
    {
        $pdo = $this->database->connection();
        $rows = $pdo->query('SELECT id::text, slug, name FROM roles ORDER BY slug LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare('SELECT p.scope FROM permissions p JOIN role_permissions rp ON rp.permission_id=p.id JOIN roles r ON r.id=rp.role_id WHERE r.id=:id ORDER BY p.scope');
        foreach ($rows as &$row) {
            $stmt->execute(['id' => $row['id']]);
            $row['permissions'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        unset($row);
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function users(int $limit = 100, int $offset = 0): array
    {
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 10000) {
            throw new HttpException(422, 'Invalid pagination.', 'validation_failed');
        }
        $pdo = $this->database->connection();
        $stmt = $pdo->prepare(<<<'SQL'
SELECT u.id::text, u.email, u.name, u.is_active, u.two_factor_enabled,
 COALESCE(jsonb_agg(r.slug ORDER BY r.slug) FILTER (WHERE r.slug IS NOT NULL), '[]'::jsonb)::text AS roles
FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id
GROUP BY u.id ORDER BY u.created_at DESC, u.id DESC LIMIT :limit OFFSET :offset
SQL);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['roles'] = json_decode((string) $row['roles'], true, 32, JSON_THROW_ON_ERROR);
            $row['is_active'] = in_array($row['is_active'], [true, 't', '1', 1], true);
            $row['two_factor_enabled'] = in_array($row['two_factor_enabled'], [true, 't', '1', 1], true);
        }
        unset($row);
        return $rows;
    }

    /** @param array<string,mixed> $principal */
    public function createUser(array $principal, string $email, string $name, string $password): array
    {
        $email = strtolower(trim($email));
        $name = trim($name);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255 || strlen($name) < 1
            || strlen($name) > 255 || strlen($password) < 14 || strlen($password) > 1024) {
            throw new HttpException(422, 'Invalid user details.', 'validation_failed');
        }
        return $this->database->transaction(function (PDO $pdo) use ($principal, $email, $name, $password): array {
            $this->requirePermission($principal, 'security.users.manage', $pdo);
            $check = $pdo->prepare('SELECT 1 FROM users WHERE lower(email) = :email LIMIT 1');
            $check->execute(['email' => $email]);
            if ($check->fetchColumn()) throw new HttpException(409, 'Email already registered.', 'conflict');
            $add = $pdo->prepare('INSERT INTO users (email,name,password_hash) VALUES (:email,:name,:password) RETURNING id::text');
            $add->execute(['email' => $email, 'name' => $name, 'password' => password_hash($password, PASSWORD_ARGON2ID)]);
            $id = $add->fetchColumn();
            if (!is_string($id)) throw new \RuntimeException('Could not create user.');
            $this->audit->record($pdo, $principal['user']['id'], 'security.user.created', 'success', 'user', $id);
            return ['id' => $id, 'email' => $email, 'name' => $name, 'roles' => []];
        });
    }

    /** @param array<string,mixed> $principal */
    public function setUserActive(array $principal, string $userId, bool $active): void
    {
        $this->database->transaction(function (PDO $pdo) use ($principal, $userId, $active): void {
            $this->requirePermission($principal, 'security.users.manage', $pdo);
            if ($userId === $principal['user']['id'] && !$active) throw new HttpException(409, 'Cannot disable your own account.', 'conflict');
            if (!$active) {
                // Never disable an active sole workspace owner or a user whose assets remain assigned.
                // Serialize membership changes by locking workspace rows before counting owners.
                $locks=$pdo->prepare(<<<'SQL'
SELECT w.id::text FROM workspaces w JOIN workspace_members wm ON wm.workspace_id=w.id
WHERE wm.user_id=:user AND wm.role='owner' ORDER BY w.id FOR UPDATE OF w
SQL);
                $locks->execute(['user'=>$userId]);
                $spaces=$locks->fetchAll(PDO::FETCH_COLUMN);
                foreach ($spaces as $workspaceId) {
                    $count=$pdo->prepare(<<<'SQL'
SELECT count(*) FROM workspace_members wm JOIN users u ON u.id=wm.user_id
WHERE wm.workspace_id=:workspace AND wm.role='owner' AND u.is_active=true
SQL);
                    $count->execute(['workspace'=>$workspaceId]);
                    if ((int)$count->fetchColumn()<=1) throw new HttpException(409,'Cannot disable the last workspace owner.','conflict');
                }
                $owned=$pdo->prepare('SELECT 1 FROM inventory_assets WHERE owner_user_id=:user LIMIT 1');
                $owned->execute(['user'=>$userId]);
                if ($owned->fetchColumn()) throw new HttpException(409,'Reassign owned assets before disabling user.','conflict');
                $role = $pdo->query("SELECT id::text FROM roles WHERE slug='administrator' FOR UPDATE")->fetchColumn();
                if (is_string($role)) {
                    $membership = $pdo->prepare('SELECT 1 FROM user_roles WHERE user_id=:id AND role_id=:role');
                    $membership->execute(['id' => $userId, 'role' => $role]);
                    if ($membership->fetchColumn()) {
                        $this->requirePermission($principal, 'security.roles.grant_administrator', $pdo);
                        $count = $pdo->prepare('SELECT count(*) FROM user_roles ur JOIN users u ON u.id=ur.user_id WHERE ur.role_id=:role AND u.is_active=true');
                        $count->execute(['role' => $role]);
                        if ((int) $count->fetchColumn() <= 1) throw new HttpException(409, 'Cannot disable the last active administrator.', 'conflict');
                    }
                }
            }
            $stmt = $pdo->prepare('UPDATE users SET is_active=:active WHERE id=:id RETURNING id::text');
            $stmt->bindValue(':active', $active, PDO::PARAM_BOOL);
            $stmt->bindValue(':id', $userId);
            $stmt->execute();
            if (!$stmt->fetchColumn()) throw new HttpException(404, 'User not found.', 'not_found');
            if (!$active) {
                $pdo->prepare('UPDATE auth_sessions SET revoked_at=now() WHERE user_id=:id AND revoked_at IS NULL')->execute(['id' => $userId]);
                $pdo->prepare('UPDATE api_tokens SET revoked_at=now() WHERE user_id=:id AND revoked_at IS NULL')->execute(['id' => $userId]);
            }
            $this->audit->record($pdo, $principal['user']['id'], $active ? 'security.user.enabled' : 'security.user.disabled', 'success', 'user', $userId);
        });
    }

    /** @param array<string,mixed> $principal @param list<string> $permissions */
    public function createRole(array $principal, string $slug, string $name, array $permissions): array
    {
        $name = trim($name);
        if (preg_match('/^[a-z][a-z0-9_-]{2,63}$/D', $slug) !== 1 || $slug === 'administrator'
            || strlen($name) < 1 || strlen($name) > 255 || count($permissions) > count(self::PERMISSIONS)
            || !array_is_list($permissions) || count(array_filter($permissions, 'is_string')) !== count($permissions)
            || count(array_unique($permissions)) !== count($permissions)
            || array_diff($permissions, self::PERMISSIONS)) {
            throw new HttpException(422, 'Invalid role details.', 'validation_failed');
        }
        return $this->database->transaction(function (PDO $pdo) use ($principal, $slug, $name, $permissions): array {
            $this->requirePermission($principal, 'security.roles.manage', $pdo);
            $exists = $pdo->prepare('SELECT 1 FROM roles WHERE slug=:slug');
            $exists->execute(['slug' => $slug]);
            if ($exists->fetchColumn()) throw new HttpException(409, 'Role already exists.', 'conflict');
            $add = $pdo->prepare('INSERT INTO roles (slug,name) VALUES (:slug,:name) RETURNING id::text');
            $add->execute(['slug' => $slug, 'name' => $name]);
            $id = $add->fetchColumn();
            if (!is_string($id)) throw new \RuntimeException('Could not create role.');
            foreach ($permissions as $permission) $this->requirePermission($principal, $permission, $pdo);
            $grant = $pdo->prepare('INSERT INTO role_permissions (role_id,permission_id) SELECT :role,id FROM permissions WHERE scope=:scope');
            foreach ($permissions as $permission) {
                $grant->execute(['role' => $id, 'scope' => $permission]);
                if ($grant->rowCount() !== 1) throw new \RuntimeException('Permission registry incomplete.');
            }
            $this->audit->record($pdo, $principal['user']['id'], 'security.role.created', 'success', 'role', $id, ['count' => count($permissions)]);
            return ['id' => $id, 'slug' => $slug, 'name' => $name, 'permissions' => $permissions];
        });
    }

    /** @param array<string,mixed> $principal */
    public function membership(array $principal, string $userId, string $slug, bool $grant): void
    {
        $this->database->transaction(function (PDO $pdo) use ($principal, $userId, $slug, $grant): void {
            $this->requirePermission($principal, 'security.roles.manage', $pdo);
            // Lock administrator row before changing the last-administrator invariant.
            $findRole = $pdo->prepare('SELECT id::text FROM roles WHERE slug=:slug FOR UPDATE');
            $findRole->execute(['slug' => $slug]);
            $roleId = $findRole->fetchColumn();
            if (!is_string($roleId)) throw new HttpException(404, 'Role not found.', 'not_found');
            if ($slug === 'administrator') {
                $this->requirePermission($principal, 'security.roles.grant_administrator', $pdo);
            }
            $privileges = $pdo->prepare('SELECT p.scope FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=:role');
            $privileges->execute(['role' => $roleId]);
            foreach ($privileges->fetchAll(PDO::FETCH_COLUMN) as $scope) {
                // A role manager cannot delegate a permission they do not possess.
                if (in_array($scope, self::PERMISSIONS, true)) $this->requirePermission($principal, $scope, $pdo);
            }
            $findUser = $pdo->prepare('SELECT id::text FROM users WHERE id=:user FOR UPDATE');
            $findUser->execute(['user' => $userId]);
            if (!$findUser->fetchColumn()) throw new HttpException(404, 'User not found.', 'not_found');
            if (!$grant && $slug === 'administrator') {
                if ($principal['user']['id'] === $userId) throw new HttpException(409, 'Cannot revoke your own administrator role.', 'conflict');
                $count = $pdo->prepare('SELECT count(*) FROM user_roles ur JOIN users u ON u.id=ur.user_id WHERE ur.role_id=:role AND u.is_active=true');
                $count->execute(['role' => $roleId]);
                if ((int) $count->fetchColumn() <= 1) throw new HttpException(409, 'At least one administrator is required.', 'conflict');
                // A sole owner must not lose the only built-in role granting workspace management.
                $locks=$pdo->prepare(<<<'SQL'
SELECT w.id::text FROM workspaces w JOIN workspace_members wm ON wm.workspace_id=w.id
WHERE wm.user_id=:user AND wm.role='owner' ORDER BY w.id FOR UPDATE OF w
SQL);
                $locks->execute(['user'=>$userId]);
                foreach($locks->fetchAll(PDO::FETCH_COLUMN) as $workspaceId) {
                    $owners=$pdo->prepare(<<<'SQL'
SELECT count(*) FROM workspace_members wm JOIN users u ON u.id=wm.user_id
WHERE wm.workspace_id=:workspace AND wm.role='owner' AND u.is_active=true
SQL);
                    $owners->execute(['workspace'=>$workspaceId]);
                    if((int)$owners->fetchColumn()<=1) throw new HttpException(409,'Transfer workspace ownership before revoking administrator.','conflict');
                }
            }
            if ($grant) {
                $write = $pdo->prepare('INSERT INTO user_roles (user_id,role_id) VALUES (:user,:role) ON CONFLICT DO NOTHING');
            } else {
                $write = $pdo->prepare('DELETE FROM user_roles WHERE user_id=:user AND role_id=:role');
            }
            $write->execute(['user' => $userId, 'role' => $roleId]);
            if ($write->rowCount() === 0) throw new HttpException(409, 'Membership already in requested state.', 'conflict');
            $this->audit->record($pdo, $principal['user']['id'], $grant ? 'security.role.granted' : 'security.role.revoked', 'success', 'user', $userId, ['role' => $slug]);
        });
    }
}
