# Roadmap CajeerServers

Roadmap основан на SemVer, LTS и security-release модели. Приоритет — сначала стабильное ядро, затем интеграции, автоматизация и экосистема расширений.

## 0.1.0 — Foundation / Skeleton

Цель: зафиксировать структуру, технические контракты и базовые доменные модели.

- [x] Начальная структура репозитория.
- [x] README, ROADMAP, SECURITY, CONTRIBUTING, CHANGELOG.
- [x] PHP 8.4+ backend skeleton.
- [x] PHP 8.5 compatibility target.
- [x] Nginx example config.
- [x] Apache optional example.
- [x] Composer package skeleton.
- [x] Nuxt/Vue admin skeleton.
- [x] OpenAPI 3.1.1 skeleton.
- [x] PostgreSQL migration skeleton.
- [x] MySQL/MariaDB compatibility migration skeleton.
- [x] Extension manifest examples.
- [x] CLI `cajeer` command shell.
- [x] GitFlic Wiki стартовые страницы.

## 0.1.1 — Application Bootstrap & Runtime (выполнено)

- [x] Единый bootstrap, загрузка .env, ConfigRepository, DI container.
- [x] Application lifecycle и обработка ошибок HTTP/CLI.
- [x] Рабочие CLI диагностика, self-test, коды ошибок.
- [x] Runtime logging, native smoke tests, GitFlic CI.
- [ ] Composer lockfile (сгенерировать в сетевой среде с Composer 2 и проверить).

## 0.1.2 — HTTP Kernel & REST API (выполнено)

- [x] Рабочий HTTP Kernel, group routes, GET/POST/PUT/PATCH/DELETE, HEAD/OPTIONS, path parameters, 404/405.
- [x] Symfony HttpFoundation request/response integration при установленном Composer; native runtime fallback для диагностики.
- [x] Middleware: body-size, Accept, security headers, access logging, request ID.
- [x] Bounded JSON parsing, per-route validation, 400/406/413/415/422; problem+json errors.
- [x] Реальные публичные `/api/v1/health` и `/api/v1/system` без имитации управления инфраструктурой.
- [x] HTTP native smoke suite, PHPUnit feature tests, актуализирован OpenAPI.
- [ ] Composer lockfile / network integration tests in deployment environment.

**Не входят в 0.1.2:** authentication/RBAC, state-changing API, real PostgreSQL/Redis/Proxmox/VMmanager/collectors.

## 0.1.3 — Database & Migration Engine (реализовано)

- [x] PostgreSQL PDO connection factory с TLS-настройками и защитой DSN.
- [x] DatabaseManager: транзакции, вложенные savepoints, retry serialization/deadlock.
- [x] Реальный SQL migration runner: advisory lock, atomic DDL, SHA-256 checksum, migration journal.
- [x] CLI `database:check`, `migrate`, `migrate:status`, `migrate:pending`, `migrate:validate`, `--dry-run`.
- [x] PostgreSQL role/permission pivots и индексы через `0002_rbac_relations.sql`.
- [x] Native database smoke-test; отдельный сценарий PostgreSQL integration test.
- [ ] Реальный PostgreSQL 17/18 integration run в CI / deployment environment.
- [ ] MySQL/MariaDB migration parity (отложена; мигратор не разрешает применять неполную схему).

**Не входят в 0.1.3:** installer, auth, RBAC middleware, открытие state-changing API, inventory CRUD, очередь и collectors.

## 0.1.4 — Installer & Initial Setup (реализовано)

- [x] Настоящий CLI installer, preflight PHP/DB, конфигурация, APP_KEY, секреты из файлов.
- [x] Web Installer `/install`: out-of-band setup token, session CSRF, TLS / local-only HTTP.
- [x] Выполнение миграций и создание первого администратора (Argon2id) с ролью и записью аудита.
- [x] PostgreSQL singleton installation state, атомарный локальный marker и `install:recover`.
- [x] Smoke tests и отдельный сценарий реальной PostgreSQL integration проверки.
- [ ] Запуск integration test на PostgreSQL 17/18 в CI/CD; в среде разработки 0.1.4 отсутствует pdo_pgsql.

## 0.1.5 — Authentication & API Tokens (реализовано)

- [x] Реальный вход/выход, PostgreSQL sessions, Argon2id, проверка активности пользователя.
- [x] Безопасная смена пароля: отзыв всех сессий и bearer-токенов.
- [x] API token issuing, SHA-256 storage, expiry, revocation и read-only scopes.
- [x] Session-only protected mutations с CSRF и same-origin guard.
- [x] Login throttling и audit событий аутентификации.
- [x] Native auth smoke-тесты и отдельный PostgreSQL integration test.
- [ ] Подтверждение PostgreSQL 17/18 и composer-backed E2E в CI/deployment environment.

## 0.1.6 — RBAC, Security Middleware & Audit (реализовано)

- [x] PostgreSQL RBAC, реальные проверки users/roles/permissions, защита от эскалации и потери последнего администратора.
- [x] TOTP 2FA, encrypted secrets, recovery codes, блокировка повторного counter.
- [x] Session-only + CSRF + Same-Origin для привилегированных операций; API tokens read-only.
- [x] Login IP limiter, MFA и webhook rate limiting, Security Headers/HSTS.
- [x] Подписанные webhook events, timestamp/HMAC/replay-check, durable inbox без исполнения.
- [x] Append-only PostgreSQL audit log с разрешёнными полями метаданных.
- [x] Native security smoke, документация, OpenAPI 3.1.1 и отдельный PostgreSQL integration test.
- [ ] Реальный прогон PostgreSQL 17/18 integration test и production TLS verification в CI — не выполнялся в текущей среде.

## 0.1.7 — Cache, Queue, Scheduler & Storage (реализовано, требует реальной интеграционной проверки)

- [x] Redis RESP2 client без обязательного ext-redis, ACL/TLS, namespace и TTL cache.
- [x] Redis Lua queue и PostgreSQL SKIP LOCKED queue: persistent status/result, lease fencing, retries, DLQ, redrive, cleanup по сроку хранения.
- [x] Worker с конкретными разрешёнными обработчиками runtime.diagnostics, storage.probe, queue.prune; никаких произвольных команд.
- [x] PostgreSQL scheduler с timezone-aware cron, SKIP LOCKED и transactional outbox; idempotent job IDs при восстановлении отправки.
- [x] Local storage: atomic write, защита от traversal/symlinks, ограничение объектов; S3 HTTPS + AWS SigV4 GET/PUT/HEAD/DELETE.
- [x] Реальные CLI-команды, migration `0006_queue_scheduler.sql`, автономные smoke-тесты и проверка S3 SigV4 через HTTP fixture.
- [ ] Подтвердить очередь и scheduler на PostgreSQL 17/18 и Redis server в CI; интеграционные тесты доступны отдельно.
- [ ] Политика retention для security rate limits и webhook inbox (отдельное обслуживание; 0.1.8).
- [ ] RabbitMQ driver и multipart/streaming S3 для больших объектов — отложены.

## 0.1.8 — Observability & Operational CLI (реализовано, требуется проверка на внешних сервисах)

- [x] Liveness и readiness с реальными проверками PostgreSQL, Redis, queue, scheduler, storage.
- [x] Prometheus metrics с ограниченными метками, HTTP histogram, queue outcomes, dependency gauges.
- [x] Opt-in OpenTelemetry spans для HTTP и фоновых worker jobs.
- [x] CLI health:live/ready, doctor:deep, system:report, queue:stats, scheduler:stats, metrics:render, openapi:lint.
- [x] Bounded cleanup security rate limits/login attempts/webhook inbox/sessions с dry-run, без удаления audit_log.
- [x] Примеры внешних Prometheus alert rules, документированные параметры production и native smoke.
- [ ] Подтвердить PostgreSQL 17/18, Redis, внешний S3/OTLP collector, production reverse-proxy/TLS в CI.
- [ ] Встроенный notification dispatcher и долгосрочное распределённое time-series хранилище — отдельные релизы, в 0.1.8 не заявлены.

## 0.1.9 — Admin UI & End-to-End Integration (планируется)

- [ ] Реальные Nuxt страницы для auth/сессий/токенов/ролей, диагностики и queue status.
- [ ] E2E install → auth → schedule → worker → job result → audit.

## 0.2.0 — Production-oriented Core (планируется)

Цель: рабочее ядро приложения, маршрутизация, конфигурация и базовая безопасность.

- [ ] Service container.
- [ ] HTTP router.
- [ ] JSON response layer.
- [ ] Config repository.
- [ ] Database connection factory.
- [ ] Migration runner.
- [ ] Redis cache adapter.
- [ ] Queue abstraction.
- [ ] Scheduler abstraction.
- [ ] Local/S3 storage abstraction.
- [ ] Base auth flow.
- [ ] RBAC skeleton.
- [ ] API token scopes.
- [ ] CSRF protection.
- [ ] Security headers middleware.
- [ ] Rate limiter.
- [ ] Audit logger.

## 0.3.0 — Inventory / CMDB

Цель: единая модель инфраструктуры.

- [ ] Organizations / workspaces.
- [ ] Servers.
- [ ] Nodes.
- [ ] Clusters.
- [ ] Virtual machines.
- [ ] Containers.
- [ ] Routers.
- [ ] Workstations.
- [ ] Operating systems.
- [ ] IP addresses.
- [ ] Networks.
- [ ] Tags and groups.
- [ ] Owners and environments.
- [ ] Credential bindings.
- [ ] Inventory import/export.
- [ ] Inventory history.

## 0.4.0 — Credentials Vault

Цель: безопасное хранение данных доступа к внешним системам.

- [ ] Encrypted credentials storage.
- [ ] API tokens vault.
- [ ] SSH key storage optional.
- [ ] SNMP community storage.
- [ ] WinRM/PowerShell credential profiles.
- [ ] Per-connector credentials.
- [ ] Credential rotation metadata.
- [ ] Secret access audit.
- [ ] Backup/export with encryption.
- [ ] Optional integration points for external vaults.

## 0.5.0 — Connector Framework

Цель: расширяемая connector-based архитектура.

- [ ] Connector manifest schema.
- [ ] Connector permissions.
- [ ] Connector health checks.
- [ ] Connector capabilities.
- [ ] Connector SDK for PHP.
- [ ] Connector SDK for TypeScript clients.
- [ ] Connection test flow.
- [ ] API polling handler.
- [ ] Webhook handler.
- [ ] Event handler.
- [ ] Action handler.
- [ ] Sync handler.
- [ ] Connector registry.
- [ ] Signed connector payloads.

## 0.6.0 — Data Collection Layer

Цель: гибкий сбор данных без превращения проекта в ОС.

- [ ] Custom scripts registry.
- [ ] Script templates.
- [ ] Script output parsers.
- [ ] Script permission model.
- [ ] Signed scripts.
- [ ] Script execution history.
- [ ] Dry-run mode.
- [ ] Collector registry.
- [ ] Exporter support.
- [ ] Webhook ingestion.
- [ ] Scheduled collection.
- [ ] Event ingestion.
- [ ] Metrics scraping.
- [ ] Manual import.

## 0.7.0 — Proxmox Integration

Цель: первая основная интеграция виртуализации.

- [ ] Proxmox connection profile.
- [ ] Proxmox API token auth.
- [ ] Cluster discovery.
- [ ] Node discovery.
- [ ] VM discovery.
- [ ] LXC discovery.
- [ ] Storage discovery.
- [ ] Network discovery.
- [ ] Task history import.
- [ ] Safe action dispatcher: start/stop/reboot.
- [ ] Snapshot metadata.
- [ ] Console URL placeholders.
- [ ] Proxmox Backup Server integration draft.

## 0.8.0 — VMmanager Integration

Цель: поддержка VMmanager как второго основного источника инфраструктурных данных.

- [ ] VMmanager connection profile.
- [ ] API auth.
- [ ] Servers import.
- [ ] VM import.
- [ ] IP import.
- [ ] Templates metadata.
- [ ] Task import.
- [ ] Safe action dispatcher.
- [ ] Sync status dashboard.

## 0.9.0 — OS / Network Connectors

Цель: получить данные с Windows/Linux/роутеров без OS-level зависимости.

- [ ] Debian/Ubuntu connector.
- [ ] Windows connector.
- [ ] Router connector.
- [ ] SSH optional capability.
- [ ] WinRM / PowerShell Remoting optional capability.
- [ ] SNMP collector.
- [ ] RouterOS API connector draft.
- [ ] Syslog ingestion draft.
- [ ] Network device config backup draft.

## 1.0.0 — Stable Core Release

Цель: первый стабильный релиз для production self-hosted установки.

- [ ] Stable installer.
- [ ] Web installer.
- [ ] CLI installer.
- [ ] PostgreSQL production migration set.
- [ ] Backup/restore CLI.
- [ ] Audit-grade logging.
- [ ] Health checks.
- [ ] System report.
- [ ] Queue/cache/storage diagnostics.
- [ ] Prometheus endpoint.
- [ ] OpenTelemetry traces.
- [ ] OpenAPI contract tests.
- [ ] Admin dashboard.
- [ ] Release package.
- [ ] GitFlic Releases publishing.
- [ ] Upgrade path from 0.x.

## 1.1.x — Automation

- [ ] Action Dispatcher.
- [ ] Approval workflows.
- [ ] Dangerous action confirmation.
- [ ] Maintenance windows.
- [ ] Idempotency keys.
- [ ] Retries and cancellation.
- [ ] Rollback hooks.
- [ ] Long-running task progress.
- [ ] Task dependencies.
- [ ] Locks.

## 1.2.x — Realtime / Console Layer

- [ ] WebSocket/SSE event stream.
- [ ] Live task progress.
- [ ] Live logs.
- [ ] Live metrics.
- [ ] Web SSH terminal optional.
- [ ] VNC/noVNC placeholder.
- [ ] RDP gateway design draft.
- [ ] Session audit references.

## 1.3.x — Extension Ecosystem

- [ ] Signed modules.
- [ ] Signed plugins.
- [ ] Signed themes.
- [ ] Manifest validation.
- [ ] Compatibility constraints.
- [ ] Permission review.
- [ ] GitFlic Registry integration.
- [ ] Extension update channel.
- [ ] Sandbox policies.

## 1.4.x — Enterprise Identity

- [ ] OAuth2/OIDC.
- [ ] LDAP/Active Directory.
- [ ] SAML optional.
- [ ] WebAuthn / Passkeys.
- [ ] Session trust.
- [ ] Device/session management.
- [ ] MFA policies.

## 1.5.x — Backup / DR

- [ ] PostgreSQL backup profiles.
- [ ] S3 backup target.
- [ ] Config backup.
- [ ] Encrypted secrets backup.
- [ ] Restore testing command.
- [ ] Retention policies.
- [ ] Proxmox Backup Server integration.
- [ ] Disaster recovery guide.

## LTS Policy

- Первый LTS-кандидат: после стабилизации ветки 1.x.
- Security-релизы: отдельные patch-релизы с changelog и advisory.
- Breaking changes: только major-релизы.
- OpenAPI contract: versioned endpoints `/api/v1`.
