# CajeerServers

**CajeerServers** — русскоязычная self-hosted веб-оболочка для наблюдения и управления инфраструктурой. Это **не ОС**, не прошивка и не замена Proxmox/VMmanager. Для будущих версий предусмотрен сбор данных через API, собственные скрипты, webhooks, collectors и интеграции без обязательных системных агентов.

## Версия 0.3.0 — Inventory / CMDB Stable Integration

Добавлена **работающая межмодульная проверка целостности** Inventory, IPAM, Topology, Catalog, History, Import и Credential Bindings. Проверки выполняются непосредственно в PostgreSQL в read-only транзакции с фиксированным snapshot и ограничением времени. Команды `inventory:integrity` и защищённый `/api/v1/admin/cmdb-integrity` возвращают только агрегированные данные без секретов. Исправлена публикация импортных queue jobs до фиксации транзакции.

```bash
php bin/cajeer version
php bin/cajeer inventory:integrity --json
php tests/cmdb-integrity-smoke.php
php tests/import-dispatch-smoke.php
php scripts/verify-release.php
```

Документация: [GitHub Docs — CMDB 0.3.0](docs/CMDB-0.3.0.md). Добавлена миграция 0016 с provenance и защитой IPAM. Строгий production-допуск требует реального PostgreSQL/Redis integration, Nuxt build, браузерного E2E и подлинных lock-файлов зависимостей. Не подтверждённые проверки нельзя считать пройденными.

## Версия 0.2.9 — Complete Inventory Admin UI & E2E

Рабочая карточка `/inventory/{uuid}` с редактированием по ETag и отображением IPAM, отношений, ревизий и безопасных ссылок на credentials. Сквозная навигация Inventory/History/IPAM/Topology/Transfer с сохранением workspace через URL; восстановление идемпотентности импорта при потерянном ответе и автообновление состояния batch. Два новых настоящих browser E2E-сценария для disposable staging: создание/изменение объекта и межмодульные переходы, а также потеря HTTP-ответа после записи импортного пакета на сервере.

```bash
php scripts/verify-release.php
cd admin
npm run test:contracts
npm run test:static
# Только на staging с отдельной PostgreSQL, активным Nuxt и нужными env:
npm run test:e2e:preflight && npm run test:e2e
```

[GitHub Docs — Complete Inventory Admin UI & E2E](docs/Complete-Inventory-Admin-UI-E2E.md). Новых SQL-миграций и REST endpoints нет. В текущей среде нет `pdo_pgsql` и npm registry; внешний PostgreSQL integration, Nuxt production build и браузерный E2E должны быть подтверждены отдельно. Отсутствующие `composer.lock` и `admin/package-lock.json` по-прежнему блокируют strict release-gate.

## Версия 0.2.8 — Inventory Import / Export & Credential Bindings

Рабочий bounded CSV/JSON import с preview, PostgreSQL batches и queue worker, отчётами по строкам, идемпотентностью и использованием 0.2.7 reconciliation. Workspace-aware экспорт CSV/JSON с защитой от spreadsheet formulas и без секретов. Привязки **существующих** credentials к assets с контролем workspace через FK (без выдачи `encrypted_payload`). Admin UI `/transfer`.

```bash
php bin/cajeer migrate
php bin/cajeer migrate:validate
php tests/transfer-smoke.php
php scripts/verify-release.php
# Только на PostgreSQL 17+/18 в cajeer_test_*:
CAJEER_PG_TEST=1 CAJEER_PG_TEST_DATABASE=cajeer_test_transfer php tests/transfer-postgres-integration.php
```

Инструкция: [GitHub Docs — Inventory Import/Export & Credentials](docs/Inventory-Transfer-Credentials.md). Реальный PostgreSQL integration, frontend Nuxt build и браузерный E2E необходимо подтвердить в целевом окружении.

## Версия 0.2.7 — Inventory History, Provenance & Reconciliation

Новая реальная PostgreSQL-история изменений объектов, происхождение полей, приём idempotent
наблюдений источника `import`, отклонение устаревших событий, сохранение ручных overrides и
конфликтов, безопасное восстановление прежних значений. Доступ ограничен RBAC, членством в
workspace, серверной сессией и CSRF. Рабочая панель `/history`.

```bash
php bin/cajeer migrate
php bin/cajeer migrate:validate
php tests/history-smoke.php
# Только изолированная PostgreSQL БД cajeer_test_*:
CAJEER_PG_TEST=1 CAJEER_PG_TEST_DATABASE=cajeer_test_history \
  php tests/history-postgres-integration.php
```

Подробности: [GitHub Docs — Inventory History](docs/Inventory-History-Provenance.md).

## Версия 0.2.3 — Inventory Assets & CRUD Engine

Реальный PostgreSQL Inventory CRUD: типизированные ручные объекты (servers, clusters, VMs,
containers, routers, workstations), фильтрация/пагинация, workspace-aware RBAC,
CSRF, атомарный audit, versioned ETag/If-Match, мягкое удаление и восстановление.
Рабочий Nuxt `/inventory` получает и сохраняет данные исключительно через PHP API.
Изменения источников Proxmox/VMmanager вручную заблокированы.

```bash
php bin/cajeer migrate
php bin/cajeer migrate:validate
php tests/inventory-smoke.php
# Только отдельная PostgreSQL БД cajeer_test_*, не production:
CAJEER_PG_TEST=1 CAJEER_PG_TEST_DATABASE=cajeer_test_inventory \
    php tests/inventory-crud-postgres-integration.php
```

Подробности: [docs/Inventory.md](docs/Inventory.md). Старые миграции не изменялись.
Реальные PostgreSQL/Redis, Nuxt build и E2E требуют проверки в целевой среде.

## Версия 0.2.2 — Organizations, Workspaces & Ownership

Рабочая PostgreSQL-модель организаций, рабочих пространств и участников (owner/manager/viewer),
изолированные списки существующих CMDB-объектов, назначение владельцев, перенос между
пространствами одной организации с обязательными RBAC/CSRF и атомарным аудитом.
Миграция `0009_organizations_workspaces.sql` сохраняет UUID и содержимое старых Inventory-записей,
а новым администраторам инсталлятор назначает владельца default workspace.
Admin UI: `/workspaces`; REST API: `/api/v1/organizations`, `/api/v1/workspaces`.

```bash
php bin/cajeer migrate
php bin/cajeer migrate:validate
php tests/workspaces-smoke.php
```

Подробности: [docs/Organizations-and-Workspaces.md](docs/Organizations-and-Workspaces.md).

**Полный Inventory CRUD пока не реализован** (целевая 0.2.3). PostgreSQL integration, Nuxt build,
Composer/npm lock-файлы и browser E2E требуют проверки на целевой инфраструктуре.

## Версия 0.2.1 — Core Stabilization & Inventory Readiness

Добавлен **работающий PostgreSQL-предпроверяющий механизм** `InventoryReadiness`: проверяет существующую
таблицу `inventory_assets`, типы колонок, пустые обязательные поля и JSONB-форматы, не
разглашая содержимое инфраструктурных объектов. Миграция `0008_inventory_readiness.sql`
вводит восемь проверяемых ограничений и четыре индекса, не изменяя старые записи.

```bash
php bin/cajeer inventory:preflight --json        # только чтение текущей БД
php bin/cajeer migrate                           # после backup
php bin/cajeer inventory:constraints --apply     # после очистки legacy данных
php bin/cajeer inventory:preflight --strict --json
php tests/inventory-readiness-smoke.php
```

Операции `inventory:constraints --apply` и `migrate` меняют SQL-схему — использовать
с запланированным окном обслуживания и резервной копией. Доступ к Inventory CRUD не
открыт (запланирован на 0.2.3); пользовательские данные не переписываются.
Подробно: [docs/Inventory-Readiness.md](docs/Inventory-Readiness.md).

**Локальная проверка:** регрессионные native smoke; реальный PostgreSQL/Redis,
Nuxt build, браузерный E2E и воспроизводимые lock-файлы остаются непроверенными,
так как в среде отсутствуют PostgreSQL, `pdo_pgsql`, Composer и сетевой npm registry.
`release:check --strict` должен продолжать возвращать отказ, пока условия не выполнены.

## Версия 0.2.0 — Production-oriented Core

Этот релиз включает **рабочую проверку готовности к развёртыванию** и исполняемые
регрессионные сценарии поверх функционала 0.1.1–0.1.9: единая версия релиза,
`release:check`, production gate по PostgreSQL/Redis/queue/scheduler/storage/миграциям,
согласованность marker установки с БД, проверка SSL/TLS и изоляции файлов,
проверки настоящих lockfiles и Nuxt SSR build. Добавлены авто-проверки внешнего HTTP
и отдельные интеграционные тесты для PostgreSQL 17+/Redis.

```bash
php bin/cajeer release:check --json
php scripts/verify-release.php
# После установки всех зависимостей, настройки isolated staging и проверки deployment:
php bin/cajeer release:check --strict --json
CAJEER_SMOKE_URL=https://staging.example.com php scripts/smoke-http.php
```

**Ограничение проверки:** в среде сборки недоступны PostgreSQL, pdo_pgsql и npm registry.
Настоящие `composer.lock`, `admin/package-lock.json`, Nuxt build и browser E2E здесь
не получены. `--strict` **не будет** сообщать об успешном production-допуске до их появления.
Это сознательная защита от фиктивной готовности. Инструкция и все команды —
[docs/Production-Core.md](docs/Production-Core.md).

Архитектура остаётся веб-ресурсом — не ОС и не заменой Proxmox/VMmanager.
До последующих версий коннекторы внешних инфраструктурных систем остаются недоступны.

## Версия 0.1.9 — Admin UI & End-to-End Integration

Nuxt 3 SSR Admin UI с реальными PHP API: вход с TOTP, управление пользователями и ролями, sessions/API tokens, 2FA, журнал аудита, системный dashboard и диагностические задания через queue. Сервер проверяет RBAC, CSRF и cookie-сессию для каждой операции; API-токены не обладают правами Admin UI.

**Патч:** поверх 0.1.8. Перед миграцией сохраните backup PostgreSQL, затем `php bin/cajeer migrate`; новая миграция `0007_admin_ui_permissions.sql` назначает 4 новых разрешения только administrator. Сборка UI: `cd admin && npm install && npm run build`. Для Nginx используйте схему same-origin из `examples/nginx/cajeerservers-tls.conf`. Подробности: [docs/Admin-UI.md](docs/Admin-UI.md).

**Проверки:** `php tests/admin-smoke.php`, `node admin/tests/static-check.mjs`; PostgreSQL интеграция и Playwright E2E требуют отдельного изолированного стенда. В данной среде отсутствие PostgreSQL и npm-зависимостей не позволяет заявить полный E2E-прогон или подтвердить Nuxt production build. Proxmox/VMmanager и Inventory API не объявлены работающими.

## Версия 0.1.8 — Observability & Operational CLI

Реальные liveness/readiness probes, диагностика PostgreSQL/Redis/Queue/Scheduler/Local/S3,
запись HTTP/queue metrics в Prometheus exposition format, OTLP/HTTP JSON spans, защищённый
scrape endpoint и эксплуатационные команды, включая system-report, stats и bounded retention.
Используйте `php bin/cajeer health:live`, `php bin/cajeer health:ready`,
`php bin/cajeer doctor:deep`, `php bin/cajeer metrics:render` и
`php tests/observability-smoke.php`. Полные настройки — [docs/Observability.md](docs/Observability.md).

**Patch:** поверх 0.1.7. Metrics endpoint по умолчанию закрыт и включается только
с отдельным scrape token. Нет открытых инфраструктурных mutation API. Реальные Redis/PostgreSQL/S3
интеграционные тесты в целевой среде обязательны перед production rollout.

## Версия 0.1.7 — Cache, Queue, Scheduler & Storage

Рабочие драйверы Redis cache (RESP2/TLS/ACL), очередей Redis (атомарный Lua) и PostgreSQL (SKIP LOCKED/lease fencing), worker с allowlist типов задач, планировщик PostgreSQL с cron/таймзонами/transactional outbox, локальное атомарное и S3-compatible SigV4 хранилище. Не запускает произвольные PHP/shell-команды из заданий. Доступ только через локальный CLI; инфраструктурные HTTP API не публикуются.

**Патч:** поверх 0.1.6; сделать backup PostgreSQL и выполнить `php bin/cajeer migrate`, затем `php tests/infrastructure-smoke.php`. Описание конфигурации, команд и эксплуатации: [docs/Queue.md](docs/Queue.md), [docs/Scheduler.md](docs/Scheduler.md), [docs/Storage.md](docs/Storage.md).

**Проверено:** native tests, локальный HTTP mock S3 с независимой проверкой SigV4, RESP2 transport fixture, LocalDisk и cron/DST. **Не проверено в текущей среде:** реальный Redis server и PostgreSQL 17/18 — интеграционные тесты предусмотрены, но серверов/`pdo_pgsql` нет. Это не означает полной production-сертификации.

## Версия 0.1.6 — RBAC, Security & Audit

Добавлена **исполняемая реализация** PostgreSQL RBAC (7 разрешений релиза 0.1.6, пользователи/роли/назначение/отзыв), server-side CSRF, общего лимитирования входа, TOTP 2FA с одноразовыми recovery codes, XChaCha20-Poly1305 шифрования секретов, HMAC-подписанных webhooks с persisted inbox и replay protection, транзакционного append-only security audit, а также дополнительных защитных HTTP-заголовков. На новых операциях не возвращаются фиктивные результаты: действие либо записано и проверено в PostgreSQL, либо завершается ошибкой. Деструктивные операции Proxmox/VMmanager по-прежнему не опубликованы.

**Обновление:** 0.1.5 → 0.1.6, резервная копия PostgreSQL, `php bin/cajeer migrate`, `php bin/cajeer migrate:validate`. Прямое применение поверх более старых версий не проверялось. Рабочие маршруты, права и правила MFA: [docs/Security.md](docs/Security.md). Native smoke: `php tests/security-smoke.php`; интеграционный тест на изолированной PostgreSQL: `php tests/security-postgres-integration.php`. В текущей среде PostgreSQL и `pdo_pgsql` недоступны, реальная БД не проверена: эксплуатационную готовность необходимо подтвердить перед публичным развёртыванием.

## Версия 0.1.5 — Authentication & API Tokens

Реализована **аутентификация через PostgreSQL и самоуправление учётными данными**:

- `POST /api/v1/auth/login` — проверка Argon2id, ограничение попыток входа, 12-часовая серверная сессия с `HttpOnly`, `SameSite=Strict`, `Secure` для HTTPS. Авторизация сохраняется в PostgreSQL; session ID и 256-битный случайный секрет проверяются на каждом запросе.
- `GET /api/v1/auth/me`, `/auth/csrf`, `/auth/sessions`, `/auth/tokens` — доступ к собственным сессиям, профилю и метаданным токенов; для bearer required explicit read-scope.
- `POST /api/v1/auth/logout`, `/auth/password`, `/auth/tokens`; `DELETE /api/v1/auth/tokens/{id}`, `/auth/sessions/{id}` — реальные операции с проверкой авторизованной cookie-сессии и `X-CSRF-Token`.
- API bearer tokens имеют формат `at1.<uuid>.<256-bit-secret>`; только SHA-256 полного токена хранится в PostgreSQL, сырой токен возвращается **однократно**, отзыв и expiry применяются при каждом запросе.
- Минимальные scopes: `profile:read`, `sessions:read`, `tokens:read`. Bearer-токены **не могут** создавать/отзывать токены, менять пароль, закрывать сессии или выполнять инфраструктурные действия.
- Смена пароля транзакционно отзывает **все** сессии и API-токены владельца. Для пользователей с включённым 2FA дополнительно требуется действующий TOTP или одноразовый recovery code (с 0.1.6).
- PostgreSQL migration `0004_authentication.sql`: сессии, блокировки входа, `revoked_at` для API-токенов, деактивация пользователей и уникальность email без учёта регистра. Нужна перед первым входом.

**Установка:** применить патч поверх 0.1.4 → `php bin/cajeer migrate` → открыть API login. Для существующей установки повторно запускать `install` нельзя. Подробный пример запросов и требования к Nginx: [docs/Authentication.md](docs/Authentication.md).

**Релиз 0.1.5 не добавляет RBAC/2FA/инфраструктурные API.** Реальные PostgreSQL integration-тесты запускаются **отдельно** и не считаются подтверждёнными при отсутствии `pdo_pgsql` и PostgreSQL 17/18. Native smoke: `php tests/auth-smoke.php`; integration: `php tests/auth-postgres-integration.php` с явными переменными тестовой БД.

## Версия 0.1.4 — Installer & Initial Setup

В 0.1.4 реализован **настоящий одноразовый установщик**, а не CLI-заглушка:

- `php bin/cajeer install` и `installer/cli/install.php` — интерактивная/неинтерактивная настройка без пароля в аргументах командной строки; секреты можно прочитать из файлов.
- `php bin/cajeer install:check`, `install:status`, `install:web-token`, `install:recover` — preflight, status, одноразовая активация web installer и восстановление marker после сбоя.
- `GET/POST /install` — HTTP-установка через `public/index.php`, session CSRF, одноразовый код из серверного CLI, HTTPS (или loopback в local), запрет повторной установки.
- Проверка PostgreSQL 17+, реальный migration runner 0.1.3, создание первого администратора с Argon2id-хешем и ролью `administrator` в одной транзакции с DB installation marker и записью аудита.
- `.env` записывается атомарно с правами 0600 и случайным APP_KEY; `storage/app/installed.json` записывается только после DB commit; специальная команда восстановления marker.
- Native smoke-тесты установщика и отдельный интеграционный тест на **пустой одноразовой PostgreSQL**.

Полная инструкция: [docs/Installation.md](docs/Installation.md). Установка не открывает доступ к серверным действиям: login, RBAC enforcement и управление инфраструктурой появятся в следующих релизах.

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

Native smoke-tests выполняются без Composer. GitHub CI проверяет PHP 8.4 и 8.5, PHPUnit запускается после установки пакетов. `composer.lock` пока не сгенерирован, поскольку Composer и доступ к registry в среде подготовки отсутствовали.

## Структура

- `core/Runtime/` — Request/Response, error handler и диагностика.
- `core/Http/` — HTTP Kernel, Router, middleware, JSON validation, exceptions.
- `routes/api.php` — реальные маршруты (только root, health, system).
- `core/Api/Controller/` — рабочие controllers для health/system; оставшиеся контроллеры не зарегистрированы.
- `api/openapi.yaml` — API 3.1.1 только для реализованных маршрутов.
- `public/index.php` — front controller.
- `tests/http-smoke.php` — native HTTP tests.

Следующая версия **0.1.5 — Authentication & API Tokens**.


### Network Inventory / IPAM (0.2.4)

Новый раздел `/ipam`: сети IPv4/IPv6, интерфейсы и резервация адресов через PostgreSQL и REST API. Инструкция: [`docs/Network-Inventory-IPAM.md`](docs/Network-Inventory-IPAM.md). Приложение является веб-ресурсом и не изменяет сетевые настройки устройств напрямую.

### Infrastructure Relationships & Topology (0.2.5)

Новый раздел `/topology` показывает хранимые в PostgreSQL связи инфраструктурных объектов и read-only связи IPAM. CRUD отношений защищён RBAC, workspace, CSRF и ETag; PostgreSQL блокирует циклы, неподходящие пары и перенос/soft-delete связанных объектов. Полное описание: [Infrastructure Relationships & Topology](docs/Infrastructure-Relationships-Topology.md). Действия на реальном оборудовании не выполняются.

### Tags, Groups, Search & Filtering (0.2.6)

- Реляционный каталог тегов и групп с workspace-изоляцией, ETag, RBAC, CSRF и аудитом.
- Автоматическая нормализация старых `inventory_assets.tags` и синхронизация новых изменений триггером PostgreSQL.
- Фильтры по тегу, группе, окружению, владельцу, типу, статусу, ОС и источнику; полнотекстовый поиск по имени/external ID и IP lookup.
- `GET /api/v1/inventory/tags`, `/groups`, `/assets` и UI `/catalog`, `/inventory`.
- Миграция `migrations/pgsql/0013_inventory_catalog_search.sql`, `docs/Inventory-Catalog-Search.md`.
- Полный production-gate требует PostgreSQL integration, Nuxt build и browser E2E.

### Vault 0.3.1: криптографический runtime

Секреты Vault шифруются независимым ключом. Пример выпуска: `php bin/cajeer vault:key:generate --output=/etc/cajeerservers/vault.key`; настройте приватный `VAULT_MASTER_KEY_FILE`, примените `php bin/cajeer migrate`, затем `php bin/cajeer vault:init` однократно и `php bin/cajeer vault:check`. `vault:probe` выполняет реальный шифрованный roundtrip в PostgreSQL с гарантированным ROLLBACK. Полные инструкции — `docs/Vault-Cryptography-0.3.1.md`. Обычный HTTP API **не раскрывает** секреты.
