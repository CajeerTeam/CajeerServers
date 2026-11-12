## [0.5.0] — 2026-11-12 — Connector Framework Stable

- Bounded TLS transport: absolute I/O deadline, strict HTTP framing and chunked responses, finite error classifications.
- Connector runtime rechecks live RBAC and manual MFA session before persisting results; active bindings cannot bypass rotation validation when a driver is unavailable.
- Durable connector queue recovery, compact delivery acknowledgements, immediate queued cancellation, consistent scheduler lock order.
- Registry SDK v1 capability stability and validation of non-empty health/rotation results. Existing actions can be resolved even when new write actions are disabled.
- Connector readiness command, regression suite and stricter release verification; full deployment still requires resolved dependencies and the isolated integration matrix.

## [0.4.9] — 2026-11-12 — Connector Admin UI & E2E

- Полный цикл управления подключениями в `/connectors`: поиск, фильтры, пагинация, настройки, Vault-привязки, health и Discovery.
- Service grants и расписания с MFA, история/отмена запусков и автообновление; webhook sources, одноразовый ключ, rotation/revoke и redrive; Proxmox power actions с явным подтверждением и проверкой UPID.
- Серверные фильтры, политика write-actions, актуальная версия credential в binding metadata и операция в run history. Без новых миграций.
- Реальный staging E2E с изолированным HTTPS Proxmox fixture, PostgreSQL, Vault, Scheduler, Worker; динамический TOTP, RBAC и Workspace isolation.

## [0.4.8] — 2026-11-12 — Connector Actions & Extension SDK

- Proxmox QEMU start/shutdown with opt-in, RBAC, MFA, ETag, explicit confirmation, idempotency, durable intent and remote UPID status. No automatic write retry.
- Trusted custom PHP connector registration, SDK HTTP helper and real HTTPS example; typed TypeScript session API client.
- Migration 0028, REST/OpenAPI, TLS fixture, SDK and PostgreSQL integration tests.

## [0.4.7] — 2026-11-12 — Connector Webhooks, Events & Signed Payloads

- Signed target-scoped event inbox, Vault-encrypted source keys, rotation/revocation, durable atomic dispatch to existing connector sync worker.
- HMAC timestamp/source/raw body verification, event-ID deduplication, safe normalized metadata, dead-letter status and redrive with live sync grant.
- Migration 0027, REST/OpenAPI, source revocation fencing and master-key rewrap; smoke and PostgreSQL integration scenarios.

## [0.4.6] — 2026-11-12 — SNMP & Connector Health

- Исполняемый SNMPv3 authPriv и опциональный SNMPv2c, read-only GET/GETNEXT, MIB-инвентаризация.
- Network device metadata с provenance, stable endpoint identity и безопасной reconciliation.
- Постоянное состояние доступности, cooldown/circuit breaker, Scheduler и Prometheus.
- Health в API и Admin UI; миграция 0026.

## [0.4.5] — 2026-11-12 — SSH & WinRM Connectors

- Реальные Linux SSH (password / private key, known_hosts до авторизации) и Windows WinRM (Basic только проверенный HTTPS) через Registry, Vault, rotation proof, Queue/Scheduler и Reconciliation.
- Фиксированные read-only коллекторы: Linux Python `/proc`, `lsblk`, `ip`; Windows PowerShell/CIM через WSMan Create/Command/Receive/Delete. Секреты передаются helper только через stdin, сетевой endpoint pinning, ограничение времени и размера, без произвольных команд.
- Миграция 0025: аппаратные данные `attributes.host_hardware`, стабильный hash machine identity, merge с ручными attributes, ручные hardware override защищены; карточка Inventory показывает данные. PostgreSQL regression добавлен в CI.
- Локальные smoke и WinRM TLS fixture пройдены; полный SSH fixture требует установленного Paramiko, PostgreSQL и Windows staging остаются обязательными для подтверждения эксплуатации.

## [0.4.4] — 2026-11-12 — HTTP Connector & VMmanager Integration

- Рабочий VMmanager 6 API v3 driver (Hosting/Infrastructure), `x-xsrf-token` из Vault, KVM/LXD, health, inspect, sync и pending rotation. DI Registry исполняет Proxmox и VMmanager; существующий Queue/Scheduler и Reconciliation работают без специальных VMmanager ветвей.
- Keyset pagination до исчерпания списков node/host, проверка порядка/дубликатов, parent identity и лимитов; stable numeric IDs, fail-closed при ошибках, без записи raw remote response. Общий HTTPS transport поддерживает оба заголовка, отдельный CA VMmanager, строгий JSON envelope, контроль длины и безопасную классификацию ошибок. Auth failure worker не повторяет.
- Admin UI использует существующий driver catalog и Vault-профили; OpenAPI/CLI/version обновлены. Новых зависимостей и миграций нет. Native regression и оба локальных HTTPS tests прошли. PostgreSQL/Vault/Queue fixture test включён в CI, но требует live DB; настоящий VMmanager, Nuxt build и E2E требуют staging.

## [0.4.3] — 2026-11-12 — Discovery, Sync & Reconciliation

- Реальный общий reconciliation для ручных и фоновых connector runs: устойчивые mapping, migration 0024 без смены UUID, preview, полные/частичные snapshots, generation fencing, транзакционная история.
- Неоднозначность имени блокирует дублирование; REST и Admin UI поддерживают явную привязку ручного asset или подтверждение отдельного создания. Ручные поля защищены. Namespace connector observations зарезервирован.
- Grace period для отсутствующих ресурсов: stale после 2 полных пропусков и 24h, missing после 3 и 72h. Частичные/пустые/ошибочные снимки не удаляют данные; повторное появление сбрасывает счётчики.
- Proxmox сохраняет VMID при переносе и родительский внешний ID; сохранены старые API/CLI/Queue, добавлены resource list/resolve. Интеграционные проверки PostgreSQL и реального appliance обязательны до promotion.

## [0.4.2] — 2026-11-12 — Connector Execution & Secure Service Grants

- Реальные фоновые `inspect`, `sync`, `health` через PostgreSQL queue `connectors`; атомарная постановка, idempotency key, lease recovery, ограниченные retry, отмена, сохранённый результат.
- MFA-делегирование Vault без расшифровки при выдаче: конкретный actor, Workspace, target/credential revisions, asset, purpose, операция и срок действия; worker проверяет актуальные права и привязки до использования секрета и до commit Inventory.
- Фиксированные интервалы, Scheduler tick, CLI и REST управления grant/run. Общий runtime сохраняет прежние синхронные маршруты и external IDs. Redis остаётся доступным для остальных задач.
- Новая PostgreSQL миграция 0023, расширенные smoke и PostgreSQL integration scenarios. Подтверждение live PostgreSQL, реального appliance и production frontend остаётся обязательным перед deployment.

## [0.4.1] — 2026-11-12 — Unified Connector Runtime & Registry

- Proxmox inspect/sync/validate-rotation теперь исполняются через общий DI Registry, типизированный контекст, проверяемые capabilities и валидируемые наблюдения. Добавлены authenticated drivers и health routes.
- Общий TLS-verified HTTPS transport: IP pinning, CIDR allowlist, отказ от redirect, ограничение ответа и времени чтения. Production требует явного `CONNECTOR_ALLOWED_NETWORKS`.
- Общая авторизация Vault и проверка ротации по зарегистрированным драйверам; прежние external IDs и PostgreSQL schema сохранены. UI показывает фактически исполняемые операции.
- Native regression, реальный локальный HTTPS test и расширенный PostgreSQL integration scenario. Живой PostgreSQL/Proxmox, Nuxt build и browser E2E требуют отдельного стенда.

## [0.4.0] — 2026-11-11 — Credentials Vault Stable Integration

- Реальная проверка **pending**-секрета Proxmox через уже реализованный TLS-verified connector: отдельный session/MFA/CSRF/RBAC HTTP маршрут, потребление одноразового grant, read-only discovery; без plaintext REST и без записи в Inventory.
- PostgreSQL миграция `0022_vault_validated_rotation.sql` сохраняет только безопасные сведения о прошедшем удалённую проверку credential/connector; proof действителен 10 минут и привязан к обоим optimistic revisions.
- Транзакционная блокировка активации стадии при отсутствии действительного proof хотя бы для одного активного Proxmox connector; другие типы остаются с явным внешним подтверждением. При активации и отмене proof удаляется.
- `/vault` показывает прогресс проверок и не позволяет активировать ожидающую версию без готовности; `/connectors` выполняет password+TOTP step-up, реальную read-only валидацию и обновляет статус.
- Новые native и расширенные PostgreSQL-интеграционные тесты: запрет произвольного client success, неприменение stale revision, single-use grant, safe transport result, отсутствие CMDB side effects, ETag и повторная проверка.
- **Ограничение:** В среде сборки нет PostgreSQL `pdo_pgsql`, Nuxt зависимостей и достоверных lock-файлов. Полный PostgreSQL roundtrip/production build/browser E2E необходимо выполнить на staging до promotion; тестовые сценарии не равны подтверждённому production release.

## [0.3.9] — 2026-11-11 — Credentials Vault Admin UI & E2E

- Реальная Nuxt страница `/vault`: workspace-specific safe metadata list с пагинацией; создание девяти типов credentials из backend профилей; изменение метаданных и active/disabled состояния, немедленная write-only замена секретов, двухфазная stage/activate/cancel ротация и архивирование. Ни существующий ciphertext, ни plaintext не выводятся в интерфейс.
- Все операции используют PHP session + CSRF, workspace owner/manager и отдельные RBAC permission checks на сервере; `If-Match` из реального ETag, отказ от silent retry при конфликте 412, обновление метаданных после успешной мутации.
- Новые вводимые secrets очищаются из volatile Vue state после попыток отправки/смены профиля, операции, workspace и страницы. На немедленную замену и активацию требуется явное подтверждение; встроенного reveal/plaintext endpoint нет.
- Реальный Playwright staging E2E: UI CRUD, typed profile, 412 conflict, disable/enable, rotation/cancel/activation/replacement, archive, отсутствие секрета в metadata REST, workspace isolation, отрицательный viewer-тест. Тесты используют disposable staging и не содержат моков API, выключены запись trace/video/screenshots.
- Интеграция навигации с `/connectors` и `/transfer`, версия Nuxt/API/CLI, release/static/native checks и документация. **В этом окружении Nuxt build и реальный Playwright/PostgreSQL E2E не запускались.** Отсутствующие lock-файлы не имитируются.

## [0.3.8] — 2026-11-11 — Encrypted Backup & Disaster Recovery (CLI execution)

- Операторские `backup:key:generate`, `backup:create`, `backup:verify`, `backup:restore` и реальные legacy CLI wrappers вместо `placeholder`.
- Потоковая резервная копия PostgreSQL custom-format (`pg_dump` через pipe), local storage, `.env` и настроенных Vault/App ключей в одном authenticated libsodium XChaCha20-Poly1305 secretstream; отдельный 0600 backup-key, без открытого SQL временного файла.
- AEAD-проверка всех фреймов и финального тега, SHA-256/размеры по файлам и SQL-дампу, защита от подмены/обрезки, path traversal и symlinks, bounded chunks; атомарное эксклюзивное опубликование архива после проверки.
- Offline восстановление после full preflight: только новая пустая PostgreSQL БД, новая директория файлов и приватная директория конфигурации; `pg_restore --single-transaction --exit-on-error`, SSH/HTTP endpoints не открываются; SQL-ошибки откатываются, а при неоднозначном COMMIT восстановленные файлы сохраняются для ручной проверки.
- Unit/native smoke и PostgreSQL CI live backup/restore integration (скрипт добавлен; в среде генерации без `pdo_pgsql`/PostgreSQL не выполнялся). Обновлены версия Nuxt/OpenAPI, release verifier и эксплуатационная инструкция.
- Границы: только PostgreSQL + LocalDisk, без S3 snapshot, PITR/WAL и автоматического failover; storage quiesce обеспечивает оператор. Нельзя объявлять полноценный production disaster recovery без внешнего restore drill.

## [0.3.7] — 2026-11-11 — Workspace / Inventory / Connector Bindings (production execution path)

- Вместо заглушки ProxmoxConnector — реальный TLS-verified API клиент для `/nodes` и `/cluster/resources?type=vm`, с проверкой форматов, ограничениями объёма и запретом redirects.
- Реальная цепочка Workspace → connector target → scoped Vault credential → Inventory credential binding → одноразовый MFA grant → server-side token consumption → remote discovery.
- `ConnectorRuntimeService` и защищённые POST `/inspect` / `/sync`; результат только в виде счётчиков, без сырого HTTP или секретов.
- Групповая атомарная синхронизация наблюдений через HistoryService в PostgreSQL: создаются/обновляются Inventory nodes, VMs и containers, применяются стабильные identity и provenance, ошибки откатывают batch.
- Nuxt UI позволяет прикрепить Inventory asset, привязать Vault credential и выдать отдельную Inventory привязку в той же транзакции, выполнить step-up и запуск реального API.
- Обновлены OpenAPI (включая исправление ошибочного уровня вложенности существующих connector paths), версия приложения, проверки и документация.
- Native smoke tests: 27 комплектов, включая новый Proxmox; проверен реальный TLS handshake к изолированному HTTPS стенду и отказ без доверенного CA. PostgreSQL live integration и frontend build/E2E в этой среде не запускались; другие provider runtimes остаются недоступными.

## [0.3.6] — 2026-11-11 — Workspace / Inventory / Connector Bindings

- Реальные PostgreSQL CRUD для workspace-scoped connector target configurations, статус, host/port, asset link, optimistic ETag, аудит.
- Реальные credential bindings для коннекторов: типизированные provider/purpose, FK, привязка к существующему активному Vault credential и запрет cross-workspace.
- Усилены старые Inventory bindings: типизированное назначение credential, PostgreSQL guard и отзыв одноразовых разрешений при отсоединении.
- Vault архивирование блокируется при любых живых Inventory или Connector references; ротация сохраняет UUID и связи.
- Добавлены session-only RBAC/CSRF API, Nuxt `/connectors`, OpenAPI 3.1.1, native smoke, isolated PostgreSQL integration, Playwright staging E2E.
- Connector remote execution и внешний secret consumption не объявляются реализованными; PostgreSQL/браузерный интеграционный прогон в среде сборки не подтверждён.

## [0.3.5] — 2026-11-11 — Credential Versioning & Rotation

- PostgreSQL-backed encrypted secret-version history (active/pending/retired/discarded), immutable credential identity and transactionally safe activation.
- `CredentialVersionService`: stage/activate/cancel, owner/manager workspace authorization, session/RBAC/CSRF, If-Match, redacted responses and audit.
- Existing Vault create/replace/archive now update version history in the same transaction; pre-existing encrypted records are backfilled without decrypting.
- `VaultCrypto::rewrap`: authenticated DEK-only key rotation; the data ciphertext and nonce remain unchanged.
- `VaultKeyRotation`: offline transaction rewraps active, disabled, archived and historical versions and atomically promotes the PostgreSQL verifier. Old processes cannot insert ciphertext with an inactive key.
- `vault:key:rotate --next-key-file=... --confirm-offline` requires an operator-controlled maintenance window and a separate private key file. After commit, switch VAULT_MASTER_KEY_FILE before restarting workers.
- New migration 0020, native smoke and isolated PostgreSQL integration; full server-side integration remains unverified in this environment.

## [0.3.4] — 2026-11-11 — Secure Access Control & Secret Consumption

- Реализован `SecretAccessService`: реальная PostgreSQL-авторизация одноразового использования секрета конкретным серверным адаптером; права `vault.credentials.use` не наследуются из `read`.
- Защита: активная серверная сессия, workspace owner/manager, существующая привязка credential к объекту и назначению, пароль и свежий неповторяемый TOTP, PostgreSQL rate limit (8/15 минут).
- Одноразовые разрешения живут 120 секунд, привязаны к session/user/workspace/asset/credential/purpose/version и атомарно погашаются до дешифрования. Отзыв или изменение credential инвалидирует старые разрешения.
- HTTP `POST /api/v1/vault/credentials/{id}/authorize-use` не возвращает plaintext; `consumeForAsset()` доступен только внутреннему PHP-коду с trusted callback. Audit успешного/отклонённого доступа и cleanup grant records.
- Добавлена миграция `0019_vault_secret_access.sql`, проверки негативных сценариев, PostgreSQL integration и OpenAPI.
- Production gate по-прежнему требует реальных PostgreSQL/Redis, PHP 8.4/8.5, Composer/npm и браузерного E2E; локальное прохождение smoke не заменяет их.

## [0.3.3] — 2026-11-11 — Credential Profiles & Validation

- Вместо заглушки CredentialProfile реализован исполняемый валидатор девяти типов credentials с точными обязательными и разрешёнными полями, проверкой SSH private key, SNMPv3 auth/privacy и Proxmox token ID.
- Существующие create/replace Vault действительно валидируют и зашифровывают полный типизированный payload. Старые строковые `generic_secret` и `api_token` сохраняют прежний формат.
- Добавлен сессионный RBAC-protected `GET /api/v1/vault/profiles` без выдачи секретов, обновлён OpenAPI 3.1.1.
- Новые native tests и реальные PostgreSQL-интеграционные сценарии для сохранения и замены типизированных credentials. Подключено к локальному release verification и GitHub Actions.
- Новых SQL-миграций нет; plaintext API, автоматическая проверка внешних учётных данных и ротация по-прежнему не реализованы.

## [0.3.2] — 2026-11-11 — Encrypted Credentials CRUD

- Реальный PostgreSQL lifecycle зашифрованных credentials: create, list/show metadata, update, replace, disable, archive.
- Все новые записи шифруются через VaultCrypto с отдельным master key; UUID/workspace/type являются AEAD контекстом.
- Добавлен Vault REST controller с session-only authentication, CSRF, workspace owner/manager, deny-by-default RBAC, ETag/If-Match.
- `0018_encrypted_credentials_crud.sql`: метаданные, версия, immutable crypto-context trigger и поддержка legacy-строк без перешифрования.
- CredentialBindingService запрещает произвольное присвоение legacy-credentials и cross-workspace привязки.
- Native smoke и изолированный PostgreSQL integration-test включены в GitHub Actions; подтверждение реального PostgreSQL требуется отдельно.
- Plaintext API, connector secret use, rotation и типизированные SSH/SNMP-профили не включены в 0.3.2.

## [0.3.1] — 2026-11-11 — Vault Cryptography & Master Key Management

- Реальный `VaultCrypto`: XChaCha20-Poly1305, отдельные DEK для каждого envelope, привязка UUID credential / workspace / type через AAD.
- Независимый `VAULT_MASTER_KEY` / приватный `VAULT_MASTER_KEY_FILE` (0600), без fallback на `APP_KEY`.
- Одноразовая регистрация verifier в PostgreSQL и криптографическая проверка ключа; DB-триггеры блокируют изменение verifier.
- Исполняемые CLI `vault:key:generate`, `vault:key:status`, `vault:init`, `vault:check`, `vault:probe` (транзакционный roundtrip с rollback).
- Native crypto-smoke, изолированный PostgreSQL-интеграционный тест и GitHub Actions job; plaintext API и ротация отложены до разграничения прав.
- В этой среде нет PostgreSQL / `pdo_pgsql`: реальная БД должна пройти integration в GitHub Actions или на отдельном тестовом стенде.

## [0.3.0] — 2026-11-10 — Inventory / CMDB Stable Integration

### Added
- Исполняемый read-only `CmdbIntegrity`: согласованная транзакция PostgreSQL, 14 межмодульных проверок, aggregate-only output, 5s statement timeout; глубокая проверка циклов.
- CLI `inventory:integrity [--json] [--deep]`, RBAC-protected `/api/v1/admin/cmdb-integrity`, панель диагностики в Nuxt.
- PostgreSQL-миграция `0016_cmdb_stability.sql`: provenance ручных изменений и запрет soft-delete ресурсов с активными IPAM-интерфейсами.
- Native tests для детектора инвариантов и порядка публикации import job; изолированный PostgreSQL integration и staging browser E2E, расширенный strict release-gate.

### Fixed
- Импорт публикует job после commit PostgreSQL и повторно использует долговечный UUID при сбоях Redis/PostgreSQL Queue.
- Недопустимые ошибки доступа больше не маскируются обработчиком отсутствия очереди.

### Validation limits
- Настоящий PostgreSQL/Redis integration, Composer/npm lockfiles, Nuxt build и browser E2E должны быть проверены на целевом стенде; локальные native tests не заменяют production-допуск.

## [0.2.9] — 2026-11-10 — Complete Inventory Admin UI & E2E

### Added
- Сквозная карточка Inventory asset с реальными данными IPAM, топологии, ревизий и привязок credentials.
- Контекстные ссылки workspace/asset между Inventory, IPAM, Topology, History, Transfer и Catalog без client-side хранения секретов.
- Два браузерных интеграционных сценария для disposable staging, включая проверку потери ответа при импорте.
- Реальный Node-контракт навигации, staging E2E preflight и отдельный GitHub Actions workflow.

### Changed
- Импорт повторно использует один Idempotency-Key при сетевом сбое и показывает progress работающего batch.
- Боковая навигация сохраняет workspace; CRUD Inventory ведёт в карточку объекта.
- Release gate требует явное разрешение работы browser E2E только с изолированным staging.
- OpenAPI и метаданные версии приведены к 0.2.9 (REST контракты не изменены).

### Validation limits
- Нельзя заявлять полностью подтверждённый browser E2E, Nuxt production build или реальный PostgreSQL integration в среде без registry/DB. Отсутствующие dependency lockfiles сохраняют strict gate закрытым.

## [0.2.8] — 2026-11-10 — Inventory Import / Export & Credential Bindings

- CSV/JSON parsing с ограничениями 512 КиБ / 200 строк; dry-run сверка реальной PostgreSQL без побочных действий.
- PostgreSQL durable import batches, idempotency key, queue worker, построчные результаты и checkpoints; повторная доставка через HistoryService Reconciliation.
- Workspace-scoped export CSV/JSON (до 500 записей), скрытие attributes/credentials, защита от spreadsheet formula injection.
- Credential reference scope/bind/unbind с FK, RBAC, membership, CSRF и аудитом без выдачи secret payload.
- PHP REST API, Nuxt `/transfer`, OpenAPI 3.1.1, миграция 0015, native и изолированный PostgreSQL integration-тест, GitHub Actions.
- Ограничение проверки: нет подтверждённого PostgreSQL 17/18 + Nuxt build + browser E2E в локальной среде.

## [0.2.7] — 2026-11-10 — Inventory History, Provenance & Reconciliation

- Рабочий append-only PostgreSQL журнал версий Inventory с триггерами, записью контекста actor/request_id и backfill baseline.
- Исторические снимки не сохраняют произвольное поле `attributes` и содержимое секретов; восстановление только разрешённых полей ручных объектов.
- Транзакционная обработка наблюдений источника `import` с повторной доставкой по event_key, отбраковкой старых наблюдений, полевым provenance и конфликтами ручных значений.
- Workspace RBAC, session-only/CSRF, optimistic If-Match, аудит, REST API и рабочая Nuxt-страница `/history`.
- SQL миграция 0014, smoke и отдельный интеграционный PostgreSQL-тест, GitHub Actions и GitHub Docs.
- Не подтверждено локально: реальный PostgreSQL/Redis, Composer/npm build, браузерный E2E; строгий production-gate остаётся обязательным.

## [0.2.6] — 2026-11-09 — Tags, Groups, Search & Filtering

- Рабочие PostgreSQL `inventory_tags`, `inventory_asset_tags`, `inventory_groups`, `inventory_group_assets`; сохранены старые UUID и JSONB-теги.
- Автоматическая синхронизация старых тегов и очистка групповых связей при переносе asset между workspaces.
- API CRUD каталога и управление составом групп с workspace RBAC, CSRF, ETag, аудитом и fail-closed проверками.
- PostgreSQL FTS + поиск по имени/external ID/IP, фасетные фильтры, сортировка и cursor pagination.
- Nuxt Catalog UI, расширенные фильтры Inventory, GitHub Actions integration и изолированные PostgreSQL тесты.
- Исправлена прежняя вложенность InventoryRelationship в OpenAPI components.schemas.
- Ограничение проверки: настоящая PostgreSQL и Nuxt E2E требуют запуска в целевом окружении.

## [0.2.5] — 2026-11-09 — Infrastructure Relationships & Topology

- Рабочие PostgreSQL CMDB-отношения `cluster_contains`, `hosts`, `depends_on`, `connected_to`: транзакции, workspace-scoped FKs, циклы и structural ownership constraints.
- REST CRUD `/api/v1/inventory/relationships` с RBAC, CSRF, ETag/If-Match и атомарным аудитом.
- GET `/api/v1/inventory/topology` собирает реальный граф из Assets/Relations и IPAM; bounded output и `truncated`.
- Nuxt `/topology` для просмотра, создания, изменения и удаления отношений; GitHub Actions и PostgreSQL integration сценарий.
- Доступ к soft-delete или cross-workspace transfer связанных активов теперь возвращает корректный 409 вместо PDO-ошибки.
- Отсутствие `pdo_pgsql`/сервера PostgreSQL и Nuxt dependencies в локальной среде не позволяет подтвердить полный интеграционный прогон.

## [0.2.3] — 2026-11-09

- Реальные PostgreSQL create/read/update/delete/restore Inventory с tenant authorization и атомарным audit.
- ETag/If-Match, триггер версии, нормализованная валидация пользовательских полей.
- Реальный Nuxt Inventory UI, OpenAPI 3.1.1 и миграция `0010_inventory_assets_crud.sql`.
- GitHub Actions CI и документация GitHub Docs (`docs/`).
- Native и подготовленный изолированный PostgreSQL интеграционный тест (его успешное выполнение требует работающей БД).

# Changelog

## [0.2.2] — Organizations, Workspaces & Ownership (2026-11-09)

- PostgreSQL миграция `0009`: organizations, workspaces, workspace_members, workspace/owner FK в inventory_assets; upgrade-safe перенос старых ресурсов в default workspace.
- Транзакционные `WorkspaceService` и `WorkspaceController`: CRUD метаданных организаций/пространств, membership, просмотр объектов, назначение владельца и межпространственный перенос в пределах организации.
- RBAC + membership isolation, CSRF, last-owner guard, owner-membership trigger, аудит. Пользователи без membership не получают доступ к чужим данным.
- Инсталлятор назначает первому администратору ownership default workspace, а существующим активным администраторам upgrade выдаёт membership.
- Nuxt `/workspaces`, новая навигация, OpenAPI 3.1.1, 49 native smoke assertions и изолированный PostgreSQL integration scenario.
- **Не подтверждено локально:** PostgreSQL/Redis integration, npm/Composer зависимости, Nuxt production build, browser E2E. Строгий production-gate не объявляется пройденным.


## [0.2.1] — Core Stabilization & Inventory Readiness (2026-11-09)

- Рабочий `InventoryReadiness`: real PostgreSQL audit колонок и агрегированных данных, нет выдачи hostname/IP/attributes в CLI.
- Транзакционная `0008_inventory_readiness.sql`: восемь check constraints `NOT VALID` и четыре индекса без изменения данных предыдущих релизов.
- CLI `inventory:preflight [--strict] [--json]` и `inventory:constraints [--apply]`: контроль данных и явная валидация существующих строк.
- Strict release-gate блокируется при невалидных данных, невалидированных ограничениях и неприменённых миграциях.
- Native PDO-double smoke, независимый PostgreSQL upgrade integration на изолированной схеме, миграционный rollback probe 0009, расширены GitHub CI и release verification.
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
- Добавлены GitHub CI integration job и script генерации настоящих dependency lockfiles.
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
- Native smoke tests, PHPUnit runtime tests, GitHub CI, документация запуска.

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

## 0.2.4 — Network Inventory & IPAM

- Добавлены реальные PostgreSQL CIDR/INET/IPAM таблицы и транзакционные операции управления подсетями, интерфейсами и IP-резервациями.
- Введены workspace-scoped уникальность адресов, запрет перекрывающихся подсетей, FK, триггеры проверки диапазона IP и активного asset, ETag/If-Match и аудит.
- REST API `/api/v1/ipam/*`, Nuxt страница `/ipam` и отдельные `inventory.network.read/manage` права.
- Добавлены native и изолированный PostgreSQL integration tests, GitHub Actions и документация GitHub Docs.
- Legacy `inventory_assets.ip_addresses` сохраняется и не переписывается автоматически.
