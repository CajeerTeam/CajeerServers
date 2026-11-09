# CajeerServers

**CajeerServers** — русскоязычная self-hosted веб-оболочка для наблюдения и управления инфраструктурой. Это **не ОС**, не прошивка и не замена Proxmox/VMmanager. Для будущих версий предусмотрен сбор данных через API, собственные скрипты, webhooks, collectors и интеграции без обязательных системных агентов.

## Версия 0.1.2 — HTTP Kernel & REST API

Функциональность 0.1.1 (bootstrap, `.env`, DI, жизненный цикл приложения, diagnostics, CLI, JSONL-логирование) сохранена. Добавлен **работающий HTTP pipeline**:

- метод-ориентированный router с группами префиксов, параметрами пути `{name}`, автоматическим HEAD и OPTIONS, обработкой 404/405;
- глобальные middleware: `Accept`, ограничение тела **1 МиБ**, безопасные HTTP-заголовки, access logging (без query string), request ID;
- строгий JSON object parser и валидация обязательных полей, типов, длины, enum и UUID для маршрутов с JSON schema; ошибки **400/406/413/415/422**;
- нормализованные ошибки `application/problem+json` (status, code, request_id), перехват 500 без публикации исключений или секретов;
- использование **Symfony HttpFoundation** для Request/Response при установленном Composer; независимый от vendor HTTP fallback для диагностики и native тестов;
- рабочие публичные API-операции `GET /api/v1/health` (проверка **только** prerequisites runtime, 200 или 503) и `GET /api/v1/system` (публичная информация: название, версия, API v1), плюс `GET /`;
- спецификация OpenAPI **3.1.1** описывает только реализованные операции, CI и тесты расширены.

**Не реализовано в 0.1.2:** аутентификация, RBAC, управление инфраструктурой, исполнение скриптов, webhooks ingestion, синхронизация, реальное Inventory, PostgreSQL migrations, Redis jobs, VMmanager/Proxmox API. Их маршруты **не зарегистрированы и возвращают 404**. В частности, нельзя делать POST для запуска синхронизации и получать фиктивное `accepted: true`.

`config/security.php` пока содержит **настройки будущих механизмов**, а не действующие RBAC, 2FA, CSRF или token auth. До реализации этих механизмов нельзя публиковать привилегированные API-маршруты.

## Требования

- PHP 8.4+ (целевой 8.5), `ext-json`, `ext-pdo`, `ext-openssl`, `ext-sodium`.
- Nginx/PHP-FPM (Apache опционально), `public/` — единственный DocumentRoot.
- Composer 2 для Symfony Components. HTTP, CLI и native tests также работают без Composer посредством самостоятельного HTTP fallback.
- `storage/logs`, `storage/cache`, `storage/tmp` доступны на запись процессу PHP.

## Локальная установка

```bash
cp .env.example .env
# APP_ENV=local, APP_DEBUG=false; никогда не коммитьте .env
composer install --no-interaction
php bin/cajeer doctor
php tests/runtime-smoke.php
php tests/http-smoke.php
php -S 127.0.0.1:8080 -t public
```

Проверки публичного API:

```bash
curl -i http://127.0.0.1:8080/api/v1/health
curl -i http://127.0.0.1:8080/api/v1/system
curl -i -X OPTIONS http://127.0.0.1:8080/api/v1/system
curl -i -X POST http://127.0.0.1:8080/api/v1/system  # HTTP 405
curl -i http://127.0.0.1:8080/api/v1/actions         # HTTP 404, no fake action
```

## Production configuration

1. Используйте `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://example.org`.
2. Сгенерируйте секретный ключ: `php -r 'echo "base64:", base64_encode(random_bytes(32)), PHP_EOL;'` и задайте `APP_KEY` через секретное окружение или защищённый `.env`.
3. `.env`, `core/`, `config/` и `storage/` **вне document root**. Запретите публичную выдачу служебных файлов. Сконфигурируйте TLS и proxy headers на Nginx.
4. `php bin/cajeer doctor --json` должен вернуть `ok:true`. Не считайте проверку runtime проверкой состояния БД или внешних сервисов.
5. Установите и зафиксируйте зависимости через `composer.lock` в доверенной сетевой среде. В архив не включён искусственный непроверенный lockfile.
6. Настройте доступ на запись в `storage/logs` и ротацию журналов. Запросы логируются без query string и без тела.

**Важно:** 0.1.2 реализует production-oriented HTTP boundary, но пока **не является полностью production-ready приложением**. Реальная защита операций и хранение данных планируются в следующих релизах.

## Тестирование

```bash
APP_ENV=local APP_DEBUG=false php tests/runtime-smoke.php
APP_ENV=local APP_DEBUG=false php tests/http-smoke.php
APP_ENV=local APP_DEBUG=false php bin/cajeer self:test
# после composer install
APP_ENV=local APP_DEBUG=false vendor/bin/phpunit
```

Native smoke-tests выполняются без Composer. GitFlic CI проверяет PHP 8.4 и 8.5, PHPUnit запускается после установки пакетов. `composer.lock` пока не сгенерирован, поскольку Composer и доступ к registry в среде подготовки отсутствовали.

## Структура

- `core/Runtime/` — Request/Response, error handler и диагностика.
- `core/Http/` — HTTP Kernel, Router, middleware, JSON validation, exceptions.
- `routes/api.php` — реальные маршруты (только root, health, system).
- `core/Api/Controller/` — рабочие controllers для health/system; оставшиеся контроллеры не зарегистрированы.
- `api/openapi.yaml` — API 3.1.1 только для реализованных маршрутов.
- `public/index.php` — front controller.
- `tests/http-smoke.php` — native HTTP tests.

Следующая версия **0.1.3 — Database & Migration Engine**.
