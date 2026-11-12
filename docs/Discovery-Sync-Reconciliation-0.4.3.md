# 0.4.3 — Discovery, Sync & Reconciliation

## Обновление

1. Сделайте проверенную резервную копию 0.4.2 (БД + конфигурация и storage). Остановите старые connector workers и Scheduler на время миграции; старый runtime не поддерживает новый реестр.
2. Распакуйте архив изменений в корень 0.4.2. Выполните `php bin/cajeer migrate`, `php bin/cajeer vault:check`, `php scripts/verify-release.php`.
3. Сохраните `CONNECTOR_ALLOWED_NETWORKS` и доверенный CA. Запустите новые worker и Scheduler (`php bin/cajeer connector:work 20`, `php bin/cajeer scheduler:run`).
4. До production promotion выполните реальный PostgreSQL тест, проверку Proxmox на отдельном стенде и Nuxt build/E2E. Откат к старым процессам после применения migration 0024 не рекомендуется: старый код не обновляет новый реестр; восстанавливайте согласованный backup.

## Рабочий контур

И `POST /api/v1/connectors/targets/{id}/sync`, и фоновые `sync` из версии 0.4.2 используют `ReconciliationService`. Driver возвращает валидированный `ConnectorResult` с `items`, признаком `complete` (по умолчанию false) и опциональным `parent_external_id`. Registry отвергает дубли, запрещённые поля, слишком большие результаты и циклы родителей. Proxmox использует прежние `node:<node>`, `qemu:<vmid>`, `lxc:<vmid>`; перенос гостя и переименование не меняют его ID. Родитель хранится в discovery registry, не переписывает ручные CMDB graph relationships.

Полный снимок Proxmox означает успешное чтение обоих API (`/nodes`, `/cluster/resources?type=vm`) и согласованность узлов гостей. Это полнота видимости данного API-токена, не доказательство полного инвентаря кластера. При ошибке TLS/HTTP/парсинга, отсутствующем узле, превышении лимита либо пустом результате запись не выполняется. Другие драйверы должны явно заявлять `complete=true` только после успешной загрузки всех страниц своего согласованного scope. Пустой результат никогда не является основанием для массового отсутствия.

Миграция `0024` связывает уже существующие `import` assets с target по точному `provider:targetUUID:externalID`. UUID и поля не изменяются. Старые строки имеют `unknown`, пока впервые не подтверждены новым снимком. При повторном открытии ранее удалённого target создаётся новый UUID; автоматического объединения нескольких targets/кластеров нет. После первого снимка нельзя заменить host/port существующего target: используйте новый target для нового источника. Оператор отвечает за неизменность идентичности кластера за DNS-именем и повторное использование VMID после удаления VM; драйвер не получает глобальный immutable ID из Proxmox. Не переключайте DNS на другой кластер под тем же target.

Снимку назначается PostgreSQL sequence generation **до удалённого I/O**. Workspace lock, target advisory lock и worker lease/grant fence сохраняются. В одной транзакции фиксируются generation, mapping, наблюдения, provenance/conflicts, состояния отсутствия, audit и результат run. Потеря ACK возвращает сохранённый результат. Устаревший generation отвергается. Обработка идёт чанками по 250 в одной транзакции; лимит Registry 2000 объектов, Proxmox 256 узлов + 1500 гостей. Пагинация UI/API — 50 по умолчанию, максимум 100. Это bounded snapshot, не потоковый импорт неограниченных кластеров.

## Preview и конфликты

`POST /api/v1/connectors/targets/{id}/inspect` — по-прежнему реальный удалённый вызов после password+TOTP grant и If-Match. Возвращает `preview.complete`, счётчики `create/update/unchanged/conflict`, первые 100 планируемых записей с candidate UUID. Не записывает Inventory/discovery state (расходует MFA grant и пишет audit). Preview — оценка на момент выполнения, не резервирование, повторная sync читает источник заново.

`GET /api/v1/connectors/targets/{id}/resources?limit=50&offset=0` — сохраняемые mapping, `observed_name`, `parent_external_id`, `asset_id`, `presence`, `last_generation`, `conflict_code`, `missed_complete` и timestamps. Требует session, `connectors.targets.read`, `inventory.assets.read`, членства в Workspace. Отсутствующий или чужой target недоступен. Результаты фонового inspect также требуют Inventory read permission.

Если точного ID нет, одноимённый asset того же типа в Workspace считается кандидатом (не доказательство идентичности). Sync сохраняет `ambiguous_name` без создания дубля. Оператор owner/manager с connector credentials manage и inventory create/update может выполнить:

```http
POST /api/v1/connectors/targets/<targetUUID>/resources/resolve
Content-Type: application/json
If-Match: "<actual-target-etag>"
X-CSRF-Token: <session-csrf>

{"external_id":"qemu:42","generation":123,"mode":"link","asset_id":"<existing-manual-asset-uuid>"}
```

Или `{"external_id":"qemu:42","generation":123,"mode":"create"}` для явного разрешения отдельного создания. Значение generation берётся из `last_generation` ресурса, ETag — из target GET. Решение без удалённого I/O и без нового asset; следующая sync применяет его. Связь допускается только с активным ручным asset того же типа в том же Workspace, не связанным с другим ресурсом. Его существующие ручные поля не перезаписываются, отличия фиксируются в History conflicts. Для импортированного asset точный connector ID остаётся единственным автоматически связываемым идентификатором; несовпадающие imported IDs не объединяются автоматически. Неоднозначность идентичности, мягко удалённый объект, type mismatch и пропавшее обязательное имя не заставляют создавать новый asset: восстанавливайте нужный объект или исправляйте данные источника. Ручные overrides импортированных assets продолжают управляться History API.

В `/connectors` доступны preview, результаты sync, список mapping с пагинацией и явные решения конфликтов. Автоматический discovery не редактирует IPAM, сети, произвольные attributes или graph relations: эти сущности требуют отдельного нормализованного адаптера и политики владения.

### Политика отсутствия

- `seen` — ресурс был подтверждён; `unknown` — мигрированная/неподтверждённая запись или сброшенные доказательства отсутствия.
- Только успешный непустой **полный** снимок увеличивает `missed_complete` для неувиденных ранее подтверждённых ресурсов. Первый пропуск начинает grace period.
- `stale` — минимум 2 полных пропуска и 24 часа от первого; `missing` — минимум 3 и 72 часа.
- Частичный снимок может обновить увиденное, но не увеличивает пропуски. Повторное появление обнуляет счётчик. Смена target revision, credential revision или credential ID сбрасывает накопленное доказательство отсутствия (новая область видимости).
- Ни один статус не удаляет asset, не меняет его operational status, не очищает связи, не освобождает IP. Не интерпретируйте missing как доказательство удаления на удалённом сервере; ACL могут измениться без смены локального credential.

Импорт CSV/JSON и публичные observations не могут записывать зарезервированный namespace `provider:<uuid>:...` или event key `connector.*`. Это защита provenance и idempotency. Экспорт этих записей пригоден для анализа, но не для обратного impersonating import; для полного переноса используйте backup/restore либо измените external IDs для независимого импорта.

## Проверки

```bash
php scripts/verify-release.php
python3 tests/connector-https-integration.py
CAJEER_PG_TEST=1 CAJEER_PG_TEST_DATABASE=cajeer_test_connector \
  CAJEER_PG_TEST_USERNAME=cajeer CAJEER_PG_TEST_PASSWORD='test-only' \
  php tests/connector-reconciliation-postgres-integration.php
```

Последний тест требует PostgreSQL 17+ и pdo_pgsql, использует отдельную временную schema и удаляет её после выполнения. Дополнительно `tests/vault-bindings-postgres-integration.php` проверяет фактический Vault/Queue/Runtime путь, потерю ACK, отмену и отзыв. Оба включены в CI и strict release gate. Локальные smoke не заменяют живую БД, настоящий Proxmox и browser E2E. Не заявляется production certification без этих проверок.
