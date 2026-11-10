# CajeerServers 0.2.8 — Inventory Import / Export & Credential Bindings

Это веб-функции Inventory/CMDB, **не агент и не ОС**. Источники Proxmox/VMmanager не имитируются: импорт выполняется от имени `import` через существующий `HistoryService::observe`, сохраняя provenance, manual overrides, stale detection и audit. Никакой исполняемый код из CSV/JSON не запускается.

## Обновление 0.2.7 → 0.2.8

1. Сделать резервную копию PostgreSQL и snapshot файлов приложения.
2. Наложить ZIP с изменёнными файлами поверх **полной** версии 0.2.7 с сохранением путей.
3. Выполнить `php bin/cajeer migrate` и `php bin/cajeer migrate:validate`.
4. Проверить `php tests/transfer-smoke.php` и `php scripts/verify-release.php`.
5. На отдельном сервере PostgreSQL 17/18 и БД с именем `cajeer_test_*` выполнить `CAJEER_PG_TEST=1 CAJEER_PG_TEST_DATABASE=cajeer_test_transfer php tests/transfer-postgres-integration.php`.
6. Убедиться, что worker очереди запущен с достаточным временем lease, например `php bin/cajeer queue:work --max-seconds=600 --lease=600` (проверить актуальные CLI-флаги командой `php bin/cajeer help`). Выделенная очередь не требуется: зарегистрирован тип `inventory.import` в `default`.

Миграция `0015_inventory_transfer_credentials.sql` добавляет `inventory_import_batches`, `inventory_import_results`, `inventory_credential_scopes`, `inventory_asset_credential_bindings` и разрешения. Существующие Assets/UUID, History и IPAM не переписываются. `migrate` выполняет SQL в транзакции; старые миграции изменять нельзя из-за checksum.

## Импорт: безопасный контракт

Запросы с веб-сессией и CSRF:

- `POST /api/v1/inventory/imports/preview` — сухой прогон, без записи в PostgreSQL. Требуются `inventory.transfer.read` и роль owner/manager.
- `POST /api/v1/inventory/imports` — durable batch в PostgreSQL и постановка `inventory.import` в queue. Требуются `inventory.transfer.manage` и роль owner/manager; **обязателен** `Idempotency-Key` (8–120 символов), а также `X-CSRF-Token`.
- `GET /api/v1/inventory/imports/{id}?workspace_id=UUID` — статус обработки, результаты по номерам строк (без исходного содержимого).
- `POST /api/v1/inventory/imports/{id}/dispatch?workspace_id=UUID` — повторная отправка pending-задания, если очередь была недоступна.

Тело preview/submit:

```json
{
  "workspace_id": "<workspace-uuid>",
  "format": "json",
  "content": "[{\"external_id\":\"srv-1\",\"type\":\"server\",\"fields\":{\"name\":\"Node A\",\"status\":\"online\"}}]"
}
```

`content` — строка CSV или сериализованный JSON-массив, максимальный размер 512 КиБ, не более 200 записей. Не принимаются `attributes`, пароли, команды, права доступа и произвольные поля. В JSON каждая запись содержит `external_id`, `type`, `fields`; допустимые fields: `name`, `status`, `os_family`, `environment`, `ip_addresses`, `tags`. Новому объекту обязательно имя. Типы соответствуют `InfrastructureAsset::TYPES`.

CSV обязателен со строго указанным заголовком:

```csv
external_id,type,name,status,os_family,environment,ip_addresses,tags
srv-1,server,Node A,online,debian,production,"[""192.0.2.15""]","[""prod""]"
```

Пустая ячейка означает «поле отсутствует». Адреса и теги — JSON-массивы внутри CSV-поля. Дубликаты и неразрешённые значения отклоняются. Превью опрашивает реальную БД (create/update/conflict), но может устареть до выполнения задачи; итог всегда определяется защищённым транзакционным reconciliation.

Рабочий процесс: батч сохраняется в PostgreSQL, очередь получает только UUID, Worker извлекает данные из PostgreSQL и вызывает `HistoryService::observe` для каждой строки. Коммиты выполняются по строкам, результаты и progress checkpoint сохраняются. Повторная доставка использует стабильный `event_key` из UUID batch и номера строки, а также `Idempotency-Key` на уровне batch. Изменения отзываемых ролей и workspace повторно проверяются Worker'ом. Сырой текст ошибок БД и секретов не сохраняется в результатах строк.

Если постановка в очередь не удалась, batch остаётся `pending`, а API возвращает `queue_available=false` — это **не** означает, что импорт выполнен. Терминально неудачную задачу можно повторить стандартной командой `queue:retry` после устранения причины. Не выдавать успешный статус до завершения обработки.

## Экспорт

`GET /api/v1/inventory/exports?workspace_id=UUID&format=csv` или `format=json` возвращает скачиваемое содержимое текущего workspace с проверкой RBAC. Не более **500** активных assets за один запрос; превышение — HTTP 413. Поля: `external_id`, `type`, `name`, `status`, `os_family`, `environment`, `ip_addresses`, `tags`. Не экспортируются `attributes`, credential bindings, секреты, сведения о сессиях и токенах. Для CSV применяется защита от spreadsheet formula injection (`=`, `+`, `-`, `@`, включая начальные пробелы). При открытии в Excel экспортируемые значения безопасно нейтрализуются и поэтому могут иметь добавленный апостроф; это осознанная особенность экспорта. HTTP-ответ имеет `Cache-Control: no-store`.

## Credential Bindings

**Это привязки существующих credentials, не Credentials Vault**. Полноценная поддержка Vault и rotation запланирована на 0.4.0. В API не допускается чтение `credentials.encrypted_payload`.

Сначала администратор с глобальным `inventory.credentials.scope` и ролью workspace `owner` регистрирует *уже существующий* credential:

`POST /api/v1/inventory/credentials/scopes` с `{ "workspace_id": "UUID", "credential_id": "UUID" }`.

Credential можно закрепить только за одним workspace. Эта операция не создаёт и не возвращает секрет. Создание новых секретов в 0.2.8 не реализуется.

Далее доступны:

- `GET /api/v1/inventory/assets/{id}/credential-bindings` — разрешение `inventory.credentials.read`, membership в workspace.
- `POST /api/v1/inventory/assets/{id}/credential-bindings` — разрешение `inventory.credentials.manage`, owner/manager, CSRF; тело `{ "credential_id":"UUID", "purpose":"ssh" }`.
- `DELETE /api/v1/inventory/assets/{id}/credential-bindings/{bindingId}` — аналогичные проверки.

FK PostgreSQL гарантируют, что asset и привязанный credential принадлежат одному workspace. При перемещении asset с существующими связями БД отклоняет операцию, пока администратор не отвяжет credential. Ответы ограничены идентификаторами и назначением. Grant `inventory.credentials.scope` выдаётся только штатному administrator при миграции; делегирование этого разрешения — отдельное решение безопасности.

## Проверка безопасности и допуск к выпуску

- Сессия, CSRF и global RBAC обязательны для операций записи.
- Проверки workspace проводятся в SQL и сервисах; unknown workspace не раскрывается.
- Шифрованные credential payload никогда не попадают в вывод, в историю изменений или в import/export.
- Для production необходимо **реально** выполнить PostgreSQL 17/18 integration, Nuxt build, Playwright E2E и строгую проверку release gate. Smoke-проверки без БД не заменяют этот допуск.

Данные для тестов следует передавать в изолированную БД `cajeer_test_*`, не в production.
