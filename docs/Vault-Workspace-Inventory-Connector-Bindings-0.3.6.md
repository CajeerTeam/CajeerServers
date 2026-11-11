# CajeerServers 0.3.6 — Workspace / Inventory / Connector Bindings

Это **работающая версия учёта привязок**: данные сохраняются в PostgreSQL, доступны через защищённые REST API и Nuxt, проверки `workspace`, `RBAC`, `CSRF`, `If-Match` и типизации выполняются при каждой операции. **Удалённые соединения Proxmox/VMmanager/SSH/WinRM/SNMP не выполняются**: исполнительные адаптеры относятся к Connector Framework 0.5.0 и последующим интеграциям.

## Установка

1. Сделайте резервную копию PostgreSQL и сохраните резервную копию зашифрованных Vault credentials и ключей в отдельном защищённом месте.
2. Наложите changed-files ZIP поверх полной версии **0.3.5**, не удаляя неизменённые файлы.
3. Примените SQL-миграцию `0021_vault_connector_bindings.sql` штатным мигратором:

```bash
php bin/cajeer version
php bin/cajeer migrate:status
php bin/cajeer migrate
php bin/cajeer migrate:validate
php tests/vault-bindings-smoke.php
php scripts/verify-release.php
```

Выполняйте миграцию только на PostgreSQL 17+. На время установки миграций рекомендуется окно обслуживания, поскольку создаются внешние ключи и новые триггеры на `inventory_assets` и `inventory_asset_credential_bindings`.

## Что хранится

- `connector_targets`: workspace, provider, имя, DNS/IP и порт (без схемы URL и паролей), необязательный UUID Inventory asset, состояние, версия для optimistic concurrency. Это **конфигурации назначения**, не живые подключения.
- `connector_credential_bindings`: ссылки UUID на credential, connector и purpose. Уникально по `(connector_id, purpose)`.
- `inventory_asset_credential_bindings`: прежние привязки сохранены; новые проверяются на совместимость credential type/purpose.
- `inventory_credential_scopes`: уже существующий реестр workspace. Создание Vault credential в 0.3.2 автоматически создаёт scope. Запрет на перепривязку чужого legacy credential сохранён.

На уровне SQL требуется активный `cjev1` credential и workspace-согласованность. Привязки не копируют ни `encrypted_payload`, ни plaintext, ни wrapped DEK.

## Поддерживаемые соответствия

| Provider | Credential types | Purpose |
| --- | --- | --- |
| proxmox | proxmox_api, api_token | api |
| vmmanager | vmmanager_api, api_token | api |
| ssh | ssh_password, ssh_private_key | ssh |
| winrm | winrm | winrm |
| snmp | snmp_v2c, snmp_v3 | snmp |
| custom | generic_secret, api_token | custom / api соответственно |

Та же проверка совместимости purpose применяется к новым Inventory credential bindings. SQL-триггер проверяет её даже при прямом INSERT/UPDATE. Старые связи не изменяются автоматически.

## REST API

Все маршруты находятся под `/api/v1`, требуют **cookie session**, глобального RBAC и актуальной workspace membership. Изменения требуют `X-CSRF-Token` и роль `owner`/`manager`. Операции изменения/удаления targets и bind/unbind требуют `If-Match: "<target-uuid>:<version>"`.

```http
GET    /inventory/credentials/scopes?workspace_id=<uuid>
GET    /connectors/targets?workspace_id=<uuid>
POST   /connectors/targets
GET    /connectors/targets/{id}
PATCH  /connectors/targets/{id}
DELETE /connectors/targets/{id}
GET    /connectors/targets/{id}/credential-bindings
POST   /connectors/targets/{id}/credential-bindings
DELETE /connectors/targets/{id}/credential-bindings/{bindingId}
```

Например, POST `/connectors/targets` с JSON:

```json
{
  "workspace_id": "00000000-0000-4000-8000-000000000000",
  "provider": "proxmox",
  "name": "PVE cluster",
  "endpoint_host": "pve.internal.example",
  "endpoint_port": 8006
}
```

POST `/connectors/targets/{id}/credential-bindings`:

```json
{
  "credential_id": "00000000-0000-4000-8000-000000000001",
  "purpose": "api"
}
```

В ответах выводятся только metadata и идентификаторы. Сама связка привязана к credential UUID, **а не к номеру секретной версии**: заменённая версия продолжит работать с тем же binding, пока credential активен. Отозванный или архивированный credential нельзя использовать. Архивирование credential запрещено до удаления любых Inventory и Connector bindings.

### Состояние и безопасность

- `403`: нет полномочий или несовместимое назначение credential.
- `404`: ресурс или workspace не видны вызывающему пользователю.
- `409`: дублирующаяся привязка, активные связи при удалении, отключённая конфигурация, занятое имя.
- `412`: устаревший ETag; перечитайте конфигурацию и повторите изменение.
- `428`: отсутствует If-Match для изменения существующей записи.
- SQL guards: чужой workspace, деактивированный credential и неверный тип отвергаются независимо от PHP.
- Нельзя удалить/перенести/soft-delete Inventory asset, пока он указан в connector target; сначала измените/удалите конфигурацию.
- Удаление Inventory binding также отзывает оставшиеся одноразовые `vault_use_grants` для этого asset/credential/purpose.
- Ни API конфигураций, ни Nuxt не являются интерфейсом расшифрования. `ConnectorBindingService::resolveActive()` возвращает **только разрешённую metadata-ссылку**. Расшифрование остаётся в `SecretAccessService` для доверенных серверных операций с MFA.

## Тестирование

Локальные native/HTTP smoke проверки запускаются без PostgreSQL, а интеграционный тест **действительно использует PostgreSQL**. Для isolated test database (не production):

```bash
CAJEER_PG_TEST=1 \
CAJEER_PG_TEST_DATABASE=cajeer_test_vaultbindings \
CAJEER_PG_TEST_USERNAME=cajeer \
CAJEER_PG_TEST_PASSWORD='disposable-test-password' \
php tests/vault-bindings-postgres-integration.php
```

Интеграционный сценарий создаёт изолированную случайную схему, применяет все SQL-миграции и проверяет workspace, RBAC, Inventory, incompatible profiles, отозванные secrets, ETag, запрет архивирования и audit. Тест подготавливается для GitHub Actions с PostgreSQL 18. Браузерный E2E `admin/tests/e2e/connector-bindings.spec.ts` требует явного `E2E_VAULT_BINDINGS=1` вместе с общими флагами изолированного staging.

**Внешняя интеграция и достоверная проверка внешних credentials не входят в 0.3.6**. Для production-допуска дополнительно нужны реальные PostgreSQL/Redis integration, Composer/npm lockfiles, Nuxt build, security review, Playwright E2E и прохождение строгого release-gate.
