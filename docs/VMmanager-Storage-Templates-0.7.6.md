# VMmanager Storage & Templates Discovery — 0.7.6

## Установка и настройка

Обновить 0.7.5, остановив workers, выполнить `php bin/cajeer migrate` (0050), пересобрать Admin UI. Для VMmanager target необходим профиль доверия с SHA-256 pin, CA и опорным узлом, а также действующий Vault credential. Создать планы `vmmanager.storage` и `vmmanager.templates`, операция **inspect**, интервал 300 секунд. Запустить и открыть раздел «Сбор данных → Коллекторы».

## Реальный удалённый сбор

Используются GET `/vm/v3/node`, `/vm/v3/host`, `/vm/v3/storage`, `/vm/v3/os`, `/vm/v3/preset`, `/vm/v3/image`, `/vm/v3/os/iso` и проверка `auth/v4/whoami`. API VMmanager 6 Hosting/Infrastructure: https://www.ispsystem.ru/docs/vmmanager-admin/razrabotchiku/api/vmmanager-api-v2026052. Удалённые запросы ограничены TLS-профилем, сетевой политикой и временем; проверяются identity и опорный узел до/после сбора. Keyset pagination использует ID, сортировку и явное окончание. Ошибка транспорта, токена или нарушение протокола не заменяют предыдущий снимок. HTTP 403/404 отдельных каталогов отражаются как `denied`/`not_found`, а не как пустой доступный каталог. Ограниченная/делегированная учётная запись даёт неполный снимок.

## Семантика

- Storage ID, guest ID и disk ID связываются только по точным идентификаторам. Одинаковое имя хранилища не доказывает его identity. Неизвестные и несопоставленные диски показаны отдельно.
- Ёмкости и размеры в MiB — десятичные строки. `null` означает, что API не предоставил значение. Размер выделенного диска не равен занятому физическому месту. Ёмкость узлов не суммируется с shared storage. Тип, состояние, shared и связи показываются только при наличии соответствующих полей.
- ОС, конфигурации VM (presets), образы VM и ISO — отдельные каталоги. Поля, не предоставленные версией API или ACL, неизвестны. Не загружаются образы, URL репозиториев, пути, пароли, storage params и произвольные поля.
- Inspect сохраняет только собственные наблюдения; ни удалённые объекты, ни CMDB, ни IPAM не изменяются. План Sync запрещён. Дисковые образы не скачиваются и не монтируются.
- Текущие снимки `/connectors/targets/{id}/vmmanager-storage` и `/vmmanager-templates` требуют connectors.targets.read, inventory.assets.read и Workspace membership. Смена версии target/credential, отзыв или отключение делает снимок stale. История по collector сохраняется в существующих connector_runs; списки сокращены, детали возвращают полный снимок.

## Эксплуатационные пределы

До 2000 узлов/гостей и до 2000 записей каталогов на сбор, 4000 дисков, 64 страницы на ресурс, 60 секунд на сбор и 750 КБ на нормализованный снимок. Превышение лимита отклоняет сбор. Межзапускового staging/resume нет. Не все версии платформы возвращают одинаковые конфигурационные поля или поддерживают все каталоги; отсутствие конкретного поля не означает нулевое значение. Нативные TLS fixture-тесты не заменяют live acceptance.

## Проверки

`python3 tests/vmmanager-https-integration.py` — рабочий клиент и TLS fixture. `php scripts/verify-release.php` — native regression; `python3 tests/vmmanager-https-integration.py --postgres` — upgrade и реальные Vault/worker/SQL тесты на выделенной тестовой PostgreSQL 17+. Не использовать production-базу. Полная проверка с реальным VMmanager, Nuxt и browser E2E обязательна до stable.
