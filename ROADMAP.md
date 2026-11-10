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

## 0.1.1 — Application Bootstrap & Runtime (выполнено)

- [x] Единый bootstrap, загрузка .env, ConfigRepository, DI container.
- [x] Application lifecycle и обработка ошибок HTTP/CLI.
- [x] Рабочие CLI диагностика, self-test, коды ошибок.
- [x] Runtime logging, native smoke tests, GitHub CI.
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

## 0.1.9 — Admin UI & End-to-End Integration (реализован код, внешняя интеграция ожидает подтверждения)

- [x] Реальные Nuxt-страницы для входа, 2FA, users/roles, sessions/tokens, audit, diagnostics, jobs, scheduler.
- [x] Защищённые session-only Admin API с RBAC, CSRF и новым набором permissions.
- [x] Постановка allowlisted диагностических задач и сохранение результатов в очереди; без shell execution.
- [x] Nginx reverse proxy same-origin для Nuxt SSR и PHP-FPM, эксплуатационная документация.
- [x] Native smoke и готовый PostgreSQL/Playwright integration/E2E suite.
- [ ] Выполнить реальные PostgreSQL/Redis + Nuxt build + Playwright browser E2E в CI/staging (нельзя считать выполненными без сервиса).
- [ ] Подтвердить полную последовательность install → auth → scheduler → worker → job result → audit на staging.
- [ ] Зафиксировать `admin/package-lock.json` и `composer.lock` после проверки реальной установки зависимостей.

## 0.2.0 — Production-oriented Core (исполняемый код, внешняя проверка обязательна)

Цель: убрать ложноположительные результаты release-проверок, зафиксировать единый SemVer,
сделать воспроизводимую матрицу smoke/integration/E2E и эксплуатационную проверку живого deployment.

- [x] Единая версия в VERSION, release.json, Nuxt package и двух OpenAPI 3.1.1 файлах.
- [x] Исполняемая проверка `release:check` и `release:check --strict --json`:
      конфигурация, права `.env`, DB/version/migrations, состояние установки, Redis,
      queue/scheduler/storage, TLS-политики, vendor, Nuxt SSR build и lockfiles.
- [x] `scripts/verify-release.php`: PHP lint, все native smoke, Nuxt static;
      строгий режим запускает PostgreSQL/Redis integration, PHPUnit, Nuxt build и Playwright E2E.
- [x] `scripts/smoke-http.php`: реальные HTTP проверки liveness/readiness, 401/404,
      security headers, отсутствие выдачи cookie гостям и обязательный TLS удалённого сайта.
- [x] Исправлена устаревшая PostgreSQL integration rollback-проба после миграции 0007.
- [x] Выделен отдельный пустой PostgreSQL database для destructive installer integration.
- [x] GitHub CI stages для PHP 8.4/8.5, зависимостей Nuxt и disposable PostgreSQL/Redis integration.
- [x] Маркер установки фиксирует версию из VERSION; старые уже установленные marker не переписываются.
- [ ] **Гейт к публичному production:** сгенерировать и зафиксировать настоящие composer.lock и admin/package-lock.json.
- [ ] **Гейт к публичному production:** выполнить Composer-install, Nuxt build/typecheck, PHPunit,
      PostgreSQL 17/18 + Redis integration и Playwright E2E на отдельном staging.
- [ ] **Гейт к публичному production:** проверить Nginx, TLS, disaster recovery, доступность сервисов и
      `php bin/cajeer release:check --strict` + remote HTTP smoke.

Исполняемая реализация и локальные smoke не означают подтверждённый production rollout.
Код внешних коннекторов, inventory и автоматизации остаётся за следующими версиями.
См. `docs/Production-Core.md`.

## 0.2.1 — Core Stabilization & Inventory Readiness (исполняемые проверки)

- [x] Read-only `inventory:preflight`: реальная проверка текущей PostgreSQL Inventory-схемы и данных, безопасные агрегированные результаты без IP/имен/атрибутов.
- [x] `0008_inventory_readiness.sql`: восемь `NOT VALID` ограничений и четыре индекса **без изменений legacy записей**; новые изменения должны быть корректны.
- [x] `inventory:constraints --apply`: явная и идемпотентная PostgreSQL VALIDATE CONSTRAINT после исправления legacy записей.
- [x] Strict release-gate требует применённую миграцию 0008 и валидированные ограничения; fail-closed на проблемных данных.
- [x] Отдельный native smoke с PDO-двойником и интеграционный сценарий upgrade на настоящем PostgreSQL 17+ (отдельная тестовая схема).
- [x] Исправлена rollback-проба интеграционного мигратора на 0009; тесты включены в GitHub CI и скрипт релиза.
- [ ] Зафиксировать **настоящие** `composer.lock` и `admin/package-lock.json` после сборки с сетью.
- [ ] Выполнить PostgreSQL/Redis integration, Nuxt build/Typecheck, PHPUnit и Playwright E2E на стенде; локально они пока не подтверждены.
- [ ] Подтвердить backup/restore, Nginx/TLS и строгий release-gate перед производственным развёртыванием.

Этот этап не включает Inventory CRUD, workspace, IPAM или управление внешней инфраструктурой. См. `docs/Inventory-Readiness.md`.

## 0.2.2 — Organizations, Workspaces & Ownership (код реализован)

- [x] PostgreSQL organizations/workspaces/workspace_members с изоляцией и переназначением существующих asset UUID.
- [x] PHP сервис с реальными транзакциями create/read/update, owner roles, membership, last-owner protection.
- [x] Scoped read существующих Inventory-объектов, assignment и перенос внутри организации, аудит и trigger.
- [x] REST API, RBAC + CSRF, Nuxt workspace UI и OpenAPI 3.1.1.
- [x] Native smoke и отдельный PostgreSQL integration test.
- [ ] Подтверждение реального PostgreSQL 17/18, Composer/npm lock, Nuxt build и Playwright E2E в release окружении.

## 0.2.3 — Inventory Assets & CRUD Engine (рабочая реализация)

- [x] PostgreSQL-backed CRUD для ручных инфраструктурных ресурсов, фильтрация и пагинация.
- [x] Global RBAC + workspace isolation; чтение для members, изменения для owner/manager с CSRF.
- [x] Конкурентная защита ETag/If-Match и версия под SQL-триггером для всех источников изменений.
- [x] Soft-delete/restore, транзакционный аудит, ограничение ввода и защита source от подмены.
- [x] Nuxt Inventory Admin UI, OpenAPI 3.1.1, новая миграция 0010.
- [x] Локальные native smoke; подготовлен isolated PostgreSQL CRUD integration test.
- [ ] Подтвердить PostgreSQL 17/18, Nuxt build и Playwright E2E в CI/staging на реальных сервисах.
- [ ] Получить настоящие `composer.lock` / `admin/package-lock.json` из доверенных регистров.


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
- [ ] GitHub Releases publishing.
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
- [ ] GitHub Registry integration.
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




### 0.2.4 — Network Inventory & IPAM

- [x] Реальные подсети IPv4/IPv6 (CIDR), интерфейсы (MAC), резервации и назначения IP (INET) с проверками PostgreSQL и workspace.
- [x] 17 REST-операций с session-only auth, RBAC, CSRF, ETag/If-Match и аудитом.
- [x] Nuxt `/ipam` с работающими формами и обработкой конфликтов.
- [x] Native smoke / PostgreSQL integration сценарии, GitHub Actions, OpenAPI 3.1.1 и GitHub Docs.
- [ ] Подтверждённый PostgreSQL 17/18 E2E на целевой платформе (требует запуска CI и инфраструктуры).

Этап 0.2.5 реализован; 0.2.6 развивает CMDB тегами, группами и поиском.

### 0.2.5 — Infrastructure Relationships & Topology

- [x] Типизированные PostgreSQL-отношения между существующими Inventory asset с FK, аудитом и ETag.
- [x] Проверка принадлежности workspace и допустимых пар типов, защита от циклов и повторных структурных родителей.
- [x] Препятствие soft-delete, retype и переносу assets с активными связями.
- [x] Workspace-scoped REST CRUD и read-only topology из действительных данных Inventory/IPAM.
- [x] Nuxt `/topology`, GitHub Docs и автоматизированные native / PostgreSQL test suites.
- [ ] Полный PostgreSQL 17/18 integration, Nuxt production build и browser E2E на целевом стенде.

Следующая версия: **0.2.6 — Tags, Groups, Search & Filtering**.

### 0.2.6 — Tags, Groups, Search & Filtering (реализовано локально)

- [x] Workspace-scoped PostgreSQL taxonomy, group membership, audit and optimistic edits.
- [x] Backfill of legacy tags and PostgreSQL trigger synchronization.
- [x] Search by name, external ID, IP and owner/environment/tag/group filters.
- [x] Catalog and Inventory Nuxt pages, OpenAPI 3.1.1, smoke and PostgreSQL integration test sources.
- [ ] Full PostgreSQL 17/18 integration and Nuxt browser E2E on a real deployment.
- [ ] Reproducible Composer/npm lockfiles, production release gate.

**Следующий этап: 0.2.7 — Inventory History, Provenance & Reconciliation.**

### 0.2.8 — Inventory Import / Export & Credential Bindings (реализация)

- [x] Реальные CSV/JSON preview, import batch, queue worker, progress/result journal.
- [x] Идемпотентное reconciliation через 0.2.7 HistoryService.
- [x] Ограниченный workspace export с редактированием формул CSV и исключением секретов.
- [x] Workspace-scoped credential references для существующих записей, без реализации Vault.
- [x] REST API, RBAC/CSRF, Nuxt UI, миграция 0015, тестовые сценарии и OpenAPI.
- [ ] Подтверждение PostgreSQL integration, Nuxt build, browser E2E и строгого production release gate на реальном стенде.

**Следующий этап: 0.2.9 — Complete Inventory Admin UI & End-to-End Integration.**


### 0.2.9 — Complete Inventory Admin UI & E2E (реализована UI-интеграция)

- [x] Карточка Inventory asset с реальными read/update/delete/restore через RBAC, workspace и ETag.
- [x] Данные IPAM, Relationships, History и credential references в карточке (без чтения секретов).
- [x] Deep-link workspace и asset из Inventory в History, IPAM, Topology, Catalog и Transfer.
- [x] Повторный HTTP submit import сохраняет Idempotency-Key; progress обновляется только для активных задач.
- [x] Browser staging сценарии на реальном backend, включая POST с потерянным ответом после commit.
- [x] GitHub Actions workflow для запуска staging E2E с отдельным подтверждением среды.
- [x] Исполняемый нативный контракт URL-навигации и проверки release.
- [ ] Реальный Nuxt `npm ci`, typecheck, production build, PostgreSQL integration и Playwright E2E на disposable стенде.
- [ ] Lock-файлы Composer/npm, полный strict release-gate.

**Следующий этап: 0.3.0 — Inventory / CMDB Stable Integration.**
