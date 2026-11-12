# 0.4.4 — HTTP Connector & VMmanager Integration

## Обновление с 0.4.3

Сделайте проверенную резервную копию, остановите старые PHP/connector workers, распакуйте архив изменений в корень проекта. Новых миграций нет: используется существующая схема до `0024_connector_reconciliation.sql`. Для ещё не обновлённой БД выполните `php bin/cajeer migrate`. Проверьте конфигурацию и Vault:

```bash
php bin/cajeer vault:check
php scripts/verify-release.php
python3 tests/connector-https-integration.py
python3 tests/vmmanager-https-integration.py
```

Сохраните рабочий Proxmox CA, настройте сети для VMmanager и при необходимости отдельный CA. Пример (подставьте собственные разрешённые подсети и путь к CA, не копируйте пример в production вслепую):

```dotenv
CONNECTOR_ALLOWED_NETWORKS=10.40.8.0/24,10.50.0.0/24
PROXMOX_CA_FILE=/etc/ssl/certs/proxmox-ca.pem
VMMANAGER_CA_FILE=/etc/ssl/certs/vmmanager-ca.pem
```

Пустой `VMMANAGER_CA_FILE` использует системное доверенное хранилище. Не отключайте TLS verification; при внутреннем CA используйте PEM trust bundle, доступный для чтения PHP-FPM и CLI worker. Укажите DNS-имя сертификата в target. В production пустой `CONNECTOR_ALLOWED_NETWORKS` блокирует remote I/O. Не расширяйте allowlist ради теста на production.

## Поддерживаемая платформа и токен

Цель — **VMmanager 6 Hosting/Infrastructure, VM API v3**, KVM и LXD. Документация ISPsystem: [API guide](https://www.ispsystem.com/docs/vmmanager-admin/developer-section/api-guide), [VMmanager API v2026052](https://www.ispsystem.ru/docs/vmmanager-admin/razrabotchiku/api/vmmanager-api-v2026052). Реализованный клиент использует `GET /vm/v3/node` и `GET /vm/v3/host` с параметрами `where`, `orderby`, `limit`. Токен передаётся в `x-xsrf-token` только через проверенный HTTPS. Используйте отдельный токен с доступом на чтение списков узлов и ВМ. Токен выдаётся в самой VMmanager (auth API v4); CajeerServers не принимает пароль платформы и не выполняет автоматическую выдачу/обновление удалённых токенов. Настройте срок жизни на стороне VMmanager; просроченный токен требует ротации в Vault.

В `/vault` создайте credential типа `vmmanager_api`, заполните поле `token`. Альтернатива — существующий `api_token` с исходной строкой токена. Не добавляйте префикс `Bearer`, `Authorization` или cookie. В REST создание принимает `secret: {"token":"<VMmanager-token>"}` для `vmmanager_api`; secret write-only. Не сохраняйте настоящий токен в shell history, тикетах или логе.

В `/connectors` выберите Workspace, `vmmanager`, DNS host и порт 443 (либо HTTPS-порт конкретной установки). Выберите активный Inventory asset, который представляет API endpoint, привяжите credential с назначением `api` к target и к этому же asset. Нужны права connector management и Vault use; для sync также inventory assets create/update. Пароль + TOTP дают одноразовый grant. Доступны health, inspect, sync и проверка pending rotation. Старые REST routes и OpenAPI operationId сохранены для совместимости клиентов; выбор драйвера теперь определяется target.provider.

## Что действительно исполняется

- Registry содержит два driver instances, Proxmox и VMmanager, через DI; `GET /api/v1/connectors/drivers` возвращает оба.
- Health запрашивает одну страницу узлов (один видимый узел); не записывает Inventory. Inspect выполняет discovery и preview без записи. Sync применяет `ReconciliationService` в PostgreSQL. Pending rotation выполняет тот же полный discovery с ожидающим токеном без записи Inventory и сохраняет proof только после успеха.
- Страницы по 100 записей запрашиваются с `where=id GT <last>`, `orderby=id asc`, `limit=0,100`; cursor строго растёт. Чтение продолжается до пустой страницы, а не до короткой. Дубли, игнорирование cursor, malformed list/object, HTTP/JSON ошибки и несогласованные parent IDs отклоняют весь снимок до CMDB commit. Не обрабатываются remote links или произвольные URL из ответа.
- Максимум 2000 суммарных ресурсов, до 64 запросов страниц на каждый список, 60-секундный бюджет между запросами, 8-секундный таймаут transport I/O и 2 MiB на ответ при штатной конфигурации DI. Превышение лимитов не даёт частичный успешный снимок. Размер страницы и бюджет фиксированы сервером; больших инсталляций требуется отдельная стратегия импорта. Worker остаётся ограничен hard timeout/lease 0.4.2.
- External IDs: `node:<numeric-id>`, `host:<numeric-id>`; глобальный Inventory namespace `vmmanager:<targetUUID>:<external-id>`. `cluster.virtualization_type=kvm` → `virtual_machine`, `lxd` → `container`. `node.id` определяет родительскую identity. Изменение имени/узла не меняет ID. Не используются IP/имя как ключ или provider ID другого target. Импортируемые поля — только name/status и parent ID; остальной remote payload отбрасывается. Неизвестное состояние → unknown, maintenance_mode → maintenance. Неизвестная виртуализация (например dummy/vmmgr5) отклоняется, не маскируется под KVM.
- Full snapshot означает все видимые API-токену страницы и согласованность node/host, а не атомарный snapshot БД VMmanager или гарантию полного охвата всех ACL. Изменения между страницами возможны. Пустой node list, ошибка или отсутствующий parent запрещают sync; пустой host list допустим при наличии узлов. Существующий grace period, provenance, conflicts и fencing сохраняются; отсутствующие ресурсы не удаляются. Перемена прав самого удалённого токена без изменения его значения не может быть автоматически обнаружена.

## HTTP transport

Один `HttpsTransport` работает для обоих драйверов. Разрешённые credential headers: Authorization и x-xsrf-token. Только GET, HTTPS, фиксированные пути, запрет CRLF, проверка CA и имени сервера, DNS resolution + pinning адреса в allowlist, отсутствие redirects. Content-Length проверяется против ограничения размера и фактически прочитанного тела. JSON parser отличает объект от массива, включая пустой `{}`/`[]` — ошибка формы не становится пустой страницей.

Безопасные категории ошибок: `connector_auth_failed` (401/403 и VMmanager auth error envelope), `connector_rate_limited` (429), `connector_unavailable` (5xx), `connector_protocol_error` (невалидный статус/JSON), `connector_transport_failed` (не удалось HTTPS/TLS); прочие ошибки discovery — `connector_failed`. Registry не возвращает headers, body, токен или исключение драйвера. Vault по-прежнему записывает факт неудачной операции без секрета, runtime возвращает только фиксированную безопасную классификацию. Фоновый worker прекращает retry при удалённом auth failure, для прочих временных ошибок использует прежнюю ограниченную политику retry. CajeerServers не пытается автоматически повторно авторизоваться на VMmanager.

## Автоматическая синхронизация и ротация

Используйте [Connector Execution 0.4.2](Connector-Execution-0.4.2.md): выдайте MFA service grant конкретному VMmanager target, operation `sync` и interval, затем запускайте Scheduler и выделенный worker `php bin/cajeer connector:work 20`. В очередь передаются идентификаторы, не токен. Ротация credential меняет версию, старые grants прекращают действовать; после активации выдайте новые grants. Для ротации stage pending token в `/vault`, выполните validate-rotation для каждого активного привязанного target в `/connectors`, затем активируйте в `/vault`. Просроченный/отклонённый новый токен не создаёт proof. Общий `CredentialVersionService` уже учитывает все зарегистрированные драйверы с `validate_rotation`, поэтому отдельный обход или VMmanager-specific activation не добавлялся. Proof действует 10 минут и привязан к обеим версиям.

## Проверки и ограничения

`php tests/vmmanager-runtime-smoke.php` — mapping, identity, unsupported type/operation, envelope и safe errors. `python3 tests/vmmanager-https-integration.py` — настоящий loopback TLS с временным CA и VMmanager-compatible fixture (включая пагинацию >100 ВМ, короткие страницы, TLS, HTTP, malformed/truncated response, отказ в середине discovery). Fixture не является VMmanager appliance. Для полного PostgreSQL сценария нужен PostgreSQL 17+ и PHP pdo_pgsql/sodium; только disposable DB:

```bash
CAJEER_PG_TEST=1 CAJEER_PG_TEST_DATABASE=cajeer_test_vmm \
CAJEER_PG_TEST_HOST=127.0.0.1 CAJEER_PG_TEST_USERNAME=cajeer \
CAJEER_PG_TEST_PASSWORD='<test-db-password>' CAJEER_PG_TEST_SSLMODE=disable \
python3 tests/vmmanager-https-integration.py --postgres
```

Тест создаёт и удаляет отдельную schema, применяет реальные миграции, проверяет MFA→Vault→TLS→Inventory, preview без записей, повторную sync, Queue без session, scheduled sync, ротацию, UUID при миграции, отсутствие proof при ошибке и terminal remote auth failure. Включён в CI и strict release verification. Локальный native release и оба HTTPS fixture теста прошли; PostgreSQL, настоящая VMmanager установка, Nuxt production build и Playwright не были доступны в среде сборки. Обязательно проверьте конкретную версию VMmanager 6, роли, paging и CA на staging до production promotion. Composer/npm lock-файлы отсутствовали в исходнике и не подменялись фиктивными.

Не заявляются VMmanager 5, ФСТЭК edition, dummy/vmmgr5 кластеры, действия изменения состояния VM, создание/удаление, storage/IPAM orchestration, полноценная телеметрия и VMmanager vendor E2E. Новых зависимостей/миграций для драйвера нет. Откат требует остановки новых worker; не запускайте VMmanager jobs старым 0.4.3 runtime, в котором исполняемого драйвера ещё нет.
