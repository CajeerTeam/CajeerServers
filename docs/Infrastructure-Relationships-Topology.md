# CajeerServers 0.2.5 — Infrastructure Relationships & Topology

Это **веб-ресурс для учёта инфраструктуры**, а не ОС, прошивка или замена Proxmox/VMmanager. Связи хранятся в PostgreSQL и показываются в Nuxt; в версии 0.2.5 приложение **не выполняет операции на реальных серверах, VM или сетевом оборудовании**.

## Доступные отношения

| Тип | Откуда | Куда | Правило |
|---|---|---|---|
| `cluster_contains` | `cluster` | `hypervisor_node` | Узел может иметь одного структурного родителя |
| `hosts` | `server`, `hypervisor_node` | `virtual_machine`, `container` | Гость может иметь одного структурного родителя |
| `depends_on` | Любой Inventory asset | Любой другой Inventory asset | Ориентированный ациклический граф зависимостей |
| `connected_to` | Любой Inventory asset | Любой другой Inventory asset | Неориентированная связь; порядок UUID нормализуется |

Нельзя создавать связь с самим собой, удалённым объектом, объектом другого workspace или с несовместимой парой типов. Для структурных отношений и зависимостей запрещены циклы. Подчинённые интерфейсы, IP-адреса и сети уже связаны внешними ключами IPAM; topology формирует на их основании дополнительные **read-only** ребра `interface_of`, `address_on_interface`, `within_network`.

### Гарантии БД

`migrations/pgsql/0012_infrastructure_relationships.sql` создаёт `inventory_relationships` со составными внешними ключами `(asset_id,workspace_id)`, индексами, ограничениями уникальности, положительной версией и триггерами. **Блокировка строки workspace** сериализует изменения графа и предотвращает конкурентное образование циклов. Триггер на `inventory_assets` блокирует soft-delete, смену типа или перенос объекта, пока связи не отвязаны. Старые UUID, JSONB и IPAM-таблицы сохраняются. Переписывать предыдущие SQL-миграции нельзя: их checksum уже зарегистрирован.

### Безопасность

- Только активная серверная сессия, **не** Bearer API tokens.
- `inventory.relationships.read` — чтение метаданных связей; `inventory.relationships.manage` — запись. **Полный topology graph дополнительно требует `inventory.assets.read` и `inventory.network.read`**, так как содержит имена серверов, сети и IP-адреса.
- Чтение дополнительно требует членства в конкретном workspace; запись — роль `owner` или `manager` и CSRF.
- Изменение типа и удаление требуют сильный `If-Match: "<uuid>:<version>"`. Возвращаются 428 без заголовка, 412 при устаревшей версии, 409 при конфликте ограничений. Операции и аудит выполняются одной транзакцией.
- На прямой несанкционированный запрос к чужому workspace возвращается 404 без раскрытия имени и структуры.

## API

Все пути относительно `/api/v1`.

| Метод | Путь | Операция |
|---|---|---|
| GET | `/inventory/relationships?workspace_id=<UUID>&limit=50&offset=0` | Список отношений. Опционально `kind`, `asset_id` |
| POST | `/inventory/relationships` | Создать `{workspace_id,source_asset_id,target_asset_id,kind}` |
| GET | `/inventory/relationships/{id}?workspace_id=<UUID>` | Получить текущую версию и `ETag` |
| PATCH | `/inventory/relationships/{id}?workspace_id=<UUID>` | Изменить `kind` с `If-Match` |
| DELETE | `/inventory/relationships/{id}?workspace_id=<UUID>` | Удалить с `If-Match`; статус 204 |
| GET | `/inventory/topology?workspace_id=<UUID>&limit=100` | Получить реальные nodes/edges, `truncated` и `limits` |

Пример POST:

```http
POST /api/v1/inventory/relationships
Content-Type: application/json
X-CSRF-Token: <токен из /api/v1/auth/csrf>

{"workspace_id":"<uuid>","source_asset_id":"<uuid>","target_asset_id":"<uuid>","kind":"hosts"}
```

`GET /inventory/topology` ограничен: до 200 asset, 200 networks, 400 interfaces, 400 addresses и 1000 relationship rows. `truncated=true` означает неполный граф; это **не** полный отчёт по всей большой инфраструктуре. На диаграмме Nuxt `/topology` показываются первые 60 узлов для читаемости; список отношений ниже ограничен 100 записями. Для обхода используйте REST-пагинацию. API не возвращает секреты и credentials.

## Обновление и тестирование

1. Выполнить backup PostgreSQL.
2. Распаковать patch поверх полного 0.2.4.
3. Применить миграцию: `php bin/cajeer migrate` и `php bin/cajeer migrate:validate`.
4. Выполнить `php tests/topology-smoke.php` и `php scripts/verify-release.php`.
5. На **отдельной тестовой БД** с PostgreSQL 17/18 запустить интеграционный сценарий:

```bash
CAJEER_PG_TEST=1 CAJEER_PG_TEST_DATABASE=cajeer_test_topology \
CAJEER_PG_TEST_USERNAME=cajeer CAJEER_PG_TEST_PASSWORD='test-only-secret' \
php tests/topology-postgres-integration.php
```

Тест создаёт и удаляет приватную схему и проверяет настоящий SQL/циклы/изоляцию/аудит/ETag/граф. Не использовать production-БД. Browser E2E `admin/tests/e2e/topology.spec.ts` требует установленный Nuxt и отдельные staging credentials. CI находится в `.github/workflows/ci.yml`; документация — в `docs/` (GitHub Docs).

## Не входит

Автоматический discovery, topological live-sync, управление виртуализацией и роутерами, настоящий network mapper, мониторинг по topology, dependency-aware automation и создание задач на управляемых серверах. Эти направления запланированы на следующие версии CajeerServers.
