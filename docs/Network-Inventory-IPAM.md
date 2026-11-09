# CajeerServers 0.2.4 — Network Inventory / IPAM

CajeerServers — веб-ресурс, **не ОС, не прошивка и не управляющий агент**. В 0.2.4 вручную управляет учётом сетей, интерфейсов и IP через свой REST API и PostgreSQL. Действия на реальном сетевом оборудовании отсутствуют.

## Возможности

- IPv4/IPv6 CIDR: хранение в PostgreSQL `cidr`, запрет перекрывающихся подсетей **внутри одного workspace**.
- `inventory_networks`: имя, CIDR, VLAN (1–4094), описание, версия, аудит.
- `inventory_interfaces`: привязка к **активному** asset того же workspace, имя, MAC (`macaddr`), версия, аудит.
- `inventory_ip_addresses`: IP (`inet`) как адрес хоста, подсеть, необязательный интерфейс, hostname, описание. Статус `reserved/assigned` выводится из `interface_id`.
- Уникальность IP на workspace; адрес должен принадлежать CIDR указанной сети. IPv4 и IPv6 изолированы по семейству. SQL-триггеры также проверяют принадлежность при прямых вставках, не только при API-операциях.
- Атомарные транзакции с блокировкой workspace, проверкой членства и `owner/manager`, ETag/`If-Match`, append-only audit.
- Отсутствующие / чужие ресурсы не перечисляются: выборки включают `workspace_id`; права `inventory.network.read` и `inventory.network.manage` выдаются отдельно от общих прав на Inventory.
- UI `/ipam` с выбором workspace, созданием/изменением сетей и интерфейсов, резервированием/назначением/освобождением IP.

## Обновление

1. Создать резервную копию PostgreSQL.
2. Применить файлы патча 0.2.4 поверх полного проекта 0.2.3 с сохранением структуры.
3. Запустить `php bin/cajeer migrate`, затем `php bin/cajeer migrate:validate`.
4. Проверить `php tests/ipam-smoke.php`.
5. На отдельной тестовой базе проверить `tests/ipam-postgres-integration.php`.
6. Проверить в установленном Nuxt интерфейсе `/ipam` с двумя workspace и тремя ролями.

Миграция: `migrations/pgsql/0011_network_inventory_ipam.sql`. **Поля legacy `inventory_assets.ip_addresses` не удаляются и не меняются**. Новые данные IPAM — отдельные нормализованные записи. Автоматической миграции JSONB-IP в IPAM нет: существующие записи требуют отдельной сверки на конфликты.

Важно: новая составная ссылка `inventory_interfaces → inventory_assets(id, workspace_id)` препятствует переносу asset с существующими интерфейсами в другой workspace. Сначала снимите назначения IP и удалите или перенесите через предусмотренную в будущих релизах процедуру сетевую привязку. Изменение CIDR у существующей подсети намеренно запрещено: требуется создать новую, переместить IP и удалить старую после освобождения.

## REST API (версия 1)

Обязателен query-параметр `workspace_id=UUID`. Все маршруты требуют браузерной сессии и `inventory.network.read` для GET, либо `inventory.network.manage` для записи. Для POST/PATCH/DELETE обязателен CSRF заголовок `X-CSRF-Token`, полученный через `/api/v1/auth/csrf`. Для PATCH/DELETE/назначения IP — сильный `If-Match: "<uuid>:<version>"` из `ETag` ответа. Bearer-токены не могут вызывать эти методы.

| Маршрут | Операции |
|---|---|
| `/api/v1/ipam/networks` | GET список, POST создать `{name,cidr,vlan_id?,description?}` |
| `/api/v1/ipam/networks/{id}` | GET, PATCH `{name?,vlan_id?,description?}`, DELETE |
| `/api/v1/ipam/interfaces` | GET список, POST создать `{asset_id,name,mac_address?}` |
| `/api/v1/ipam/interfaces/{id}` | GET, PATCH `{name?,mac_address?}`, DELETE |
| `/api/v1/ipam/addresses` | GET список, POST резервирование `{network_id,address,hostname?,description?}` |
| `/api/v1/ipam/addresses/{id}` | GET, PATCH `{hostname?,description?}`, DELETE (освобождение) |
| `/api/v1/ipam/addresses/{id}/assign` | POST `{interface_id}` с If-Match |
| `/api/v1/ipam/addresses/{id}/unassign` | POST без тела с If-Match |

Списки ограничены `limit=1..100` и `offset=0..10000`. Ошибки: 401 без сессии; 403 при недостаточных правах; 404 при недоступном workspace/ресурсе; 409 при конфликте CIDR/MAC/IP или наличии зависимых записей; 412 при устаревшей версии; 428 при отсутствии If-Match; 422 при неверных полях или адресах. DELETE не каскадирует потери IPAM: сначала освободите адреса.

## Пример HTTP (после входа в браузере)

```http
POST /api/v1/ipam/networks?workspace_id=00000000-0000-4000-8000-000000000001
Content-Type: application/json
X-CSRF-Token: <token>

{"name":"Office LAN","cidr":"192.0.2.0/24","vlan_id":120}
```

Ответ `201 Created`, JSON `data` с UUID, CIDR, `version=1`, ETag. Затем POST `/interfaces` на существующий asset того же workspace, POST `/addresses` с IP внутри CIDR и POST `/addresses/{id}/assign` с UUID интерфейса.

## Тестирование и ограничения

Нативные проверки (`ipam-smoke.php`) покрывают IPv4/IPv6, MAC/DNS, ETag, поля и маршруты без БД. **Они не доказывают корректность исполнения SQL**. Для реальной проверки PostgreSQL 17+:

```bash
CAJEER_PG_TEST=1 \
CAJEER_PG_TEST_DATABASE=cajeer_test_ipam \
CAJEER_PG_TEST_USERNAME=cajeer \
CAJEER_PG_TEST_PASSWORD='test-only-password' \
php tests/ipam-postgres-integration.php
```

Интеграционный тест создаёт временную схему и удаляет её. Не используйте боевую БД. GitHub Actions выполняет его в отдельном PostgreSQL 18 job при наличии необходимых сервисов; статус CI зависит от выполнения workflow в GitHub.

**Не входит в 0.2.4:** автоматическое сканирование сети, DHCP/DNS provisioning, резервирование диапазонов/пулов, VLAN provisioning, сетевой discovery, Proxmox/VMmanager connectors, массовая синхронизация IPAM с legacy JSONB и перенос сетей между workspace. Наличие данных здесь не изменяет настройки реальных устройств.
