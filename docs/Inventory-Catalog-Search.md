# CajeerServers 0.2.6 — теги, группы, поиск и фильтрация

CajeerServers — веб-ресурс для инвентаризации инфраструктуры. Этот релиз не выполняет команды на серверах и не заменяет Proxmox/VMmanager.

## Установка

1. Сделайте **резервную копию PostgreSQL** и сохраните данные `.env` отдельно.
2. Распакуйте патч поверх **0.2.5**, сохраняя относительные пути.
3. На стенде проверьте миграцию `0013_inventory_catalog_search.sql`; затем примените её на целевой БД через `php bin/cajeer migrate`.
4. Запустите `php bin/cajeer migrate:validate` и `php tests/catalog-smoke.php`.
5. Для полного допуска запустите `tests/catalog-postgres-integration.php` на отдельной тестовой PostgreSQL 17+/18 БД и Nuxt E2E.

Миграция выполняется в транзакции и **не удаляет** объекты и старые `tags` JSONB. Индексы создаются обычным `CREATE INDEX`; на крупных базах согласуйте окно обслуживания, поскольку построение индексов может блокировать запись. Проверку отката делайте по резервной копии: SQL миграция не является автоматически обратимой.

## Данные

- `inventory_tags` — каталог тегов в пределах workspace; slug неизменяем.
- `inventory_asset_tags` — принадлежность объектов тегам; composite FK защищает от ссылок между workspace.
- `inventory_groups` — именованные группы, version + ETag.
- `inventory_group_assets` — членство объектов в группах, version группы меняется при каждой реальной операции.
- `inventory_assets.environment` — строковое окружение (`unassigned`, `production`, `staging`, `development`, своё значение).
- Индекс `GIN(to_tsvector('simple', ...))` — PostgreSQL FTS для имени и external ID, дополнительный защищённый substring search и поиск по legacy/IPAM IP.

**Обратная совместимость:** существующие валидные строки `inventory_assets.tags` переводятся в реляционные связи. Триггер синхронизирует `tags` с каталогом при создании/изменении объекта через старый REST API или прямой SQL. При переносе объекта в другой workspace групповые связи очищаются, а теги создаются/назначаются в новом workspace. Исторические невалидные теги JSONB не удаляются автоматически; проверьте их до миграции отдельно.

## API

Все маршруты используют `/api/v1`, серверную сессию и глобальное RBAC разрешение. Для чтения каталога нужно `inventory.catalog.read`, а для просмотра имён объектов группы дополнительно `inventory.assets.read`; для изменений — `inventory.catalog.manage` и роль workspace `owner` или `manager`. Изменения требуют CSRF и сильный ETag в `If-Match` (кроме `POST` создания). Bearer-токены не управляют каталогом.

| Метод | Маршрут | Операция |
|---|---|---|
| GET/POST | `/inventory/tags` | Список / создание тега |
| GET/PATCH/DELETE | `/inventory/tags/{id}?workspace_id=UUID` | Чтение / изменение названия и описания / удаление неиспользуемого тега |
| GET/POST | `/inventory/groups` | Список / создание группы |
| GET/PATCH/DELETE | `/inventory/groups/{id}?workspace_id=UUID` | Чтение / изменение / удаление пустой группы |
| GET | `/inventory/groups/{id}/assets?workspace_id=UUID` | Объекты в группе, включая soft-deleted для возможности отсоединения |
| POST/DELETE | `/inventory/groups/{id}/assets/{assetId}?workspace_id=UUID` | Добавление активного / отсоединение объекта; `If-Match` группы |
| GET | `/inventory/assets?workspace_id=UUID&...` | Фильтры и полнотекстовый поиск объектов |

Параметры поиска: `search`, `type`, `status`, `source`, `os_family`, `environment`, `owner_user_id`, `tag` (slug), `group_id` (UUID), `sort` (`created_desc`, `updated_desc`, `name_asc`), `limit`, `offset` или `cursor`. Курсор применяется только при `sort=created_desc` и `offset=0`. `pagination.next_cursor` выдаётся при наличии следующей страницы. Для одинаковых временных меток порядок стабилизируется UUID. Обычная `offset`-пагинация сохранена для старых клиентов.

Код ошибки 404 используется для отсутствующих и недоступных workspace; 403 — для недостаточной роли; 409 — для конфликтов/занятых сущностей; 412 — для старого ETag; 422 — для неверной фильтрации и тела; 428 — для отсутствия `If-Match`.

## Ограничения

- Все запросы workspace-scoped и ограничены 100 записями на страницу.
- Режим `include_deleted=1` разрешён только owner/manager.
- Удаление занятого тега или группы запрещено; сначала снимите привязки. Из группы можно исключить даже soft-deleted объект.
- У пользователей viewer нет прав изменять каталог, даже если глобальное разрешение по ошибке выдано.
- Временные CSV/JSON экспорты, мониторинг агентов и provider connectors не входят в 0.2.6.
- PostgreSQL integration, полный Nuxt build, браузерный Playwright E2E и отсутствующие lockfiles требуют отдельного подтверждения в целевой инфраструктуре.
