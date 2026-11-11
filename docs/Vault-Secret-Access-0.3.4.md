# CajeerServers 0.3.4 — Secure Access Control & Secret Consumption

## Что действительно реализовано

Внутренний `SecretAccessService` выдаёт **одноразовую** авторизацию (не credential и не bearer token) на заданную связку сессии, пользователя, Inventory asset, credential, workspace, purpose и версии. TTL — 120 секунд. Выдача происходит только после повторной проверки пароля и свежего TOTP (6 цифр, replay запрещён в `user_mfa.last_counter`). На уровне PostgreSQL дополнительно проверяются разрешение `vault.credentials.use`, активная сессия с MFA, роль owner/manager, scope и существующая привязка.

1. Применить миграцию `0019_vault_secret_access.sql` поверх схемы 0.3.3 и сделать backup.
2. Включить TOTP для администратора и назначить нужному пользователю отдельное разрешение `vault.credentials.use`; администратор получает разрешение через миграцию.
3. Создать credential и назначить его доступному Inventory asset с совместимым purpose (`api`, `ssh`, `winrm`, `snmp`, `custom`).
4. Авторизовать использование POST-запросом с серверной сессией, CSRF и JSON-полями `asset_id`, `purpose`, `password`, `totp` по адресу `/api/v1/vault/credentials/{id}/authorize-use`. Ответ сообщает только `authorized` и `expires_in`; секрет и grant token **не возвращаются**.
5. Доверенный серверный код вызывает `SecretAccessService::consumeForAsset($principal, $assetId, $credentialId, $purpose, $trustedHandler)`. PHP callback получает сериализованный секрет и его тип. Для структурированного профиля внутри callback допустимо `CredentialProfile::decodeForInternalUse($type,$serialized)`; результат должен применяться только к разрешённой внутренней операции, не возвращаться в HTTP и не попадать в очередь/логи.

## Что предотвращается

- Внешние bearer API tokens не поддерживают Vault use, HTTP-метода decrypt/use нет.
- Viewer и пользователи других workspaces не могут потреблять секреты; право metadata-read недостаточно.
- Отозванная/истекшая сессия, отсутствие свежего MFA, неправильный пароль или повторный TOTP блокируют выдачу.
- Неактивный credential, удалённый asset, отсутствие подходящей credential binding, несовместимый type/purpose и изменение версии блокируют использование.
- Разрешение атомарно помечается `consumed_at` **до дешифрования**; повторное чтение невозможно. Изменение версии или state credential делает старые разрешения непригодными.
- Отправленные пароль/TOTP не сохраняются; rate limit постоянный, действует 8 попыток на 15 минут.
- Аудит `vault.credential.use_authorized`, `vault.credential.use_denied`, `vault.credential.accessed` содержит только ID, тип события и безопасные поля.

## Границы безопасности

Это безопасный внутренний PHP API, а не удалённая консоль или готовый Proxmox/SSH-клиент. Only trusted application adapters may receive a plaintext copy. PHP не даёт гарантированной очистки всех копий памяти: сервис обнуляет исходную строку после callback, но адаптер должен избегать своих сохраняемых копий, логирования, исключений и возврата секрета. После атомарного потребления другая операция может отозвать credential во время уже выполняющегося внешнего действия: адаптер обязан учитывать этот временной интервал.

`security:prune --days=7 --apply` очищает expired grant rows (не затрагивая immutable audit log). Рекомендуется регулярно запускать команду через Scheduler.

## Проверки

```bash
php bin/cajeer migrate
php tests/vault-access-smoke.php
php scripts/verify-release.php
```

Для полного теста с **отдельной** БД `cajeer_test_*`, доступным PostgreSQL 17+/`pdo_pgsql` и изолированными тестовыми данными:

```bash
CAJEER_PG_TEST=1 CAJEER_PG_TEST_DATABASE=cajeer_test_vault php tests/vault-access-postgres-integration.php
```

Запрещено запускать integration-тесты на production-БД. Полный production-gate требует PHP, PostgreSQL/Redis, тестовой сборки Nuxt, реального HTTP deployment и browser E2E.
