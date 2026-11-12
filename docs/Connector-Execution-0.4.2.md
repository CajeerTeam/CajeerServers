# CajeerServers 0.4.2 — Connector Execution & Secure Service Grants

## Обновление

Резервная копия 0.4.1, затем распаковать изменённые файлы и применить `php bin/cajeer migrate`. Нужен PostgreSQL 17+, действующий Vault master key, PHP CLI 8.4+ с `pdo_pgsql`, sodium, pcntl; `CONNECTOR_ALLOWED_NETWORKS` и доверенный CA для Proxmox задаются как в 0.4.1. Migration 0023 не изменяет старые credential/target IDs или Inventory. После миграции проверить `php bin/cajeer vault:check` и `php scripts/verify-release.php`.

## Делегирование

1. Создать рабочий target, привязать активный credential и тот же Inventory asset/purpose (как в 0.4.1).
2. Используя активную MFA browser session и CSRF, POST `/api/v1/vault/credentials/{credential_id}/authorize-use` с `asset_id`, `purpose`, паролем и свежим TOTP. Одноразовый grant живёт 120 секунд.
3. POST `/api/v1/connectors/targets/{id}/service-grants` с CSRF и `If-Match: "<target-id>:<version>"`. JSON: `{"operation":"sync","ttl_seconds":86400,"interval_seconds":900}`. `inspect`, `sync`, `health` допустимы; `validate_rotation`, remote writes и произвольные команды запрещены. TTL 60..2592000 секунд, интервал 60..86400 секунд; без `interval_seconds` запуск только по запросу. В scheduled grant первый tick ставит задачу сразу. Необязательное расписание не продлевает TTL.
4. Ответ 201 содержит идентификатор и метаданные разрешения, не секрет и не bearer token. MFA grant погашается атомарно с записью делегирования без расшифровки. Повторная выдача требует новой MFA-проверки. Сервисному разрешению нельзя менять target, operation или credential: отозвать и выдать новое.

Работник действует от имени выдавшего разрешение пользователя, но не имитирует browser session. Logout не отзывает намеренно выданное сервисное разрешение. Отключение пользователя/MFA, потеря прав, смена версии credential/target, удаление asset/binding или отзыв запрещают дальнейшее использование. Любая смена credential metadata/rotation также требует нового grant; это консервативная политика. Ротация pending credential по-прежнему только интерактивная. До и после I/O проверяются действующая роль Workspace и RBAC; revoke/cancel сериализуются с commit. Уже начатый read-only сетевой запрос может завершиться, но его результат не применится после отзыва или отмены, если commit ещё не произошёл.

## Запуск и наблюдение

Все маршруты используют session auth; write требует CSRF. Для получения разрешения также If-Match. Пример enqueue: POST `/api/v1/connectors/targets/{id}/runs`, `Idempotency-Key: import-2026-11-12-1`, body `{"grant_id":"<grant UUID>"}`. Ответ 202 с `data.id`, `data.deduplicated` и Location. Повтор того же key на том же grant возвращает тот же run. В очереди только UUID job (пустой payload), секрет не передаётся. Уникальность key в пределах grant. Максимум 10 pending runs на grant и 20 действующих grants на target.

- GET `.../{id}/service-grants`: последние 100 grants. DELETE `.../{id}/service-grants/{grantId}`: отзыв (204).
- GET `.../{id}/runs?limit=50&offset=0`: история. GET `.../{id}/runs/{runId}`: статус и очищенный результат. POST `.../{id}/runs/{runId}/cancel`: отмена, если работа ещё не завершилась.
- Статусы: queued, running, succeeded, failed, cancelled. Retry находится в queued. `error_code` не содержит тела удалённого запроса. Для выдачи/запуска нужны `connectors.credentials.manage`, `vault.credentials.use`, owner/manager; sync требует также inventory.assets.create/update. Для просмотра `connectors.targets.read` или `connectors.credentials.read` соответственно. Оператор с правом management может отозвать чужой grant своего Workspace; запускать grant может только его владелец.

CLI (доверенный локальный оператор; actor UUID проверяется по актуальным правам, не создаёт grant):

```sh
php bin/cajeer connector:run <actor-id> <target-id> <grant-id> <idempotency-key>
php bin/cajeer connector:status <actor-id> <target-id> <run-id>
php bin/cajeer connector:cancel <actor-id> <target-id> <run-id>
php bin/cajeer connector:tick
php bin/cajeer connector:work 10
```

`php bin/sync-connectors.php` эквивалентен `connector:tick`. Существующий `php bin/cajeer scheduler:run` также вызывает connector tick при включённом scheduler; используйте один основной способ планирования. Запускать cron каждую минуту и отдельный worker под supervisor/systemd либо периодически. `connector:work N` обрабатывает до N задач, завершает работу при пустой очереди. Не запускайте `queue:work --queue=connectors`; эта очередь зарезервирована. Обычные Redis/PostgreSQL jobs не меняются. Для production используйте отдельную service account ОС, ограничьте доступ к .env и Vault key, не запускайте worker от root.

Пример cron (путь заменить на фактический):

```cron
* * * * * cd /opt/cajeer && /usr/bin/php bin/cajeer scheduler:run
* * * * * cd /opt/cajeer && /usr/bin/flock -n /run/cajeer/connector-worker.lock /usr/bin/php bin/cajeer connector:work 20
```

Создать `/run/cajeer` заранее с владельцем service account; для непрерывной обработки предпочтительнее supervisor с bounded work и restart delay. Несколько worker допустимы; advisory lock не позволяет двум фоновых операциям одного target исполняться одновременно. Синхронный inspect/sync использует тот же lock. Отдельная очередь всегда PostgreSQL даже если `QUEUE_DRIVER=redis`: атомарная вставка run и queue job в общей транзакции исключает потерю работы между БД и Redis. Scheduler использует `SKIP LOCKED`, coalescing пропущенных интервалов, не порождает backlog на grant, пока предыдущий run pending. Это fixed-interval scheduling, не cron per connector.

## Аварийное восстановление

Lease 300 секунд, CLI hard timeout 240 секунд (pcntl SIGALRM), максимум 5 попыток, retry 30/60/120/240 секунд. Не используются долгоживущие lease heartbeat/renewal. Потеря lease запрещает запись; при аварии process restart и истечение lease позволяют повторный захват. Действия read-only на удалённой стороне. Результат sync и изменения Inventory в одной транзакции: потеря ACK после commit возвращает сохранённый результат без повторного запроса или повторной записи. Отказ авторизации терминальный; сетевые/временные ошибки повторяются ограниченно. Статус domain run — источник истины, queue completion означает доставку, в том числе терминального failure/cancel. Общий queue retry/prune запрещён для connector queue; история сохраняется, retention policy пока задаётся отдельным эксплуатационным процессом после бэкапа. Не удалять вручную активные queue rows.

## Проверки

`php tests/connector-execution-smoke.php` и общий `php scripts/verify-release.php` проверяют DI, API, ограничения, миграцию и регрессии. Полный PostgreSQL сценарий включён в `tests/vault-bindings-postgres-integration.php`: реальные migration/MFA/grants/queue/Vault/Inventory, тестовый Proxmox transport, logout, revoke, expiry, cancel, lease recovery, ACK replay, отзыв во время запроса. Запускать только в disposable `cajeer_test_*` database с `CAJEER_PG_TEST=1` и `CAJEER_PG_TEST_DATABASE`, `CAJEER_PG_TEST_HOST`, `CAJEER_PG_TEST_USERNAME`, `CAJEER_PG_TEST_PASSWORD`. Например `php tests/vault-bindings-postgres-integration.php`. Реальная TLS fixture отдельно: `python3 tests/connector-https-integration.py`.

**Границы версии:** активный driver — Proxmox; нет новых VMmanager/SSH/WinRM/SNMP, remote write actions, общего heartbeat/lease renewal или полного UI управления расписаниями (API/CLI уже рабочие, UI этап 0.4.9). Full live PostgreSQL integration, appliance, Nuxt production build и браузерные E2E требуют staging. Нельзя считать native smoke подтверждением production deployment.
