# VMmanager Safe Action Dispatcher — 0.7.8

Реальная интеграция VMmanager 6 Hosting/Infrastructure для KVM/LXD. Используется существующий ConnectorActionService, PostgreSQL, Vault и стандартный API действий. Не требуется отдельный worker для отправки POST: команды отправляются только в рамках авторизованной MFA-сессии. `vmmanager.tasks` выполняет только read-only reconciliation.

## Настройка

1. Примените миграцию `0052_vmmanager_safe_actions.sql` (`php bin/cajeer migrate`).
2. Настройте target VMmanager с CA, TLS pin, anchor node и Vault credential. Для действий нужен неделегированный административный токен VMmanager (`@admin`, owner_id == trustee_id), чтобы проверить видимость конкурирующих задач. Для обычного read-only discovery допустимы ограниченные токены.
3. Выполните `vmmanager.guests` Sync. Подробный снимок гостя должен быть доступен для текущих ревизий target и credential и моложе 15 минут. При изменении identity/placement повторите сбор и проверьте CMDB. Настройте `vmmanager.tasks` Inspect (например 60 секунд).
4. Выдайте оператору `connectors.vmmanager.actions.execute` в дополнение к существующим `connectors.actions.execute`, `connectors.actions.read`, `connectors.targets.read`, `connectors.credentials.manage`, `vault.credentials.use`, `inventory.assets.read` и workspace-доступу. Миграция назначает новое право только administrator. Включите `CONNECTOR_WRITE_ACTIONS=true` только при разрешённом удалённом управлении.
5. В Admin UI: Подключения → VMmanager target → Удалённые действия. Выберите `host:<id>`, операцию, пройдите MFA и введите точное подтверждение (например `vmmanager.host.start host:21`).

## API и безопасность

| CajeerServers action | VMmanager POST |
|---|---|
| `vmmanager.host.start` | `/vm/v3/host/{id}/start` |
| `vmmanager.host.stop` | `/vm/v3/host/{id}/stop` |
| `vmmanager.host.reboot` | `/vm/v3/host/{id}/restart` |

Все три отправляют только `{}` с `Content-Type: application/json` и `x-xsrf-token`. Не поддерживаются произвольные URL, произвольные параметры, принудительная остановка, автоматический повтор или отключение TLS. Остановка использует штатную семантику VMmanager, не обещает ACPI-гарантию. Документированные API: https://www.ispsystem.ru/docs/vmmanager-admin/razrabotchiku/primery-ispol-zovaniya-api-v2026052

`POST /api/v1/connectors/targets/{id}/actions` с действующей сессией, CSRF, `If-Match`, уникальным `Idempotency-Key`, `{ "action":"vmmanager.host.start", "external_id":"host:21", "confirm":"vmmanager.host.start host:21" }`. Повтор того же ключа в той же области возвращает сохранённую запись, не отправляет команду. Новая операция блокируется при `dispatching`, `submitted` или `unknown` для этого гостя.

Перед POST дважды проверяются pinned HTTPS/anchor, whoami, объявленный POST handler, точный guest и node, KVM/LXD, состояние, HA/rescue, maintenance, видимые блокировки, активные задачи. Задачи проверяются по статусам created/running/deferred; активная задача без известного host также блокирует отправку, а заполненная страница без доказательства отсутствия конкурирующей задачи отклоняется. Вторая проверка сравнивает нормализованную конфигурацию с первой. Сохраняется durable intent до изменяющего запроса. При неудаче до POST действие failed; после начала POST любой неопределённый ответ — unknown, без retry. Внешняя конкурентная операция между последней проверкой и POST остаётся возможной; удалённый VMmanager не предоставляет атомарной транзакции с локальной БД.

## Task reconciliation

VMmanager возвращает Consul ID в `task` (не числовой ID строки `/vm/v3/task`). Restart возвращает `task` и `start_task`; оба должны быть терминальными. Проверка использует документированный фильтр `consul_id` + `name`, сверяет guest при наличии host в записи и не принимает неизвестный результат за успех. Сохраняется ограниченная строка receipt `vmm:<host>:<op>:<consul>:<start_consul_or_0>:<owner>:<fingerprint>` в существующем поле remote_task. Fingerprint построен из доступных идентификаторов гостя, размещения, владельца, дисков и NIC, не является уникальным UUID поколения.

`vmmanager.tasks` читает до четырёх известных submitted-действий за запуск по справедливой очереди, проверяет задачи и живое состояние (`running` для start/restart, `stopped` для stop) и сохраняет результат/аудит в той же транзакции, что и историю задач. Если исходная версия target/credentials изменилась, автоматическая сверка не применяется. Активные задачи не разблокируются. Неизвестный результат без receipt не сопоставляется эвристически: требуется внешняя проверка оператором с MFA и подтверждением `I verified the remote result`. Исчезнувшая задача, смена identity или несовпадающее состояние требуют внешней проверки. Удалённые параметры и тексты ошибок не сохраняются.

## Проверка

`python3 tests/vmmanager-power-https-integration.py` — локальный TLS fixture, реальные PHP запросы; все три операции на обоих типах гостя, Consul receipt, pending/terminal, состояние, ACL, TLS, гонка, потеря ответа, worker. `--postgres` дополнительно запускает изолированную PostgreSQL/Vault/worker цепочку (CAJEER_PG_TEST=1, cajeer_test_*). `php scripts/verify-release.php` — native regression. Fixture не является настоящим VMmanager; production acceptance должна выполняться на выделенных KVM/LXD тестовых гостях с настоящим сервером, PostgreSQL и браузерным UI. Не включайте команды на production-гостях для тестирования.
