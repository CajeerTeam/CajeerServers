# VMmanager Cluster & Server Discovery 0.7.3

Рабочий read-only collector `vmmanager.cluster` использует VMmanager 6 Hosting/Infrastructure API v3 (`GET /vm/v3/cluster`, `GET /vm/v3/node`) и проверку `auth/v4/whoami`. Документация API: https://www.ispsystem.ru/docs/vmmanager-admin/razrabotchiku/api/vmmanager-api-v2026052 .

## Настройка

1. Применить `php bin/cajeer migrate` (0047), пересобрать Admin UI, перезапустить workers.
2. Настроить VMmanager target, Vault token и профиль доверия SHA-256/anchor ID/name (см. документацию 0.7.1/0.7.2). Профиль обязателен для нового collector даже при глобальном CA-only режиме.
3. В «Сбор данных → Планы и запуски» создать план `vmmanager.cluster`, Inspect или Sync, интервал 300 секунд. Авторизация/права и TTL grant — существующие механизмы CajeerServers. Нужны права чтения узлов и кластеров на удалённом API.
4. Запустить план. В «Коллекторы» доступен текущий снимок и история, поиск узлов по имени/ID/IP, фильтр по кластеру. API: `GET /api/v1/connectors/targets/{id}/vmmanager-cluster`, SDK `vmmanagerCluster(id)`. Для чтения нужны Workspace membership, connectors.targets.read и inventory.assets.read.

## Семантика

- `cluster:<id>` и `node:<id>` — стабильные external ID в пределах target. Физический узел представлен одним `hypervisor_node` (не дублируется отдельным server). Sync использует существующие правила создания/сопоставления, manual-field protection и конфликтов identity. Принадлежность хранится как `parent_external_id` в discovery mapping, а не как редактируемый CMDB relationship. Снимок и история содержат cluster_id; перемещение обновляет parent. Невидимый родитель не перезаписывает известную связь.
- API-поля `cpu_number`, `ram_mib.total/used`, `storage_mib.total/used`, `host_count`, `host_limit`, `ip`, `ipv6`, `state`, `maintenance_mode`. Значения ресурсов — десятичные строки или null; MiB сохраняются без пересчёта в float. used — семантика VMmanager, не измерение live RSS/IO. Неизвестные значения не заменяются нулями, host_limit=-1 отображается как без лимита. Секреты, URL, SSH user, комментарии и произвольные свойства не сохраняются.
- Пагинация `where=id GT ...&orderby=id asc&limit=0,100`; обязательная пустая страница для завершения, строгая проверка возрастающих ID. Ограничение 2000 кластеров и узлов суммарно, 64 страницы на тип, 60-секундный общий deadline, 850 КБ JSON. Превышение или отказ API прерывает сбор; предыдущий снимок не затирается. Межзапускового staging/resume больших инсталляций нет. Снимок не является транзакционным снимком удалённого VMmanager; параллельные изменения могут влиять на видимость.
- `snapshot.complete` означает завершённый cluster/node сбор для неделегированного администратора и видимые родительские кластеры. Для ограниченного токена false; отсутствие родителя отражается отдельно. Ошибки 403/404 не маскируются пустым списком. Общий ConnectorResult.complete всегда false: частичный по типам сбор не должен объявлять гостей исчезнувшими. Обычный vmmanager.inventory не включает кластеры в проверку отсутствия. Только полный cluster/node-снимок учитывает отсутствие cluster mappings; применяется существующая политика stale/missing (24/72 часа и повторные наблюдения). Узлы и гости проверяются на отсутствие обычным inventory. Объекты не удаляются автоматически; актуальный снимок показывает текущие наблюдения.
- Снимок хранится в `vmmanager_cluster_state` вместе с target/credential revisions; изменение профиля, ротация, отключение или отвязка делают текущие данные stale. История в connector_runs сохраняет снимок; записи списка истории не содержат объёмные массивы. Workspace lock, повторная проверка гранта/credentials и запись результата job — одна транзакция.
- Неизвестная версия/редакция, загрузка CPU в процентах, физические сокеты/ядра, BMC/serial, создание/удаление кластеров, изменение узлов, автоматическая запись editable CMDB relationships не входят в релиз.

## Проверки

`php scripts/verify-release.php`, `python3 tests/vmmanager-https-integration.py` (TLS fixture), `python3 tests/vmmanager-https-integration.py --postgres` (изолированная тестовая БД PostgreSQL 17+ с CAJEER_PG_TEST=1 и CAJEER_PG_TEST_DATABASE=cajeer_test_*). Последний сценарий проверяет Sync, membership, повторный сбор, историю и stale state. Настоящая VMmanager-инсталляция и полный browser E2E требуют отдельного staging. Не объявлять stable до строгой приёмки.
