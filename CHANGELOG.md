# Changelog

## [0.2.1] — Core Stabilization & Inventory Readiness (2026-11-09)

- Рабочий `InventoryReadiness`: real PostgreSQL audit колонок и агрегированных данных, нет выдачи hostname/IP/attributes в CLI.
- Транзакционная `0008_inventory_readiness.sql`: восемь check constraints `NOT VALID` и четыре индекса без изменения данных предыдущих релизов.
- CLI `inventory:preflight [--strict] [--json]` и `inventory:constraints [--apply]`: контроль данных и явная валидация существующих строк.
- Strict release-gate блокируется при невалидных данных, невалидированных ограничениях и неприменённых миграциях.
- Native PDO-double smoke, независимый PostgreSQL upgrade integration на изолированной схеме, миграционный rollback probe 0009, расширены GitFlic CI и release verification.
- Синхронизированы VERSION, release.json, OpenAPI и Nuxt metadata; исходные миграции 0001–0007 не изменены.
- **Не подтверждены локально:** реальный PostgreSQL/Redis, Composer/npm dependency resolution, Nuxt production build, Playwright browser E2E. Lock-файлы намеренно не подделывались; production-gate должен оставаться закрытым без этих проверок.

## [0.2.0] — Production-oriented Core (2026-11-09)

- Единая SemVer 0.2.0 в ядре, release.json, Nuxt и OpenAPI; исправлен устаревший installer marker.
- Рабочий `release:check` со строгой проверкой runtime, приватной `.env`, версий,
  миграций/checksums, installed database marker, PostgreSQL 17+, Redis, queue, storage,
  TLS remote database и повторяемых зависимостей.
- `scripts/verify-release.php` исполняет native regression suites и в strict-режиме
  интеграцию PostgreSQL/Redis, PHPUnit, Nuxt typecheck/build и браузерный Playwright E2E.
- `scripts/smoke-http.php` проверяет настоящий удалённый HTTPS без выполнения изменений.
- Исправлена интеграционная PostgreSQL rollback-проба 0008 после 0007,
  тест установщика использует отдельную пустую тестовую базу.
- Добавлены GitFlic CI integration job и script генерации настоящих dependency lockfiles.
- Локально проверены native smoke, PHP lint и Nuxt static. **Не проверены локально:**
  установка npm/composer зависимостей, Nuxt production build, живые PostgreSQL/Redis,
  внешний Nginx deployment и E2E. Строгий production gate без них не проходит.

## [0.1.9] — Admin UI & End-to-End Integration (2026-11-09)

- Настоящие страницы Nuxt 3: login/TOTP, operational dashboard, users/roles, sessions/API tokens, MFA, audit, diagnostics, jobs и scheduler.
- Session-only admin API с RBAC + CSRF; 4 новых permissions, миграция 0007 и default-deny для остальных ролей.
- Разрешено ставить только `runtime.diagnostics` и `storage.probe`; сохранённый job status доступен без payload/result/секретов.
- Single-origin Nginx TLS reverse proxy для Nuxt SSR и PHP-FPM, защищённые cookies, TypeScript composables и client route guard.
- Native admin smoke, подготовленный PostgreSQL integration test и Playwright E2E, документация развёртывания.
- Подтверждено локально: PHP lint, native smoke, статическая проверка интерфейса. **Не подтверждено:** Nuxt build и E2E на PostgreSQL/Redis, поскольку необходимые зависимости/сервисы недоступны в этой среде.

## [0.1.8] — Observability & Operational CLI (2026-11-09)

- Реальные public liveness (`/api/v1/live`) и readiness (`/api/v1/ready`), Runtime-only `/health` сохранён.
- PostgreSQL/Redis/queue/scheduler/storage diagnostics; недоступные зависимости fail closed, без утечки секретов.
- Prometheus HTTP histogram/counters, worker outcome counters и dependency gauges, bounded labels и persistent 0600 file-lock store.
- Opt-in, token-protected `/api/v1/metrics` (text/plain), HTTPS-only в production.
- Opt-in OpenTelemetry OTLP/HTTP JSON для HTTP и queue, без пользовательских URL, payload и токенов.
- CLI health:live, health:ready, doctor:deep, system:report, metrics:render, queue:stats, scheduler:stats, security:prune, openapi:lint с реальными exit codes.
- Замена placeholder entrypoints, конфигурация, OpenAPI 3.1.1, Wiki, пример Prometheus alerts, native observability smoke.
- Локально проверены метрики, HTTP-скрейп, трассы через HTTP fixture и регрессии. Продуктовые PostgreSQL/Redis/S3-интеграции здесь не запускались.


## [0.1.7] — Cache, Queue, Scheduler & Storage (2026-11-09)

- Реальный Redis client (RESP2, AUTH/ACL, TLS, binary safety), TTL cache с атомарным INCRBY.
- Реальные Redis (Lua atomic claims) и PostgreSQL (SKIP LOCKED) queues: idempotent enqueue, lease token fencing, retries, failed/retry, stored results, retention prune.
- Allowlisted JobWorker: `runtime.diagnostics`, `storage.probe`, `queue.prune`; отказ от исполнения пользовательских команд и кода.
- PostgreSQL scheduler с 5-field cron, DST/timezone handling, SKIP LOCKED, транзакционным outbox и устойчивой повторной отправкой.
- LocalDisk с атомарной записью, symlink/traversal guards; S3-compatible SigV4 HTTPS GET/PUT/HEAD/DELETE, 16 MiB limit.
- CLI для cache, queue, scheduler и storage; `0006_queue_scheduler.sql`, environment options, Wiki и native infrastructure smoke.
- Автоматизированно проверены LocalDisk, cron, Redis RESP2 fixture, S3 SigV4 HTTP mock, worker allowlist; **не** подтверждён реальный Redis/PG интеграционный прогон (сервисы отсутствуют в среде).
- Не заявлены: RabbitMQ, S3 multipart upload, произвольное выполнение shell задач, production-ready deployment.

## [0.1.6] — RBAC, Security & Audit (2026-11-09)

- PostgreSQL migration `0005_security_audit.sql`: актуальные permission grants для administrator, MFA/recovery/limiter/webhook tables, append-only audit trigger.
- Ролевые и пользовательские операции с принудительной проверкой прав, anti-escalation checks, запретом самоотключения и удаления последнего администратора.
- Полноценный RFC 6238 TOTP, XChaCha20-Poly1305 secret storage, single-use recovery codes и защита от повторного TOTP counter. Логин/сессии/токены поддерживают активную MFA; старые сессии и токены отзываются при включении.
- Signed webhook inbox: HMAC-SHA256, пяти минутное временное окно, PostgreSQL uniqueness replay protection, ограничения размера и rate limit. Входящие события сохраняются, **не исполняются**.
- Аудит событий управления и отказов, metadata allowlist; `audit_log` запрещает UPDATE/DELETE на уровне БД.
- Доработаны CSRF, application security headers, scope checks; добавлены native security smoke tests, отдельный PostgreSQL integration test и OpenAPI 3.1.1.
- Не проверено здесь: реальные транзакции на PostgreSQL 17/18 (в среде отсутствуют `pdo_pgsql` и сервер PostgreSQL). Следующие задачи: queue/scheduler/cache/storage в 0.1.7.

## [0.1.5] — Authentication & API Tokens (2026-11-09)

### Added
- Сессионная аутентификация: Argon2id, PostgreSQL sessions, уникальные 256-битные секреты, HttpOnly/SameSite=Strict/Secure cookie, индивидуальное и массовое завершение сессий.
- Работающее API: login, logout, me, CSRF, смена пароля, получение и отзыв собственных сессий/API-токенов.
- API bearer tokens с хранением только SHA-256, expiration/revocation, read-only scopes; токен возвращается ровно один раз.
- DB-based throttle неуспешных входов, защищённый Origin/CSRF, проверка активного пользователя, атомарное отозвание токенов и audit events.
- PostgreSQL migration 0004, native authentication tests и отдельный PostgreSQL integration scenario; OpenAPI 3.1.1 актуализирован.

### Security
- Привилегированные инфраструктурные действия остаются отключены до RBAC; API-токены не могут выполнять password/session/token mutations.
- Для учётных записей с `two_factor_enabled=true` password-only login заблокирован до реализации 2FA в 0.1.6.

### Known limitations
- В текущей среде нет pdo_pgsql/PostgreSQL, поэтому реальная интеграция с БД и полный E2E login не были проверены.
- Полноценные RBAC/2FA, глобальный rate limiting и Admin UI запланированы отдельно.

## [0.1.4] — Installer & Initial Setup (2026-11-09)

### Added
- CLI installer with real PostgreSQL schema creation, administrator provisioning (Argon2id), administrator role, audit event.
- Database-backed singleton installation state in `0003_installation_state.sql`, advisory-locked initial provisioning transaction.
- Web Installer at `/install` (HTTPS / local-only HTTP), one-time server-issued setup token and session CSRF.
- Atomic `.env` writer with 0600 permissions; cryptographically random APP_KEY generation.
- Installation status, preflight, recovery of local marker from committed DB state.
- Installer smoke and isolated PostgreSQL integration test; safer Nginx PHP front-controller config.

### Notes
- Actual PostgreSQL 17/18 integration needs `ext-pdo_pgsql` and a disposable test database.
- Authentication/login UI not yet available; creating an administrator record does not imply completed auth.


## [0.1.3] — Database & Migration Engine (2026-11-09)

### Added
- Настоящие PostgreSQL PDO connections, транзакции/savepoints и безопасный DSN builder.
- Атомарное выполнение SQL-миграций под PostgreSQL advisory lock; SHA-256 journal, контроль истории, dry-run.
- SQL statement parser с поддержкой PL/pgSQL dollar quoting и вложенных комментариев.
- CLI database:check, migrate, migrate:status, migrate:pending, migrate:validate и совместимые standalone entrypoints.
- Миграция role/permission associations и индексов; native database smoke-tests и изолированный PostgreSQL integration test.

### Security
- Предотвращение незаметного изменения/пропажи применённых миграций.
- Запрет незадекларированного baseline и out-of-order SQL; запрет миграций MySQL до достижения parity.

### Known limitations
- PostgreSQL integration test требует отдельного реального PostgreSQL 17/18 и ext-pdo_pgsql; CI без DB не подтверждает integration.
- Отсутствует Composer lockfile; дополнительные API/аутентификация не публикуются.

## 0.1.2 — HTTP Kernel & REST API (2026-11-09)

- Работающий router, middleware pipeline, динамические параметры пути, HEAD/OPTIONS/405 и консистентные problem+json ответы.
- Реальные endpoints `/api/v1/health` (проверка runtime readiness) и `/api/v1/system` (публичные метаданные); незавершённые endpoints не публикуются.
- Symfony HttpFoundation request/response adapter при наличии пакета; native fallback для самостоятельной диагностики без Composer.
- Ограничение входного тела до 1 МиБ, строгий JSON parser/validator, защищённые HTTP-заголовки, request ID, структурированные access logs.
- Native HTTP smoke и PHPUnit feature tests; OpenAPI 3.1.1 отражает только реально существующие API-операции.


Формат основан на Keep a Changelog, версия проекта следует SemVer.

## [0.1.1] - 2026-11-09

### Added
- Исполняемый PHP bootstrap и жизненный цикл приложения (boot/handle/terminate).
- Dotenv loader с приоритетом process environment и типизированными значениями.
- Ленивая конфигурация по вложенным ключам с кешированием и защитой путей.
- DI: instance, singleton, transient, alias, autowire, обнаружение циклов.
- JSONL runtime logging с редактированием секретов и обработкой ошибок.
- HTTP runtime: root JSON, 404, 405, 503, request ID, HEAD support.
- CLI: version, about, config:get, doctor, config:check, self:test с реальными exit codes.
- Native smoke tests, PHPUnit runtime tests, GitFlic CI, документация запуска.

### Changed
- Неисполняемые команды `migrate`, `queue:work` и другие завершаются кодом 3 вместо ложного успеха.
- Не реализованный установщик явно сообщает о недоступности.
- `.env.example` больше не содержит фиктивных ключей/паролей.

### Known limitations
- HTTP `/api/v1/*`, auth, БД, установка, очереди и коннекторы — не реализованы в 0.1.1.
- `composer.lock` не генерировался без Composer/доступа к репозиториям; перед воспроизводимой поставкой его нужно создать и проверить.

## [0.1.0] - 2026-06-15

### Added
- Начальный skeleton CajeerServers.
- README, ROADMAP, SECURITY, CONTRIBUTING, CODE_OF_CONDUCT.
- OpenAPI 3.1.1 skeleton.
- PHP backend core skeleton.
- Nuxt/Vue admin skeleton.
- Inventory, connectors, collectors, scripts, sync engine и action dispatcher placeholders.
- PostgreSQL/MySQL migration skeleton.
- GitFlic Wiki стартовые страницы.
