# CajeerServers 0.3.3 — Credential Profiles & Validation

## Реализованные профили

`CredentialProfile` выполняет проверку **реальных входящих данных** при создании и замене секрета. Структурированные значения целиком сериализуются в JSON и шифруются существующим CJV1 envelope через `VaultCrypto`. В таблицу `credentials` записывается только бинарный ciphertext; никакие поля профиля не копируются в публичную `metadata`.

| `type` | `secret` | Обязательные поля | Дополнительные поля |
|---|---|---|---|
| `generic_secret` | строка | секрет (строка) | — |
| `api_token` | строка | токен (строка) | — |
| `proxmox_api` | объект | `token_id`, `token_secret` | — |
| `vmmanager_api` | объект | `token` | — |
| `ssh_password` | объект | `username`, `password` | — |
| `ssh_private_key` | объект | `username`, `private_key` | `passphrase` |
| `winrm` | объект | `username`, `password` | `auth` |
| `snmp_v2c` | объект | `community` | — |
| `snmp_v3` | объект | `username`, `auth_protocol`, `auth_passphrase` | `privacy_protocol`, `privacy_passphrase` (только вместе) |

Все незаявленные поля отклоняются статусом 422. Данные профиля не включаются в ошибки валидации. `api_token` и `generic_secret` сохраняют строковый формат версии 0.3.2; существующие CJV1-значения остаются совместимыми без миграций.

### Точные ограничения

- `proxmox_api.token_id`: `name@realm!tokenname` с буквами, цифрами, точками, дефисами и подчёркиваниями. Это проверка синтаксиса идентификатора, не подтверждение существования токена.
- `winrm.auth`: одно из `negotiate`, `ntlm`, `kerberos`, `basic`. Передача паролей в удалённую инфраструктуру **не выполняется** этой версией; будущий коннектор обязан проверять HTTPS и политику выбранного механизма.
- `snmp_v3.auth_protocol`: только `sha256` / `sha512`; `privacy_protocol`: `aes128` / `aes256`. Оба privacy-поля обязательны одновременно, длина passphrase — минимум 8 символов (байтов).
- `ssh_private_key`: поддерживаются PEM-ключи, которые OpenSSL может прочитать (с учётом passphrase), и структурно корректный контейнер `OPENSSH PRIVATE KEY`. Для зашифрованного OpenSSH-ключа проверяются контейнер и наличие passphrase, но **не проверяется её правильность** до взаимодействия с SSH-транспортом. Неиспользуемые/неподдерживаемые кодировки отклоняются.
- Общее ограничение: 64 КиБ для сериализованного секретного значения; отсутствие NUL, некорректного UTF-8 и неизвестных полей; чётко ограниченные длины отдельных строк.

### HTTP API

`GET /api/v1/vault/profiles` возвращает только список типов и имена обязательных/необязательных полей. Требует серверной сессии и разрешения `vault.credentials.read`.

`POST /api/v1/vault/credentials` принимает объект `secret` для новых профилей, а `PUT /api/v1/vault/credentials/{id}/secret` заменяет значение **с тем же типом**. После создания тип, UUID и workspace криптографически неизменяемы. Оба изменяющих маршрута требуют аутентификацию сессией, CSRF, RBAC и workspace owner/manager; для замены также нужен `If-Match`.

Пример **только структуры запроса** (не используйте реальные секреты в логах, примерах или CI):

```json
{
  "workspace_id": "e2222222-bbbb-4ccc-8abc-222222222222",
  "name": "Proxmox monitoring",
  "type": "proxmox_api",
  "secret": {
    "token_id": "root@pam!monitoring",
    "token_secret": "<test-only-placeholder>"
  }
}
```

В ответе возвращаются только метаданные. Не включайте данные `secret` в URL, внешние журналы и очередь задач. Не передавайте реальные секреты в автоматические тесты.

### Реальное использование и интеграция

0.3.3 **не открывает endpoint чтения plaintext** и не выполняет подключения к Proxmox, VMmanager, SSH, SNMP или WinRM. Безопасное использование plaintext внутренним сервисом планируется на 0.3.4, а API-коннекторы — на более поздние версии. Профиль гарантирует валидацию *формата хранения*, а не работоспособность учётной записи на внешнем устройстве.

### Проверки

```bash
php tests/vault-profiles-smoke.php
php tests/vault-crud-smoke.php
php scripts/verify-release.php
```

Для проверки на настоящем PostgreSQL используйте отдельную тестовую БД `cajeer_test_*`, переменные `CAJEER_PG_TEST=1` и `CAJEER_PG_TEST_DATABASE`, затем `php tests/vault-crud-postgres-integration.php`. Тест дополнен сквозным сохранением, расшифровкой, повторным шифрованием и проверкой метаданных для всех новых профилей. **Не запускайте его на production-БД.**

Новых миграций в 0.3.3 нет: существующие `credentials.type varchar(80)` и `encrypted_payload bytea` подходят для всех профилей. Production-допуск требует успешного PostgreSQL integration, проверки Nuxt build и строгого release-gate с настоящими lock-файлами.
