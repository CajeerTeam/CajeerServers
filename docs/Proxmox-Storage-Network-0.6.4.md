# Proxmox Storage & Network Discovery — 0.6.4

Рабочий read-only collector `proxmox.storage-network` для PVE 8/9. Использует существующий проверенный профиль PVE, Vault и Connector Runtime. API-ключ не передаётся в Admin UI, снимки или историю.

## Запуск

1. Сделайте резервную копию PostgreSQL и примените миграции обычным способом (`php bin/cajeer migrate`, см. `php bin/cajeer help`). Новая миграция `0040_proxmox_storage_network_discovery.sql` создаёт текущий scoped snapshot, старые миграции не изменяются.
2. Создайте/проверьте профиль PVE, привяжите API credential к target и Inventory asset. Для read-only сбора предоставьте PVE API token разрешения на необходимые узлы, VM и хранилища: `Sys.Audit`, `VM.Audit`, `Datastore.Audit`, доступ к соответствующим SDN/localnetwork через `SDN.Audit` или предусмотренные PVE права чтения. ACL должны покрывать нужные пути, включая права пользователя и privilege-separated token. Не требуется предоставлять права изменения VM или storage.
3. Admin → «Сбор данных» → выберите PVE target → «Планы и запуски» → создайте план `proxmox.storage-network` с `inspect` или `sync`, MFA и нужным интервалом. Запустите вручную или по расписанию. На странице collectors появится «Хранилища, volumes и сетевая топология». `inspect` сохраняет текущий снимок и preview Inventory; `sync` дополнительно применяет существующее reconciliation для узлов/гостей. Storage/network не подменяют редактируемые CMDB assets или IPAM.
4. API: `GET /api/v1/connectors/targets/{id}/proxmox-storage-network`, `GET /api/v1/connectors/targets/{id}/runs?collector=proxmox.storage-network`, `GET /api/v1/connectors/targets/{id}/runs/{runId}`. Для чтения необходимы доступ к Workspace, `connectors.targets.read` и `inventory.assets.read`. SDK: `client.proxmoxStorageNetwork(id)`.

## Что действительно собирается

- `GET /api2/json/storage`: безопасная проекция конфигурации datastore (ID, тип, shared, disabled, допустимые content и узлы).
- `GET /api2/json/nodes/{node}/storage`: состояние на конкретном узле, capacity/used/available в байтах (точные десятичные строки). Одинаковые local storage ID на разных узлах имеют различные `storage_key`; явно shared storage — общую идентичность. Ёмкости shared storage не суммируются между узлами.
- `GET /api2/json/nodes/{node}/storage/{storage}/content`: список видимых volumes, format, размер/приблизительный размер, владелец VMID, parent volume, защита backup; не раскрываются path, notes, пароли, encryption key и произвольные options.
- `GET /api2/json/nodes/{node}/network?type=include_sdn` в PVE 9; в PVE 8 — `/network` и `/network?type=any_bridge`, объединённые по имени интерфейса. Адреса IPv4/IPv6, MTU, мосты, bonds, физические интерфейсы, VLAN, OVS и доступные SDN-интерфейсы. Связи указывают на peer внутри одного узла, с признаком подтверждения видимым списком интерфейсов.
- `GET /api2/json/cluster/resources?type=vm`, `GET /api2/json/nodes/{node}/{qemu|lxc}/{vmid}/config`: связи guest disk → storage/volume и NIC → bridge, VLAN/MAC/IP. Не раскрываются host bind-mount paths, произвольный QEMU/LXC config или пароли. Проверяется местоположение гостя повторным чтением списка; известные конфликты идентичности VMID отклоняются атомарно.

## Границы наблюдения и эксплуатация

Это снимок видимой конфигурации, а не live-трафик, LLDP, физические кабели, полный SDN controller inventory или автоматическое управление storage. Права PVE могут скрыть ресурсы даже при HTTP 200. `details_complete` означает, что все запрошенные разделы получены, а не что весь кластер видим. `complete=false` предотвращает удаление CMDB на основании отсутствия в ACL-фильтрованном списке.

403, 404, 429, offline и лимиты показываются явными состояниями. Недоступный volume или bridge не считается удалённым. 401, некорректный протокол и ошибка доверия не маскируются; при транзитной ошибке HTTPS весь сбор повторяется на разрешённом fallback без смешивания ответов разных endpoints. Ротация Vault, изменение профиля, отключение или перепривязка target инвалидируют текущий снимок. История хранится в существующих connector runs.

В одном запуске ограничены 12 узлов, 24 storage content-запроса, 32 guest config, 96 detail-запросов и 60-секундный бюджет начала detail-запросов; каждый HTTPS запрос также имеет собственный timeout. При превышении размеров списков выбирается случайное циклическое окно, snapshot помечается частичным. Большой снимок может опустить volume lists с явным `size_limited`, сохраняя хранилища и остальные связи; если после этого превышен безопасный лимит, запуск отклоняется. Повторные запуски дают другие окна, исторические снимки сохраняются, но current не представляет накопленный полный inventory больших кластеров. Полный снимок требует, чтобы данные укладывались в лимиты и были доступны токену. Отдельные storage/volume ID не являются глобально уникальными без target и узла/shared scope.

## Проверки

`php tests/proxmox-storage-network-smoke.php` — исполнительный collector, реальные форматы payload, числовая точность, связи, ACL, rate limit, offline, race и ошибки протокола.

`python3 tests/proxmox-profile-https-integration.py` — реальные локальные TLS endpoints, pinned identity, failover и новый collector. `bash tests/connector-sdk-integration.sh` — SDK через HTTPS fixture.

`php tests/proxmox-profile-postgres-integration.php` — upgrade 0039→0040, реальная очередь, PostgreSQL persistence, history, ACL и invalidation; требует PostgreSQL 17+ и переменных изоляции `CAJEER_PG_TEST`. `php scripts/verify-release.php --strict` — полный release gate с PostgreSQL, зависимостями и staging/browser. Release channel остаётся development до прохождения полного gate.
