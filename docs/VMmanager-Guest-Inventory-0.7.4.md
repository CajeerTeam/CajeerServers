# VMmanager VM & Container Inventory — 0.7.4

## Настройка

1. Обновить файлы и выполнить `php bin/cajeer migrate` (0048).
2. Настроить VMmanager 6 target, Vault API token и обязательный профиль доверия (см. 0.7.1–0.7.2).
3. В «Сбор данных → Планы и запуски» создать `vmmanager.guests`, Inspect или Sync, интервал 300 секунд, авторизовать grant и запустить worker.
4. В «Коллекторы» открыть VMmanager: виртуальные машины и контейнеры. Поиск, фильтры, 50 строк на страницу, детали дисков/NIC и история запусков.

## Сбор и хранение

GET `/vm/v3/node` и `/vm/v3/host` с `where=id GT ...`, `orderby=id asc`, `limit=0,100` до явного пустого списка. Список host включает связанные сущности; отдельный GET по ID не включает все связи (см. [документацию ISPsystem](https://www.ispsystem.ru/docs/vmmanager-admin/razrabotchiku/rabota-s-api-v2026052)). До и после сбора проверяются опорный узел, pinned TLS и `auth/v4/whoami`. Сбор исключительно read-only.

Проекция содержит ID host/node/cluster, KVM/LXD, имя, состояние, CPU, RAM/HDD в MiB, OS, ID аккаунта, доступные диски (size_mib, storage, bus, type, target_dev), интерфейсы (MAC, bridge, model) и IP. Вложенные списки и объекты нормализуются; не сохраняются исходные JSON, password, email, SSH keys, пути дисков, токены. Неизвестные значения — null, явный пустой список отличается от непредоставленного. Ёмкость — десятичные строки для точности. Конфигурация и статус отражают предоставленные API данные, не являются измерением ресурсов из ОС гостя.

Inspect сохраняет снимок и preview. Sync использует существующие `host:<id>` / `node:<id>` и Inventory reconciliation, включая доступные IP и распознаваемую OS family; CPU/RAM/диски сохраняются в отдельном снимке, не перезаписывают ручные CMDB-атрибуты. При переносе гостя сохраняется его asset ID. Ограниченные и делегированные credentials не дают доказательства отсутствия ресурсов. Полные node/host-снимки не меняют отсутствие кластеров. При несогласованности видимого размещения полный сбор завершается ошибкой.

`GET /api/v1/connectors/targets/{id}/vmmanager-guests` возвращает текущий снимок, scoped по Workspace, target/credential revision. При смене профиля/credentials или отключении target данные stale. История доступна через `/runs?collector=vmmanager.guests` и `/runs/{id}`. Сохранение current state, reconciliation и job result выполняется атомарно. Ошибка не заменяет прежний успешный снимок.

## Проверки и границы

`python3 tests/vmmanager-https-integration.py` — локальный TLS fixture, в том числе подробная конфигурация и частичная видимость. `--postgres` требует PostgreSQL 17+, pdo_pgsql и изолированную `cajeer_test_*` БД; проверяет обновление 0047→0048, Vault/worker, историю и повторный Sync. `php scripts/verify-release.php` — native regressions. `npm run typecheck && npm run build` в admin для deployed UI. Реальную совместимость с конкретной инсталляцией проверять через read-only план и сверку с нативным VMmanager.

Сохраняются пределы 2000 node+host, 64 страницы на тип, 60 секунд, 850 KB нормализованного снимка. Превышение — ошибка без замены текущего снимка; межзапусковое staging/resume отсутствует. Недоступные в host list конфигурационные поля остаются неизвестными; отдельный storage/IPAM discovery и управление гостями — следующие релизы. Канал development до строгой приёмки.
