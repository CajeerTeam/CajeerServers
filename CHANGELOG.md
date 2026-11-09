# Changelog

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
