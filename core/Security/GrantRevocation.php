<?php
declare(strict_types=1);
namespace Cajeer\Servers\Security;

use PDO;

/** Invalidate authorizations in the same transaction as the security change.
 * Never resurrect a previously delegated secret by restoring a user's privileges.
 */
final class GrantRevocation
{
    public static function revoke(PDO $pdo, string $actor, ?string $workspace = null): void
    {
        if (!$pdo->inTransaction()) throw new \LogicException('Grant revocation requires a transaction.');
        $args = ['actor' => $actor];
        $scope = '';
        if ($workspace !== null) { $scope = ' AND workspace_id=:workspace'; $args['workspace'] = $workspace; }
        // Disable dedicated plans as well as generic schedules. Existing workers recheck
        // the revoked grant before accessing plaintext and before committing their results.
        $q = $pdo->prepare('UPDATE connector_service_grants SET revoked_at=clock_timestamp() WHERE actor_id=:actor AND revoked_at IS NULL'.$scope.' RETURNING id');
        $q->execute($args);
        $ids = $q->fetchAll(PDO::FETCH_COLUMN);
        $q = $pdo->prepare('UPDATE collection_plans SET enabled=false WHERE grant_id=:id AND enabled');
        foreach ($ids as $id) $q->execute(['id'=>$id]);
        $q = $pdo->prepare('DELETE FROM vault_use_grants WHERE actor_id=:actor'.$scope);
        $q->execute($args);
    }
}
