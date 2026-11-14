# OS / Network Admin UI & E2E — 0.8.9

## Рабочий процесс

Откройте **OS / Network** в боковом меню. Выберите Workspace, затем провайдера Linux / SSH, Windows / WinRM, SNMP или RouterOS. Dashboard показывает точное число подключений, сохранённые результаты health check (доступны, сбои, отключены, не проверены), отсутствие профиля доверия и привязки CMDB. Это не моментальная проверка транспорта. При частичной ошибке показаны только подтверждённые данные; если все запросы неудачны, числа не подменяются нулями.

Поиск и постраничный список ведут в карточку устройства. URL сохраняет Workspace, target, provider и раздел; серверное членство и проверка контекста обязательны. В карточке доступны:

- состояние, даты и полнота сохранённых снимков, последние сборы, последняя версия конфигурации RouterOS;
- специализированный Inventory Linux / Windows / SNMP / RouterOS, существующие фильтры и постраничный просмотр;
- события, график/таблица метрик, наблюдаемая топология Workspace и подтверждаемый аддитивный IPAM reconciliation;
- планы, grants и журнал запусков; создание плана и ручной запуск через существующий backend, MFA и очередь;
- RouterOS: резервные копии, версии, diff и скачивание `.rsc` с проверкой SHA-256.

Для действий нужны соответствующие RBAC-права и роль owner/manager в Workspace. Настройка подключений, Vault и связей остаётся в существующих разделах. Просмотр топологии не изменяет IPAM; импорт требует явного выбора актуального источника и подтверждения. Профили и ключи не выводятся в Dashboard. Не добавлены универсальные backup для SNMP/SSH/WinRM или destructive OS actions.

## Backend и обновление

`GET /api/v1/connectors/targets?workspace_id=…&provider=routeros&include_summary=1&limit=1` возвращает прежний `data` и дополнительный `summary`: `total`, `healthy`, `problem`, `disabled`, `unchecked`, `without_profile`, `unlinked`. Подсчёт выполняется SQL над тем же ограниченным Workspace и фильтрами `search`, `provider`, `state`, до `limit/offset`. Health использует `connector_health_current`. Без флага ответ совместим с 0.8.8. Новая миграция не требуется; нужны миграции до 0060 включительно.

Обновите исходники поверх 0.8.8, разрешите зависимости в доверенном окружении (`bash scripts/resolve-dependencies.sh`, проверка и фиксация lockfiles), соберите `bash scripts/build-release.sh`. Примените стандартную процедуру миграций и разверните новый API и Admin одновременно. Порядок сборки и staging описан в README и release scripts.

## Проверки

- `node --experimental-strip-types admin/tests/os-network-navigation-contract.mjs` — навигация, права, Workspace, агрегаты.
- `node admin/tests/static-check.mjs` — статические проверки Admin.
- `python3 tests/os-network-admin-fixture-integration.py` — production PHP RouterOS transport через локальный TLS-стенд, Inventory, сети, метрики, экспорт и diff, изоляция учётных записей и очистка временных файлов.
- `php tests/vault-bindings-postgres-integration.php` — реальные агрегаты PostgreSQL, фильтрация и Workspace ACL (настроенная тестовая БД).
- `admin/tests/e2e/os-network-admin.spec.ts` — Playwright на одноразовом staging с реальным API, PostgreSQL, Vault, worker, TLS-стендом и `E2E_CONNECTOR_UI=1`. Подключается к существующему `scripts/verify-browser-e2e.sh` / staging workflow. Нужны CA и endpoint policy из staging, `ROUTEROS_CA_FILE` на API и worker. Тест создаёт отдельные Workspace, пользователя viewer и четыре подключения; RouterOS проходит реальный цикл inventory/network/metrics → queue → backup → dedup → diff → download. Остальные три провайдера проверяются в браузере как специализированные пустые состояния, их транспортные проверки остаются в прежних интеграционных тестах. Сценарий также проверяет cross-workspace, reload/back, RBAC и SHA-256 скачанного файла. Запускать только на disposable staging.

Канал release остаётся `development` до подтверждения полного build, PostgreSQL, browser E2E и live acceptance на реальном оборудовании. Наличие тестового кода не означает прохождения этих проверок.
