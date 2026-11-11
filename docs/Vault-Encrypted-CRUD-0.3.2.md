# CajeerServers 0.3.2 — Encrypted Credentials CRUD

В релизе реализовано реальное шифрованное хранилище credentials на PostgreSQL, подключённое к существующему `VaultCrypto` и `CredentialsVault`. Это веб-подсистема, а не ОС или агент.

## Развёртывание

1. Сделать резервную копию PostgreSQL.
2. Установить патч 0.3.2 поверх полной версии 0.3.1.
3. Установить `ext-sodium` и `pdo_pgsql`, настроить приватный `VAULT_MASTER_KEY_FILE` (обычно `/etc/cajeerservers/vault.key`, режим `0600`, владелец пользователь PHP-FPM).
4. Выполнить `php bin/cajeer migrate`; версия миграций должна включать `0018_encrypted_credentials_crud.sql`.
5. На новой установке однократно выполнить `php bin/cajeer vault:init`. Если Vault инициализирован на 0.3.1, **не перезапускать** `vault:init`.
6. Проверить `php bin/cajeer vault:check` и `php bin/cajeer release:check --strict` на staging. Не перенастраивать master key без безопасной процедуры ротации.

## REST API (session-only)

| Метод | Путь | Permission | Дополнительно |
|---|---|---|---|
| GET | `/api/v1/vault/credentials?workspace_id=UUID` | `vault.credentials.read` | доступный workspace |
| POST | `/api/v1/vault/credentials` | `vault.credentials.create` | CSRF, owner/manager |
| GET | `/api/v1/vault/credentials/{id}` | `vault.credentials.read` | доступный workspace |
| PATCH | `/api/v1/vault/credentials/{id}` | `vault.credentials.update` | CSRF, owner/manager, `If-Match` |
| PUT | `/api/v1/vault/credentials/{id}/secret` | `vault.credentials.update` | CSRF, owner/manager, `If-Match` |
| DELETE | `/api/v1/vault/credentials/{id}` | `vault.credentials.delete` | CSRF, owner/manager, `If-Match`, нет активных bindings |

API использует обычную сессию администратора: access token **не** обладает правом менять или раскрывать секреты. В production HTTPS обязателен. GET возвращают только имя, идентификатор, workspace, тип, описание, состояние, дату, версию и ETag. Ciphertext и plaintext не включаются ни в metadata API, ни в аудит.

### Создание

`POST /api/v1/vault/credentials`, тело:

```json
{"workspace_id":"UUID","name":"Production API","description":"Только тестовый пример","type":"api_token","secret":"<REPLACE_WITH_SECRET>"}
```

Поле `secret` обязательно, 1–65536 байт UTF-8. `name` и `description` должны содержать только несекретные данные. Поддерживаются `generic_secret` и `api_token`; расширенные credential profiles запланированы на 0.3.3.

**Никогда не сохранять секреты в shell history, URL, application logs или CI artifacts.** Для production отправлять JSON через защищённый клиент непосредственно по HTTPS, а не печатать секрет в командной строке.

### Изменение/замена

Ответы возвращают ETag вида `"<UUID>:1"`. Перед PATCH, PUT и DELETE клиент должен предоставить актуальный `If-Match`. При конфликте версии — HTTP 412, при отсутствии — HTTP 428. При замене секрета используется тот же UUID/workspace/type как криптографический AAD; предыдущий plaintext не выдаётся.

PATCH поддерживает только `name`, `description` и `status=active|disabled`. DELETE архивирует, а не уничтожает зашифрованные байты и audit trail; при активных Inventory bindings возвращается 409. Для архивирования сначала снять привязки.

## Legacy credentials

Миграция **не** пытается расшифровать ранее сохранённые `credentials.encrypted_payload`. Legacy-записи сохраняются с `vault_format=legacy`, `state=legacy` и недоступны для новых Vault CRUD и привязок. Ранее созданные ссылки не удаляются. Неизвестные старые ciphertext не считаются пригодными, их безопасный перенос требует отдельной процедуры подтверждения владения и переустановки секрета.

## Тестирование

```sh
php tests/vault-crypto-smoke.php
php tests/vault-crud-smoke.php
php scripts/verify-release.php
```

Настоящая интеграция запускается **только** на disposable PostgreSQL 17+ БД с названием `cajeer_test_*`:

```sh
CAJEER_PG_TEST=1 CAJEER_PG_TEST_DATABASE=cajeer_test_vault \
CAJEER_PG_TEST_USERNAME=cajeer CAJEER_PG_TEST_PASSWORD='local-ci-only' \
php tests/vault-crud-postgres-integration.php
```

Тест создаёт отдельную случайную схему, проверяет реальный ciphertext, RBAC, владельцев workspace, ETag, транзакционный аудит, запрет legacy enrollment, disable/archive и удаляет созданную схему. Не запускать на production БД.

## Известные границы версии

В 0.3.2 нет plaintext reveal API, ротации master key, автоматической ротации удалённых токенов, полноценных коннекторов и Nuxt Vault UI. Эти функции намеренно отложены до защищённых следующих этапов. Отсутствие подтверждённого PostgreSQL integration, `composer.lock`, `admin/package-lock.json` и production Nuxt build блокирует полный production release-gate.
