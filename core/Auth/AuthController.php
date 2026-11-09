<?php
declare(strict_types=1);

namespace Cajeer\Servers\Auth;

use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;

/** Real auth endpoints. All identity is resolved by the server, never from request body. */
final class AuthController
{
    public function __construct(private readonly AuthenticationService $auth) {}

    public function login(HttpRequest $request): HttpResponse
    {
        $this->auth->requireSameOrigin($request);
        $input = $request->attribute('json');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $login = $this->auth->loginOrReject($input['email'], $input['password'], $ip, $input['mfa_code'] ?? null);
        return HttpResponse::json([
            'user' => $login['user'], 'csrf_token' => $login['csrf_token'], 'expires_in' => $login['expires_in'],
            'auth_type' => 'session',
        ])->withHeader('Set-Cookie', $login['cookie']);
    }

    public function me(HttpRequest $request): HttpResponse
    {
        $principal = $this->auth->authenticate($request, ['profile:read']);
        return HttpResponse::json(['user' => $principal['user'], 'auth_type' => $principal['mode'], 'scopes' => $principal['scopes']]);
    }

    public function csrf(HttpRequest $request): HttpResponse
    {
        $principal = $this->auth->authenticate($request, sessionOnly: true);
        return HttpResponse::json(['csrf_token' => $this->auth->rotateCsrf($principal)]);
    }

    public function logout(HttpRequest $request): HttpResponse
    {
        $principal = $this->auth->authenticate($request, sessionOnly: true);
        $this->auth->requireCsrf($request, $principal);
        $this->auth->logout($principal);
        return (new HttpResponse(204, ''))->withHeader('Set-Cookie', $this->auth->cookie()->clear());
    }

    public function changePassword(HttpRequest $request): HttpResponse
    {
        $principal = $this->auth->authenticate($request, sessionOnly: true);
        $this->auth->requireCsrf($request, $principal);
        $input = $request->attribute('json');
        $this->auth->changePassword($principal, $input['current_password'], $input['new_password']);
        return (new HttpResponse(204, ''))->withHeader('Set-Cookie', $this->auth->cookie()->clear());
    }

    public function sessions(HttpRequest $request): HttpResponse
    {
        $principal = $this->auth->authenticate($request, ['sessions:read']);
        return HttpResponse::json(['data' => $this->auth->sessions($principal)]);
    }

    public function revokeSession(HttpRequest $request): HttpResponse
    {
        $principal = $this->auth->authenticate($request, sessionOnly: true);
        $this->auth->requireCsrf($request, $principal);
        $current = $this->auth->revokeSession($principal, $this->uuid($request));
        $response = new HttpResponse(204, '');
        return $current ? $response->withHeader('Set-Cookie', $this->auth->cookie()->clear()) : $response;
    }

    public function tokens(HttpRequest $request): HttpResponse
    {
        $principal = $this->auth->authenticate($request, ['tokens:read']);
        return HttpResponse::json(['data' => $this->auth->tokens($principal)]);
    }

    public function createToken(HttpRequest $request): HttpResponse
    {
        $principal = $this->auth->authenticate($request, sessionOnly: true);
        $this->auth->requireCsrf($request, $principal);
        $input = $request->attribute('json');
        return HttpResponse::json($this->auth->createToken($principal, $input['name'], $input['scopes'], $input['expires_in']), 201);
    }

    public function revokeToken(HttpRequest $request): HttpResponse
    {
        $principal = $this->auth->authenticate($request, sessionOnly: true);
        $this->auth->requireCsrf($request, $principal);
        $this->auth->revokeToken($principal, $this->uuid($request));
        return new HttpResponse(204, '');
    }

    private function uuid(HttpRequest $request): string
    {
        $id = $request->attribute('id');
        if (!is_string($id) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $id) !== 1) {
            throw new HttpException(404, 'Not Found', 'not_found');
        }
        return $id;
    }
}
