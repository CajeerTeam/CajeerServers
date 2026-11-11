# CajeerServers 0.3.7 — рабочая связка Workspace / Inventory / Vault / Proxmox

## Что выполняется

В админ-панели `/connectors` работают **настоящие запросы Proxmox VE HTTPS API** (`GET /api2/json/nodes` и `GET /api2/json/cluster/resources?type=vm`). Коннектор не вызывает shell, не выполняет удалённые команды и не изменяет Proxmox. Токен передаётся исключительно удалённому API по HTTPS с проверкой имени сервера и цепочки CA. PHP-запросы используют timeout 8 с, запрещают HTTP redirects, принимают только HTTP 200 + ограниченный JSON и не включают токен или сырой ответ в REST-ответы.

`inspect` возвращает только количество обнаруженных nodes/VM/containers. `sync` превращает обнаруженные объекты в workspace-bound записи Inventory типов `hypervisor_node`, `virtual_machine`, `container` через **действующий** `HistoryService::observeMany` и PostgreSQL-транзакцию. Идентификаторы устойчивы к миграции VM между узлами: `proxmox:{connector_id}:qemu:{vmid}` и `...:lxc:{vmid}`; nodes — `...:node:{node}`. Все строки по одному sync откатываются совместно при ошибке валидации или базы. Второй вызов обновляет существующие объекты; история/привязка источника сохраняются. Коннектор не удаляет и не архивирует отсутствующие на удалённой стороне объекты, чтобы временный сбой API не удалил CMDB.

Импорт через существующий reconciliation engine имеет `source=import`, а идентификатор начинается с `proxmox:{connector_uuid}:`. У ручных объектов `source=manual`; они не перезаписываются импортом. Приоритет ручных overrides, conflicts и отсеивание устаревших наблюдений остаются прежними.

## Настройка для реального запуска

1. Запустить PostgreSQL 17/18 и установить миграции `php bin/cajeer migrate`. Разрешить пользователю роль `administrator` и роль `owner` либо `manager` в выбранном Workspace.
2. Создать **обычный Inventory asset** (тип `server` или `hypervisor_node`) для хоста Proxmox. В `/connectors` выбрать Workspace, `proxmox`, DNS-имя/IP, порт API (обычно 8006) и именно этот asset.
3. Создать Vault credential типа `proxmox_api` с `token_id` (например, `svc@pve!inventory`) и `token_secret`. Предоставить токену на Proxmox только права чтения, достаточные для получения `/nodes` и `/cluster/resources` (например, `Sys.Audit`/`VM.Audit` в соответствии с Proxmox ACL). Если используется legacy `api_token`, содержимое должно быть ровно `token_id=token_secret`.
4. Привязать credential к коннектору c purpose `api`. Поставить флажок «Одновременно дать этому credential доступ к выбранному Inventory asset» (требует `inventory.credentials.manage`) либо самостоятельно создать **такую же привязку** в `/inventory/assets/{asset_id}/credential-bindings` с purpose `api`. Это сознательно два независимых полномочия; удаление connector binding **не удаляет** Inventory binding автоматически.
5. Настроить личную MFA (TOTP). В разделе Proxmox панели ввести текущий пароль учётной записи и свежий TOTP, запустить «Проверить Proxmox API» или «Синхронизировать Inventory». Панель вначале запрашивает `POST /vault/credentials/{credential_id}/authorize-use`; затем вызывает `POST /connectors/targets/{id}/inspect` или `/sync` с `If-Match`. Каждое действие расходует **один** grant с TTL 120 сек. Для повторного действия требуется новая проверка пароля и новый TOTP, если старый уже использован.
6. Для самоподписанных сертификатов Proxmox: заранее сохранить CA / проверенный сертификат на сервере CajeerServers, настроить `PROXMOX_CA_FILE=/path/to/trusted-ca.pem` и перезапустить PHP runtime. **Не выключать** проверку TLS. В production использовать DNS имя, присутствующее в SAN сертификата. Параметр задаётся на PHP сервере, не передаётся через браузер.

### Контроль доступа

Обе операции требуют сессионную авторизацию, CSRF, действующий `If-Match`, workspace `owner/manager`, `connectors.credentials.manage`, `vault.credentials.use`. `sync` дополнительно требует `inventory.assets.create`, `inventory.assets.update`. Сам `SecretAccessService` проверяет активную MFA-сессию, пароль и новый TOTP при выдаче grant, session/user/asset/credential/purpose/version при расходовании, а также отсутствие архивирования или отзыва credentials. Endpoint **не** принимает и не возвращает plaintext/ciphertext.

### API

```http
POST /api/v1/vault/credentials/{credential_id}/authorize-use
X-CSRF-Token: …
Content-Type: application/json

{"asset_id":"<asset_uuid>","purpose":"api","password":"…","totp":"123456"}
```

После успешного подтверждения:

```http
POST /api/v1/connectors/targets/{id}/inspect
X-CSRF-Token: …
If-Match: "{connector_uuid}:{version}"
```

Аналогично `/sync` для записи. Ответ содержит только `connector_id`, `mode`, `discovered`, `types` и (для sync) `outcomes`. Параметры подключения и статус хранятся в PostgreSQL, а исходный токен находится только в шифрованном Vault. Ошибки HTTP 412 означают устаревший ETag; 403 — отсутствие доступа/step-up; 409 — отсутствующий asset или dual binding; 502 — невозможность безопасного подключения к API (в том числе недоверенный TLS).

## Проверки и ограничения

- `php tests/proxmox-runtime-smoke.php` — 25+ проверок запроса, авторизации, форматов, ID, лимитов, отсутствия секрета в ответе и блокировки anonymous API.
- `php tests/vault-bindings-postgres-integration.php` — транзакционная проверка dual-binding, `runtimeContext`, атомарной загрузки/отката CMDB на **отдельной тестовой** PostgreSQL 17/18 (`CAJEER_PG_TEST=1`, `CAJEER_PG_TEST_DATABASE=cajeer_test_*`). Этот тест не запускается на рабочей базе.
- `php scripts/verify-release.php` и `node admin/tests/static-check.mjs` — regressions.
- Проверен отдельный реальный TLS-сеанс к тестовому HTTPS-серверу с корректным CA и отказом без него; **не** подтверждён обмен с настоящим Proxmox в пользовательской инфраструктуре.

Драйверы `vmmanager`, `ssh`, `winrm`, `snmp`, `custom` пока **не выполняют удалённый sync**: доступен только CRUD конфигурации и metadata bindings, а `/inspect`/`/sync` возвращают HTTP 501. Не использовать их как готовые коннекторы. Для production-допуска всего проекта необходимо отдельно пройти staging PostgreSQL, PHP 8.4/8.5, Nuxt production build, Playwright E2E, strict release gate, установить реальные Composer/npm lockfiles. Эти проверки не заменяются локальными native smoke.
