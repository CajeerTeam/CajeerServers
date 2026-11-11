# Vault Cryptography — CajeerServers 0.3.1

Этот релиз содержит **исполняемый** криптографический механизм и защиту идентичности master key в PostgreSQL. Это **не** публичный CRUD секретов (0.3.2) и **не** система ротации (0.3.5). Не публикуйте plaintext через HTTP.

## Первоначальная настройка

1. Создайте за пределами веб-корня частный каталог, доступный только пользователю, запускающему PHP-FPM/CLI; файл должен принадлежать тому же пользователю:
   `install -d -m 0700 /etc/cajeerservers` (адаптируйте владельца).
2. Выполните `php bin/cajeer vault:key:generate --output=/etc/cajeerservers/vault.key`. Создание **эксклюзивное**: существующий файл не перезаписывается, права — `0600`. Ключ не печатается.
3. Настройте `VAULT_MASTER_KEY_FILE=/etc/cajeerservers/vault.key` в окружении PHP-FPM и CLI. **Не используйте `APP_KEY`** и устаревший `VAULT_KEY`; не задавайте одновременно `VAULT_MASTER_KEY` и `VAULT_MASTER_KEY_FILE`.
4. Создайте резервную копию БД. Примените `php bin/cajeer migrate` (миграция `0017_vault_key_verifier.sql`).
5. Проверьте `php bin/cajeer vault:key:status`. Далее один раз `php bin/cajeer vault:init`.
6. Проверьте `php bin/cajeer vault:check`, затем `php bin/cajeer vault:probe`. Последняя команда реально записывает и читает encrypted `credentials.encrypted_payload` внутри транзакции **с обязательным rollback**.

`vault:init` всегда отказывает при повторном выполнении, а `vault:check` отказывает при неверном ключе или повреждении verifier. Не удаляйте verifier с целью «переинициализации»: ключ и уже зашифрованные данные могут стать недоступны.

`VAULT_MASTER_KEY` допускает строго `base64:` + 32 случайных байта в base64; `VAULT_MASTER_KEY_FILE` — абсолютный путь к приватному файлу, прочитанному под учётной записью процесса. Приватный файл не должен быть симлинком или иметь права группы/остальных пользователей.

## Cryptography

- `VaultCrypto::seal/open(plaintext, credentialUUID, workspaceUUID, type)`; XChaCha20-Poly1305, новый DEK и nonce для каждой записи, отдельные authenticated contexts для wrapped DEK и payload.
- Структура binary envelope `CJV1 | 16-byte key-id | 24-byte wrap nonce | 48-byte wrapped DEK | 24-byte data nonce | ciphertext+tag`.
- Контекст шифрования включает UUID credential, UUID workspace и type. Перенос credential между workspace без расшифровки в старом контексте и повторного шифрования запрещён.
- DEK и производный KEK очищаются `sodium_memzero()`; ключ master хранится вне БД.
- Формат `CJV1` фиксирован; смена master key / rotation отсутствует в 0.3.1. **Не заменяйте файл ключа на работающей установке.**
- `key_id` не раскрывает ключ и используется только для подбора версии; при смене ключа ciphertext не расшифровывается без исходного root key.

## Проверки

```sh
php tests/vault-crypto-smoke.php
CAJEER_PG_TEST=1 CAJEER_PG_TEST_DATABASE=cajeer_test_vault php tests/vault-postgres-integration.php
```

Второй тест применяет все миграции в случайной временной схеме тестовой БД, проверяет однократную инициализацию, ошибки неверного ключа, сохранение реального encrypted `bytea`, аутентификацию и запрет UPDATE/DELETE/TRUNCATE verifier. Требуются PostgreSQL 17+, `pdo_pgsql`, `sodium`; тест **не** разрешено запускать на production DB.

В случае утери root key восстановить ciphertext невозможно. Храните резервную копию ключа отдельно от резервной копии PostgreSQL, в защищённом месте. Не добавляйте файл ключа в Git, ZIP, логи, CI artifacts или публичный storage.
