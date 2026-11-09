<?php
declare(strict_types=1);

namespace Cajeer\Servers\Installer;

use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;
use RuntimeException;
use Cajeer\Servers\Config\EnvironmentLoader;
use Throwable;

/** Browser wizard with out-of-band setup token, session CSRF and TLS requirement. */
final class WebInstaller
{
    public function __construct(private readonly InstallService $installer) {}

    public function __invoke(HttpRequest $request): HttpResponse
    {
        if ($this->installer->state()->isInstalled()) return $this->page('Already installed', '<p>Установка уже завершена.</p>', 410);
        if (!$this->installer->state()->hasWebToken()) return $this->page('Installer disabled', '<p>Веб-установщик выключен. Запустите локально <code>php bin/cajeer install:web-token</code>.</p>', 403);
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $loopback = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true);
        if (!$https && !($loopback && EnvironmentLoader::getString('APP_ENV', 'production') === 'local')) return $this->page('TLS required', '<p>Для установки по сети требуется HTTPS.</p>', 403);
        if (session_status() === PHP_SESSION_NONE) {
            if (headers_sent()) return $this->page('Sessions unavailable', '<p>Session setup failed.</p>', 503);
            if (!session_start([
                'use_strict_mode' => 1, 'use_only_cookies' => 1,
                'cookie_httponly' => true, 'cookie_secure' => $https,
                'cookie_samesite' => 'Strict', 'cookie_path' => '/',
            ])) return $this->page('Sessions unavailable', '<p>Session setup failed.</p>', 503);
        }
        if (!isset($_SESSION['cajeer_install_csrf']) || !is_string($_SESSION['cajeer_install_csrf'])) {
            $_SESSION['cajeer_install_csrf'] = bin2hex(random_bytes(32));
        }
        if ($request->method === 'GET' || $request->method === 'HEAD') return $this->form();
        if ($request->method !== 'POST') return $this->page('Method not allowed', '', 405);
        if (!str_starts_with(strtolower($request->header('Content-Type', '')), 'application/x-www-form-urlencoded')) {
            return $this->form('Неверный Content-Type.', 415);
        }
        if (strlen($request->body) > 16384) return $this->form('Превышен допустимый размер формы.', 413);
        $origin = $request->header('Origin');
        if ($origin !== null) {
            $expected = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
            if (!hash_equals(strtolower($expected), strtolower(rtrim($origin, '/')))) {
                return $this->form('Источник запроса не разрешён.', 403);
            }
        }
        parse_str($request->body, $fields);
        foreach ($fields as $value) {
            if (!is_string($value)) return $this->form('Некорректные данные формы.', 400);
        }
        if (!hash_equals($_SESSION['cajeer_install_csrf'], $fields['csrf'] ?? '')) {
            return $this->form('Недействительный CSRF-токен.', 403);
        }
        if (!$this->installer->state()->verifyWebToken($fields['setup_token'] ?? '')) {
            return $this->form('Недействительный одноразовый код установки.', 403);
        }
        if (($fields['admin_password'] ?? '') !== ($fields['admin_password_confirm'] ?? '')) {
            return $this->form('Пароли администратора не совпадают.', 422);
        }
        $input = [];
        foreach (['APP_URL','APP_ENV','DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD','DB_SSLMODE','admin_email','admin_name','admin_password'] as $key) {
            if (isset($fields[$key])) $input[$key] = $fields[$key];
        }
        try {
            $this->installer->normalize($input);
        } catch (RuntimeException) {
            return $this->form('Некорректные поля настройки, проверьте адрес, БД и пароль администратора.', 422);
        }
        try {
            $result = $this->installer->install($input);
            unset($_SESSION['cajeer_install_csrf']);
            session_regenerate_id(true);
            return $this->page('Installation completed', '<h1>Установка завершена</h1><p>Администратор создан: ' . self::h($result['admin_email']) . '</p><p>Следующий шаг: реализация входа в версии 0.1.5.</p>');
        } catch (Throwable $error) {
            // Do not send driver exception chains, credentials or DSNs to the browser.
            error_log('CajeerServers installer failed (' . $error::class . ').');
            return $this->form('Установка не завершена. Проверьте настройки PostgreSQL, расширения и журнал ошибок сервера.', 503);
        }
    }

    private function form(string $error = '', int $status = 200): HttpResponse
    {
        $csrf = self::h((string) ($_SESSION['cajeer_install_csrf'] ?? ''));
        $errorHtml = $error === '' ? '' : '<p role="alert">' . self::h($error) . '</p>';
        $body = '<h1>CajeerServers — первоначальная установка</h1>' . $errorHtml .
            '<p>Одноразовый код получите командой <code>php bin/cajeer install:web-token</code> на сервере.</p>' .
            '<form action="/install" method="post" autocomplete="off">' .
            '<input type="hidden" name="csrf" value="' . $csrf . '">' .
            self::input('setup_token','Одноразовый код','password') .
            self::input('APP_ENV','Окружение (local / production)','text','production') .
            self::input('APP_URL','Публичный URL','url') .
            self::input('DB_HOST','PostgreSQL host','text','127.0.0.1') .
            self::input('DB_PORT','PostgreSQL port','text','5432') .
            self::input('DB_DATABASE','PostgreSQL database','text','cajeerservers') .
            self::input('DB_USERNAME','PostgreSQL user','text','cajeer') .
            self::input('DB_PASSWORD','PostgreSQL password','password') .
            self::input('DB_SSLMODE','PostgreSQL TLS mode','text','prefer') .
            self::input('admin_email','Email администратора','email') .
            self::input('admin_name','Имя администратора') .
            self::input('admin_password','Пароль администратора (14+ символов)','password') .
            self::input('admin_password_confirm','Повтор пароля','password') .
            '<button type="submit">Установить</button></form>';
        return $this->page('CajeerServers Setup', $body, $status);
    }

    private static function input(string $name, string $label, string $type = 'text', string $value = ''): string
    {
        $name = self::h($name);
        return '<p><label>' . self::h($label) . ': <input required name="' . $name . '" type="' . self::h($type) . '" value="' . self::h($value) . '"></label></p>';
    }

    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function page(string $title, string $body, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . self::h($title) . '</title></head><body><main>' . $body . '</main></body></html>', ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
