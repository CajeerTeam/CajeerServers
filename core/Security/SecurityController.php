<?php
declare(strict_types=1);
namespace Cajeer\Servers\Security;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;

/** Security APIs: every mutating privileged operation requires session + CSRF + RBAC. */
final class SecurityController
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly Rbac $rbac,
        private readonly TwoFactorManager $mfa,
        private readonly AuditLogger $audit,
        private readonly WebhookManager $webhooks,
    ) {}

    /** @return array<string,mixed> */
    private function principal(HttpRequest $request, ?string $permission = null, bool $mutating = false): array
    {
        $principal = $this->auth->authenticate($request, sessionOnly: true);
        if ($mutating) $this->auth->requireCsrf($request, $principal);
        if ($permission !== null) $this->rbac->requirePermission($principal, $permission);
        return $principal;
    }

    private function uuid(HttpRequest $request, string $name = 'id'): string
    {
        $id = $request->attribute($name);
        if (!is_string($id) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $id) !== 1) {
            throw new HttpException(404, 'Not found.', 'not_found');
        }
        return $id;
    }

    public function roles(HttpRequest $request): HttpResponse
    {
        $this->principal($request, 'security.roles.read');
        return HttpResponse::json(['data' => $this->rbac->roles()]);
    }
    public function createRole(HttpRequest $request): HttpResponse
    {
        $user = $this->principal($request, 'security.roles.manage', true);
        $data = $request->attribute('json');
        return HttpResponse::json($this->rbac->createRole($user, $data['slug'], $data['name'], $data['permissions']), 201);
    }
    public function users(HttpRequest $request): HttpResponse
    {
        $this->principal($request, 'security.users.read');
        return HttpResponse::json(['data' => $this->rbac->users($this->intQuery($request, 'limit', 50), $this->intQuery($request, 'offset', 0))]);
    }
    public function createUser(HttpRequest $request): HttpResponse
    {
        $user = $this->principal($request, 'security.users.manage', true);
        $data = $request->attribute('json');
        return HttpResponse::json($this->rbac->createUser($user, $data['email'], $data['name'], $data['password']), 201);
    }
    public function setActive(HttpRequest $request): HttpResponse
    {
        $user = $this->principal($request, 'security.users.manage', true);
        $this->rbac->setUserActive($user, $this->uuid($request), $request->attribute('json')['active']);
        return new HttpResponse(204, '');
    }
    public function grantRole(HttpRequest $request): HttpResponse
    {
        $user = $this->principal($request, 'security.roles.manage', true);
        $slug = $request->attribute('slug');
        if (!is_string($slug) || preg_match('/^[a-z][a-z0-9_-]{2,63}$/D', $slug) !== 1) throw new HttpException(404, 'Not found.', 'not_found');
        $this->rbac->membership($user, $this->uuid($request, 'id'), $slug, true);
        return new HttpResponse(204, '');
    }
    public function revokeRole(HttpRequest $request): HttpResponse
    {
        $user = $this->principal($request, 'security.roles.manage', true);
        $slug = $request->attribute('slug');
        if (!is_string($slug) || preg_match('/^[a-z][a-z0-9_-]{2,63}$/D', $slug) !== 1) throw new HttpException(404, 'Not found.', 'not_found');
        $this->rbac->membership($user, $this->uuid($request), $slug, false);
        return new HttpResponse(204, '');
    }
    public function audit(HttpRequest $request): HttpResponse
    {
        $this->principal($request, 'security.audit.read');
        $before = $request->query['before'] ?? null;
        if ($before !== null && !is_string($before)) throw new HttpException(422, 'Invalid audit cursor.', 'validation_failed');
        return HttpResponse::json(['data' => $this->audit->recent($this->intQuery($request, 'limit', 50), $before)]);
    }
    public function mfaStatus(HttpRequest $request): HttpResponse
    {
        return HttpResponse::json($this->mfa->status($this->principal($request)));
    }
    public function mfaEnroll(HttpRequest $request): HttpResponse
    {
        $principal = $this->principal($request, mutating: true);
        return HttpResponse::json($this->mfa->enroll($principal, $request->attribute('json')['password']), 201);
    }
    public function mfaConfirm(HttpRequest $request): HttpResponse
    {
        $principal = $this->principal($request, mutating: true);
        return HttpResponse::json(['recovery_codes' => $this->mfa->confirm($principal, $request->attribute('json')['code'])]);
    }
    public function mfaDisable(HttpRequest $request): HttpResponse
    {
        $principal = $this->principal($request, mutating: true);
        $body = $request->attribute('json');
        $this->mfa->disable($principal, $body['password'], $body['code']);
        return (new HttpResponse(204, ''))->withHeader('Set-Cookie', $this->auth->cookie()->clear());
    }
    public function webhooks(HttpRequest $request): HttpResponse
    {
        $this->principal($request, 'security.webhooks.manage');
        return HttpResponse::json(['data' => $this->webhooks->sources()]);
    }
    public function createWebhook(HttpRequest $request): HttpResponse
    {
        $principal = $this->principal($request, 'security.webhooks.manage', true);
        return HttpResponse::json($this->webhooks->create($principal, $request->attribute('json')['name']), 201);
    }
    public function revokeWebhook(HttpRequest $request): HttpResponse
    {
        $principal = $this->principal($request, 'security.webhooks.manage', true);
        $this->webhooks->revoke($principal, $this->uuid($request));
        return new HttpResponse(204, '');
    }
    public function webhookEvents(HttpRequest $request): HttpResponse
    {
        $this->principal($request, 'security.webhooks.manage');
        return HttpResponse::json(['data' => $this->webhooks->events($this->intQuery($request, 'limit', 50))]);
    }
    public function ingestWebhook(HttpRequest $request): HttpResponse
    {
        $this->auth->assertInstalled();
        return HttpResponse::json($this->webhooks->ingest($this->uuid($request), $request), 202);
    }

    private function intQuery(HttpRequest $request, string $key, int $default): int
    {
        $value = $request->query[$key] ?? $default;
        if (is_int($value)) return $value;
        if (!is_string($value) || !preg_match('/^[0-9]{1,5}$/D', $value)) {
            throw new HttpException(422, 'Invalid pagination.', 'validation_failed');
        }
        return (int) $value;
    }
}
