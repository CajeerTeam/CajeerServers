# CajeerServers 0.2.1 — Inventory Readiness

## Назначение

Рабочие операции подготовки **существующей** таблицы `inventory_assets` к этапу 0.3.0 CMDB. Не включают CRUD, организационные пространства, Proxmox/VMmanager или сбор данных. CajeerServers остаётся веб-приложением, а не ОС и не обязательным агентом.

## Безопасное обновление 0.2.0 → 0.2.1

1. Запланировать окно обслуживания: обычный `CREATE INDEX` может блокировать запись на большие таблицы. Остановить фоновые задания, использующие `inventory_assets`, сохранить **проверенную** резервную копию PostgreSQL и предусмотреть план восстановления.
2. До миграции посмотреть данные: `php bin/cajeer inventory:preflight --json`. Команда только читает БД и **не** раскрывает имена хостов, IP и произвольные JSON-атрибуты; возвращает агрегированные счётчики. При ошибке — ненулевой exit code.
3. Исправить обнаруженные невалидные записи вручную с аудитом и контролем доступа; не запускать слепые массовые UPDATE по production. Невалидными считаются пустые/пробельные `name`, `type`, `source`, `status`, пустой непустой `external_id`, не-массивы `ip_addresses`/`tags` и не-объект `attributes`.
4. Применить `php bin/cajeer migrate` и `php bin/cajeer migrate:validate`. Миграция `0008_inventory_readiness.sql` **не удаляет и не обновляет** старые данные: создаёт четыре индекса и восемь `CHECK ... NOT VALID`. Новые или изменяемые строки должны удовлетворять ограничениям. Миграция транзакционная; уже применённые `0001`–`0007` не редактируются.
5. Исполнить `php bin/cajeer inventory:constraints --json` для просмотра состояния. После устранения проблем **явно** запустить `php bin/cajeer inventory:constraints --apply` для выполнения `ALTER TABLE ... VALIDATE CONSTRAINT` по разрешённому списку. На больших таблицах это отдельная операция с потреблением I/O; контролировать окно обслуживания.
6. Запустить `php bin/cajeer inventory:preflight --strict --json`, `php bin/cajeer migrate:status`, `php bin/cajeer release:check --strict --json`, запустить workers и проверку `/api/v1/ready` после успешной приёмки.

При невалидных существующих строках миграция всё равно сохраняет данные и проходит, а `inventory:preflight --strict` и production gate **не** объявят базу готовой, пока ограничения не будут валидированы.

## CLI

```bash
php bin/cajeer inventory:preflight --json
php bin/cajeer migrate --dry-run
php bin/cajeer migrate
php bin/cajeer inventory:constraints
php bin/cajeer inventory:constraints --apply
php bin/cajeer inventory:preflight --strict --json
```

`inventory:constraints` без `--apply` **не** изменяет БД. Команды не выполняют произвольный SQL из пользовательского ввода. Не выставлять их через публичный HTTP API.

## Тестирование

```bash
php tests/inventory-readiness-smoke.php
php scripts/verify-release.php
```

Тест с **настоящим** PostgreSQL 17+ использует случайную изолированную схему внутри выделенной тестовой БД `cajeer_test_*`: создаёт исходную схему до 0.2.0, добавляет повреждённую legacy-строку, применяет миграцию 0008, проверяет сохранность данных и блокировку новых ошибочных строк, восстанавливает данные, валидирует ограничения и повторно запускает мигратор. Не выполняйте его на production.

```bash
CAJEER_PG_TEST=1 \
CAJEER_PG_TEST_DATABASE=cajeer_test_core \
CAJEER_PG_TEST_HOST=127.0.0.1 \
CAJEER_PG_TEST_USERNAME=cajeer_ci \
php tests/inventory-postgres-integration.php
```

Пароль БД передавать через защищённую среду `CAJEER_PG_TEST_PASSWORD` и никогда не публиковать в истории терминала. В текущей среде сборки драйвер `pdo_pgsql` и сервер PostgreSQL отсутствуют: этот интеграционный сценарий **добавлен, но не исполнен**.

## Что не завершено

`composer.lock`, `admin/package-lock.json`, Composer install, Nuxt production build, браузерный E2E, интеграция с PostgreSQL/Redis и проверка полноценного production deployment — обязательные блокирующие проверки. Локальный native smoke не заменяет их. Не проставлять статус готовности до реального CI/staging запуска.
