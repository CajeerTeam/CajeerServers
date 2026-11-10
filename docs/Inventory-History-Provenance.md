# CajeerServers 0.2.7 — Inventory History, Provenance & Reconciliation

Реализация предназначена для **self-hosted веб-оболочки**, а не системной ОС/агента. На этом этапе источником входящих наблюдений является только `import`; внешние Proxmox/VMmanager connectors не подключены. API не выполняет команды на устройствах.

## Обновление с версии 0.2.6

1. Сделать резервную копию PostgreSQL и подтвердить успешное восстановление резервной копии в тестовой среде.
2. Наложить ZIP 0.2.7 поверх **полной** версии 0.2.6 с сохранением относительных путей.
3. Выполнить `php bin/cajeer migrate` и `php bin/cajeer migrate:validate`.
4. Проверить `php tests/history-smoke.php` и `php scripts/verify-release.php`.
5. Выполнить тест на **изолированной БД**: `CAJEER_PG_TEST=1 CAJEER_PG_TEST_DATABASE=cajeer_test_history php tests/history-postgres-integration.php`.
6. Проверить готовность Admin UI `/history` и защищённые API на staging, запустить GitHub Actions.

Миграция `migrations/pgsql/0014_inventory_history_provenance.sql` НЕ изменяет первоначальную схему 0001 и сохраняет UUID и прежние данные. Для существующих объектов записывается ровно один `baseline` с **текущим** состоянием: восстановить версии до установки 0.2.7 невозможно. Создание индексов и backfill может блокировать таблицы; применяйте в окно обслуживания.

## REST API (session-only)

| Метод | Маршрут | Назначение |
|---|---|---|
| GET | `/api/v1/inventory/assets/{id}/history` | Версии и диффы без `attributes` |
| GET | `/api/v1/inventory/assets/{id}/provenance` | Режим происхождения каждого поля |
| GET | `/api/v1/inventory/assets/{id}/conflicts` | Конфликты manual/observed |
| GET | `/api/v1/inventory/assets/{id}/observations` | Последние результаты поступлений |
| POST | `/api/v1/inventory/observations` | Приём наблюдения `import` |
| PATCH | `/api/v1/inventory/assets/{id}/overrides` | Создание/обновление ручных полей |
| DELETE | `/api/v1/inventory/assets/{id}/overrides/{field}` | Снятие ручного override |
| POST | `/api/v1/inventory/assets/{id}/restore-version` | Восстановление безопасных полей ручного объекта |

Чтение требует `inventory.assets.read` + `inventory.history.read` и membership текущего workspace. Изменение дополнительно требует `inventory.reconcile.manage` или `inventory.history.restore`, роль owner/manager, сессию, CSRF. Для ETag-операций обязательный заголовок `If-Match: "<asset-id>:<version>"` (без weak ETag). API bearer tokens не могут управлять reconciliation.

### Наблюдение

```json
{
  "workspace_id": "<uuid-space>",
  "source": "import",
  "external_id": "edge-01",
  "event_key": "import-batch-001-row-25",
  "type": "router",
  "fields": {
    "name": "Edge Router",
    "status": "online",
    "os_family": "linux",
    "ip_addresses": ["192.0.2.10"],
    "tags": ["production"]
  }
}
```

`observed_at` необязателен; если он указан, принимается RFC3339 в пределах 366 дней назад и до 5 минут вперёд. `event_key` уникален для workspace/source. Повторная отправка **идентичного** сообщения возвращает сохранённую квитанцию (`duplicate=true`) без нового обновления и аудита. Повторное использование ключа с другими данными даёт 409. Наблюдения старше последнего применённого записываются с исходом `stale` и не меняют объект. При первичном появлении `external_id` создаётся новый объект source=`import`, при последующих поступлениях обновляются только поля в режиме `observed`.

### Ручное переопределение и восстановление

`PATCH .../overrides` принимает объект `{"fields":{"name":"Операторское название"}}`. Провенанс отмечает режим `manual`; при несовпадении с будущим `import` объект сохраняет ручное значение, а конфликт записывается отдельно. `DELETE .../overrides/name` снимает защиту; следующее новое наблюдение может обновить поле. Изменения версионированы через ETag и аудит.

`POST .../restore-version` принимает `{"revision":2}`. Разрешено только для активных объектов source=`manual`; восстанавливаются `name`, `status`, `os_family`, `ip_addresses`, `tags`, `environment`, `owner_user_id`. Мета-атрибуты `attributes`, удаление и связи IPAM/Topology не восстанавливаются. Восстановление — новая ревизия, а не изменение старой.

## Модель хранения и безопасность

- `inventory_asset_revisions`: append-only revision log с PostgreSQL-триггером; прямой UPDATE/DELETE запрещён для роли приложения. Системные суперпользователи могут изменять схему, поэтому это не криптографический WORM.
- `inventory_observations`: транзакционная дедупликация событий и результат их обработки.
- `inventory_observation_state`: последнее время наблюдения источника, без автоматического удаления устаревших объектов.
- `inventory_field_provenance`: ручной или наблюдаемый режим для ограниченного набора полей.
- `inventory_reconciliation_conflicts`: открытые и разрешённые расхождения.
- История намеренно **не сохраняет содержимое** `attributes`. Диффы отражают только `{ "attributes": {"redacted": true} }`. Эта система не предназначена для хранения паролей или токенов.
- История не переносится в новый workspace при перемещении объекта; записи прошлых пространств скрыты от пользователей нового, а данные не удаляются.
- Идемпотентность обеспечивается ограничениями БД и блокировками workspace. Нельзя использовать одну и ту же БД для изолированных и production тестов.

## Проверки допуска

`php scripts/verify-release.php --strict` остаётся обязательным. Подтвердить живой PostgreSQL 17/18, CI, frontend production build и браузерный E2E перед заявлением о полной production-готовности. В окружении генерации эти проверки могли быть недоступны.
