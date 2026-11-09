# CajeerServers

**CajeerServers** — русскоязычная self-hosted веб-оболочка для наблюдения и управления инфраструктурой. Это **не ОС**, не прошивка и не замена Proxmox/VMmanager. Для будущих версий предусмотрен сбор данных через API, собственные скрипты, webhooks, collectors и интеграции без обязательных системных агентов.

## Версия 0.1.4 — Installer & Initial Setup

В 0.1.4 реализован **настоящий одноразовый установщик**, а не CLI-заглушка:

- `php bin/cajeer install` и `installer/cli/install.php` — интерактивная/неинтерактивная настройка без пароля в аргументах командной строки; секреты можно прочитать из файлов.
- `php bin/cajeer install:check`, `install:status`, `install:web-token`, `install:recover` — preflight, status, одноразовая активация web installer и восстановление marker после сбоя.
- `GET/POST /install` — HTTP-установка через `public/index.php`, session CSRF, одноразовый код из серверного CLI, HTTPS (или loopback в local), запрет повторной установки.
- Проверка PostgreSQL 17+, реальный migration runner 0.1.3, создание первого администратора с Argon2id-хешем и ролью `administrator` в одной транзакции с DB installation marker и записью аудита.
- `.env` записывается атомарно с правами 0600 и случайным APP_KEY; `storage/app/installed.json` записывается только после DB commit; специальная команда восстановления marker.
- Native smoke-тесты установщика и отдельный интеграционный тест на **пустой одноразовой PostgreSQL**.

Полная инструкция: [wiki/Installation.md](wiki/Installation.md). Установка не открывает доступ к серверным действиям: login, RBAC enforcement и управление инфраструктурой появятся в следующих релизах.

**Для установки требуются PostgreSQL 17+, PHP ext-pdo_pgsql и права на миграции.** Никаких результатов с реальной БД нельзя считать подтверждёнными без запуска `tests/installer-postgres-integration.php` в соответствующем окружении.

## Версия 0.1.3 — Database & Migration Engine

0.1.3 добавляет **настоящую PostgreSQL persistence-инфраструктуру**, не публикуя незащищённые API и не притворяясь, что auth/inventory уже реализованы:

- `ConnectionFactory` — PDO PostgreSQL и MySQL/MariaDB из структурированной конфигурации, защита DSN, connect timeouts, TLS-параметры PostgreSQL, non-persistent connections, отключённые emulate prepares;
- `DatabaseManager` — ленивое подключение, проверка соединения, транзакции, вложенные savepoints, ограниченные повторы только для serialization/deadlock;
- `MigrationRunner` — реальное выполнение SQL PostgreSQL, advisory-lock против параллельного запуска, атомарность каждой миграции, `cajeer_schema_migrations` с SHA-256 checksum, недопущение повторного SQL, обнаружение изменённых/удалённых/непорядковых миграций, `--dry-run` без изменений БД;
- `SqlStatementParser` — разбор миграций с SQL-строками, вложенными комментариями, dollar-quoted PL/pgSQL блоками;
- `0002_rbac_relations.sql` — настоящие таблицы user_roles, role_permissions, индексы токенов и аудита;
- CLI `database:check`, `migrate`, `migrate:status`, `migrate:pending`, `migrate:validate` (также `schema:check`) и рабочие `bin/migrate.php`, `bin/schema-check.php`.

**Поддержка миграций в 0.1.3: PostgreSQL 17+**. MySQL/MariaDB PDO-подключение поддерживается, но SQL-миграции для этих СУБД пока неполные и **намеренно заблокированы**. Ни одна миграция не запускается по HTTP. Аутентификация, permissions, очередь и интеграции Proxmox/VMmanager остаются в следующих релизах.

### Конфигурация PostgreSQL

```bash
cp .env.example .env
# Указать в .env:
# DB_CONNECTION=pgsql
# DB_HOST=127.0.0.1
# DB_PORT=5432
# DB_DATABASE=cajeerservers
# DB_USERNAME=cajeer
# DB_PASSWORD=<private-value>
# Для удалённой production-БД:
# DB_SSLMODE=verify-full
# DB_SSLROOTCERT=/etc/ssl/certs/your-database-ca.pem
```

Для PostgreSQL требуется **PHP ext-pdo_pgsql**; установить пакет PHP-расширения вместе с PHP 8.4+. У пользователя БД должны быть полномочия на создание схемных объектов и, при первом запуске, `pgcrypto` (`CREATE EXTENSION IF NOT EXISTS pgcrypto` в начальной миграции). **Перед первым `migrate` сделайте резервную копию БД.** Если таблицы уже создавались вручную без журнала миграций, мигратор завершится отказом — понадобится контролируемая сверка и baseline. `migrate` не пытается автоматически «принять» неотслеживаемую схему.

```bash
php bin/cajeer database:check
php bin/cajeer migrate:status
php bin/cajeer migrate --dry-run
php bin/cajeer migrate
php bin/cajeer migrate:validate
php bin/cajeer migrate:pending --json
# альтернативные entrypoints:
php bin/migrate.php --dry-run
php bin/schema-check.php
```

При ошибке миграции транзакция **текущего SQL-файла** откатывается, уже успешно применённые предыдущие миграции сохраняются. Не редактируйте применённые `.sql` — добавляйте новый файл с большей версией. Если checksum не совпал либо журнал содержит удалённую миграцию, выполнение блокируется. PostgreSQL DDL, не допускающий транзакций (например, `CREATE INDEX CONCURRENTLY`), не поддерживается этим transactional migration runner; пишите транзакционно совместимые изменения.

### Проверки

```bash
APP_ENV=local php tests/runtime-smoke.php
APP_ENV=local php tests/http-smoke.php
APP_ENV=local php tests/database-smoke.php
# Тест с НАСТОЯЩИМ PostgreSQL запускается только на отдельной disposable БД:
# CAJEER_PG_TEST=1 CAJEER_PG_TEST_DATABASE=cajeer_test_integration \
# CAJEER_PG_TEST_HOST=127.0.0.1 CAJEER_PG_TEST_USERNAME=cajeer \
# CAJEER_PG_TEST_PASSWORD='...' php tests/database-postgres-integration.php
```

`database-smoke.php` тестирует транзакции, блокировки, checksum и обработку ошибок через PDO test double; он **не заменяет** выполнение интеграционного теста на PostgreSQL 17/18. Интеграционный тест меняет переданную тестовую БД и требует её изоляции. Не указывайте production DB. Composer lockfile всё ещё должен быть сгенерирован и проверен в среде с Composer 2.

## Версия 0.1.2 — HTTP Kernel & REST API

Функциональность 0.1.1 (bootstrap, `.env`, DI, жизненный цикл приложения, diagnostics, CLI, JSONL-логирование) сохранена. Добавлен **работающий HTTP pipeline**:

- метод-ориентированный router с группами префиксов, параметрами пути `{name}`, автоматическим HEAD и OPTIONS, обработкой 404/405;
- глобальные middleware: `Accept`, ограничение тела **1 МиБ**, безопасные HTTP-заголовки, access logging (без query string), request ID;
- строгий JSON object parser и валидация обязательных полей, типов, длины, enum и UUID для маршрутов с JSON schema; ошибки **400/406/413/415/422**;
- нормализованные ошибки `application/problem+json` (status, code, request_id), перехват 500 без публикации исключений или секретов;
- использование **Symfony HttpFoundation** для Request/Response при установленном Composer; независимый от vendor HTTP fallback для диагностики и native тестов;
- рабочие публичные API-операции `GET /api/v1/health` (проверка **только** prerequisites runtime, 200 или 503) и `GET /api/v1/system` (публичная информация: название, версия, API v1), плюс `GET /`;
- спецификация OpenAPI **3.1.1** описывает только реализованные операции, CI и тесты расширены.

**В версии 0.1.2 ещё не было реализовано:** аутентификация, RBAC, управление инфраструктурой, исполнение скриптов, webhooks ingestion, синхронизация, реальное Inventory, PostgreSQL migrations, Redis jobs, VMmanager/Proxmox API. Их маршруты **не зарегистрированы и возвращают 404**. В частности, нельзя делать POST для запуска синхронизации и получать фиктивное `accepted: true`.

`config/security.php` пока содержит **настройки будущих механизмов**, а не действующие RBAC, 2FA, CSRF или token auth. До реализации этих механизмов нельзя публиковать привилегированные API-маршруты.

## Требования

- PHP 8.4+ (целевой 8.5), `ext-json`, `ext-pdo`, **`ext-pdo_pgsql` для PostgreSQL**, `ext-openssl`, `ext-sodium`.
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

**Важно:** 0.1.3 реализует реальный слой PostgreSQL и миграции, но ещё **не является полностью production-ready приложением**. Реальная защита операций и хранение данных планируются в следующих релизах.

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

Следующая версия **0.1.5 — Authentication & API Tokens**.
