# Inventory / CMDB — 0.2.3

CajeerServers — веб-оболочка, не ОС и не замена Proxmox/VMmanager. В этом релизе реализован **ручной учёт** инфраструктурных объектов через PHP/PostgreSQL. Автоматическая синхронизация с внешними источниками будет реализована в последующих версиях.

## Обновление

1. Резервная копия PostgreSQL. Не меняйте ранние SQL-файлы: мигратор проверяет SHA-256 checksum.
2. Примените `php bin/cajeer migrate` (добавляется `0010_inventory_assets_crud.sql`).
3. `php bin/cajeer migrate:validate`, `php tests/inventory-smoke.php`, `php scripts/verify-release.php`.
4. Откройте `/inventory` в Nuxt Admin UI. Старые записи, UUID, источники и JSONB-данные сохранены.

Проверка на PostgreSQL 17+/18 в **отдельной** БД:

```bash
CAJEER_PG_TEST=1 CAJEER_PG_TEST_DATABASE=cajeer_test_inventory \
CAJEER_PG_TEST_USERNAME=cajeer CAJEER_PG_TEST_PASSWORD='test-password' \
php tests/inventory-crud-postgres-integration.php
```

Тест создаёт и удаляет изолированную схему. Не запускайте на production-БД. В текущем окружении php-pgsql/PostgreSQL не были доступны; до успешного интеграционного теста production-допуск не подтверждён.

## Поля и типы

`server`, `hypervisor_node`, `cluster`, `virtual_machine`, `container`, `router`, `workstation`, `other`. Статусы: `unknown`, `online`, `offline`, `maintenance`, `provisioning`, `decommissioned`.

Формат JSON (максимум 1 МБ на HTTP-запрос):

```json
{
  "workspace_id": "00000000-0000-4000-8000-000000000001",
  "type": "server",
  "name": "Debian node",
  "status": "online",
  "os_family": "debian",
  "ip_addresses": ["192.0.2.11", "2001:db8::11"],
  "tags": ["production", "eu"],
  "attributes": {"rack": "B4", "cpu_cores": 8}
}
```

`owner_user_id` можно установить в UUID действующего участника этого workspace. Поля `source`, `external_id`, `workspace_id` и `type` запрещено менять через PATCH: источники API-коннекторов не подменяются ручным CRUD.

## API

Все пути относительно `/api/v1`:

| Метод | URL | Примечание |
|---|---|---|
| GET | `/inventory/assets?workspace_id=<UUID>&limit=50&offset=0` | Список; опциональные `type`, `status`, `search`, `include_deleted=1` |
| POST | `/inventory/assets` | Создать ручной объект, body как выше |
| GET | `/inventory/assets/{id}` | Карточка; ответ содержит `ETag` и `data.etag` |
| PATCH | `/inventory/assets/{id}` | Изменить поля, заголовок `If-Match` |
| DELETE | `/inventory/assets/{id}` | Soft-delete, `If-Match` |
| POST | `/inventory/assets/{id}/restore` | Восстановить, `If-Match` |

Формат ETag: `"<uuid>:<version>"`. Для изменения сначала выполните GET и передайте ETag без модификаций в `If-Match`. При конкурентном изменении получаете `412 Precondition Failed`; без `If-Match` — `428 Precondition Required`. Удалённые объекты не входят в обычные выборки; manager/owner могут запросить `include_deleted=1`.

Каждая операция требует **серверную PHP-сессию и глобальную RBAC permission**. Чтение требует активного членства в workspace; создание/изменение/удаление дополнительно требуют `owner` либо `manager`. Изменяющие запросы защищены CSRF. Bearer API-токены **не разрешают** эти операции. Сохранение изменения и запись audit_log выполняются в одной PostgreSQL-транзакции; операция откатывается, если аудит не удалось записать.

Исходники с `source != manual` можно читать в разрешённых пространствах, но нельзя вручную редактировать, удалять или восстанавливать. Это защищает будущие данные Proxmox/VMmanager.

## Ограничения и будущие этапы

IP пока сохраняются как проверенный JSONB-массив. Нормализованный IPAM планируется на 0.2.4, topology — 0.2.5. Автоматический импорт, sync engine и provider connectors — позднее. Данный выпуск не реализует управление реальными устройствами, секретами, сетями и виртуализацией.

Документация размещена в GitHub Docs (`docs/`), CI — GitHub Actions (`.github/workflows/`).
