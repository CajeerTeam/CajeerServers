# CajeerServers 0.3.8 — Encrypted Backup & Disaster Recovery

Это **действующий операторский CLI**, а не набор API-контрактов. Для PostgreSQL 17/18 используется `pg_dump -Fc` → приватный потоковый libsodium secretstream XChaCha20-Poly1305 (256 KiB на фрейм) → `.cjdr`; восстановление читает архив в `pg_restore --single-transaction --exit-on-error`. При создании и восстановлении **полный SQL-дамп в открытом виде не записывается на диск**. Локальные storage-файлы и необходимые ключи приложения включаются в тот же шифрованный поток (если не выбран `--db-only`). PostgreSQL credentials передаются дочерним процессам только в environment, а не в argv.

## Предварительные условия

- PHP 8.4+ с `ext-sodium`, `ext-pdo_pgsql` для восстановления, `proc_open` и `fsync` (если PHP поддерживает); PostgreSQL сервер 17+, установленный **совместимый** `pg_dump`/`pg_restore`, по умолчанию `/usr/bin/pg_dump`, `/usr/bin/pg_restore`. Другие расположения через `PG_DUMP_BINARY`, `PG_RESTORE_BINARY` (только абсолютные исполняемые файлы).
- `DB_CONNECTION=pgsql`, рабочие параметры PostgreSQL и права на backup/restore. Для удалённой production-БД требуется `DB_SSLMODE=verify-full` и доверенный CA. Другие драйверы БД в 0.3.8 не поддержаны.
- Операторский CLI с правами на чтение исходного локального storage и `.env`, на чтение Vault master key, а также на запись **нового** защищённого каталога резервных копий. Команды не открываются через HTTP API.
- До полного backup/restore **остановить** web-сервер (или перевести в режим read-only), очереди, scheduler, коннекторы и другие процессы записи. `pg_dump` имеет согласованный снимок только БД, не общей файловой системы. В manifest намеренно записано `consistent_storage: false`: CLI не может гарантировать кросс-системный snapshot без внешнего quiesce.

## Генерация и хранение ключа

```bash
install -d -m 0700 /srv/cajeer-private /srv/cajeer-backups
php bin/cajeer backup:key:generate --output=/srv/cajeer-private/backup.key
# Ключ должен быть собственностью CLI-пользователя с режимом 0600.
# BACKUP_KEY_FILE=/srv/cajeer-private/backup.key в приватном .env
```

Не используйте `APP_KEY` или `VAULT_MASTER_KEY` в качестве ключа резервной копии. **Отдельную копию `/srv/cajeer-private/backup.key` хранить вне восстанавливаемого узла**, в контролируемом офлайн-хранилище. Потеря этого ключа делает архивы невосстановимыми. Ключ не включается в `.cjdr` и не выводится в логи/CLI.

## Создание, проверка, автоматизация

```bash
php bin/cajeer backup:create --output=/srv/cajeer-backups/cajeer-20261111-020000.cjdr
php bin/cajeer backup:verify --archive=/srv/cajeer-backups/cajeer-20261111-020000.cjdr

# Только PostgreSQL (НЕ полноценный disaster-recovery backup):
php bin/cajeer backup:create --db-only --output=/srv/cajeer-backups/db-20261111.cjdr

# Аналогичные операторы через старые CLI-обёртки:
php bin/backup.php --output=/srv/cajeer-backups/daily.cjdr
```

Пути должны быть абсолютными, c существующими обычными родительскими каталогами. Архив **никогда не перезаписывается**, создаётся как `0600` и публикуется только после успешного `pg_dump` и повторной криптографической проверки. Взломанный, усечённый, склеенный или неверно расшифрованный архив получает ошибку; кроме AEAD проверяются SHA-256 всех файлов и SQL-дампа, размеры и финальный manifest. Backup **нельзя** помещать в web root, в исходный storage или на сам путь секретного ключа.

Создание можно запускать из системного cron либо внешнего оркестратора **только после обеспечения quiesce** и с запуском `backup:verify`; для 0.3.8 нет встроенных job/S3 backup задач и автоматической retention-политики. Ограничения по размеру диска, место под архив и политику off-site/retention обеспечивать отдельно. Рекомендуется регулярно проверять **фактическое восстановление** на отдельном стенде.

## Offline восстановление на чистой инфраструктуре

1. Разверните соответствующий код CajeerServers и зависимости, остановите все web/queue/scheduler/connector worker процессы. Создайте **пустую** БД PostgreSQL с правами CREATE/DDL и подходящим PostgreSQL client. Не выполняйте миграции в этой пустой БД перед восстановлением.
2. Доставьте архив и **отдельно сохранённый** приватный backup.key на новый сервер. Установите DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD так, чтобы оператор имел право работать с **новой пустой** БД. Укажите абсолютные пути для восстановления файлов, **которых ещё не существует**.
3. Проверьте и восстановите:

```bash
php bin/cajeer backup:verify \
  --archive=/srv/cajeer-backups/cajeer-20261111-020000.cjdr \
  --key-file=/srv/cajeer-private/backup.key

php bin/cajeer backup:restore \
  --archive=/srv/cajeer-backups/cajeer-20261111-020000.cjdr \
  --key-file=/srv/cajeer-private/backup.key \
  --target-db=cajeer_recovery_empty \
  --confirm-target=cajeer_recovery_empty --offline \
  --storage-target=/srv/cajeer-data-restored \
  --recovery-target=/srv/cajeer-private/recovered-20261111

# Эквивалентно: php bin/restore.php --archive=... --target-db=... ...
```

`--offline` является **подтверждением оператора**, CLI сам не способен остановить внешние процессы. `--target-db` и `--confirm-target` должны в точности совпадать. Не допускается восстановление в БД с пользовательскими таблицами/представлениями/функциями; новые storage и recovery directories не должны существовать, а в public root они недопустимы. Проверка AEAD и manifest выполняется **до любых изменений**, далее файлы поштучно восстанавливаются с правами `0600` и сохраняются до начала транзакции `pg_restore`. `pg_restore --single-transaction` откатывает SQL при стандартных ошибках до COMMIT. Если процесс восстановления уже запущен, но результат COMMIT нельзя подтвердить (обрыв соединения/клиента/питания), восстановленные каталоги **сохраняются** для ручной проверки, а не стираются: это исключает автоматическую потерю файлов потенциально завершившегося восстановления. Незавершённые staging-каталоги удаляются. PostgreSQL и FS не обладают единой атомарной транзакцией; после аварии обязательно проверить БД и файлы и при необходимости повторить восстановление в **новой** чистой БД/каталоге.

4. В `--recovery-target` содержатся `.env` (если был файл), `app.key` и `vault.key` (если эти ключи были настроены при backup). Секреты **не устанавливаются автоматически в live-конфигурацию**. Перенастройте `.env` под новые hostname/DB/storage, установите из `vault.key` правильный `VAULT_MASTER_KEY_FILE` (или иной защищённый источник) и тот же `APP_KEY`; `BACKUP_KEY_FILE` задайте отдельно. Не публикуйте восстановленные файлы под HTTP.
5. Проверьте `php bin/cajeer vault:check`, `database:check`, `migrate:validate`, `inventory:integrity`, затем release/health checks и только потом разрешайте writes и запускайте фоновые процессы. Если секреты хранились только во внешнем secret manager, конфигурацию manager/ключи следует восстановить отдельно.

## Границы покрытия 0.3.8

- Снимок **одной PostgreSQL database**, а не всей PostgreSQL cluster: роли, tablespaces, внешние extensions, сертификаты и конфигурация сервера должны быть развернуты отдельно. Не поддерживается PITR, WAL archiving, физический PostgreSQL restore, point-in-time rollback или автоматическое переключение узлов.
- В полном режиме копируется **local disk** (`STORAGE_DISK=local`); при S3 выбор полномасштабного резервного копирования возвращает ошибку. Не заявляется охват S3 objects: нужен независимый проверенный S3 backup/lifecycle. `--db-only` делает только резервную копию PostgreSQL.
- В backup включаются `.env` из корня приложения (при наличии) и работающие секреты из `APP_KEY`, `VAULT_MASTER_KEY`/`VAULT_MASTER_KEY_FILE` при их конфигурации. Не включаются другие внешние secrets, файлы ОС, TLS certificates, контейнерные volumes вне storage-root, исходный код, плагины, Redis runtime/queue, traces или ClickHouse.
- Восстановление в существующую непустую production-БД не поддерживается: намеренно ограничено **чистым** изолированным target. Переключение трафика после smoke-tests — обязанность оператора.
- E2E тест `tests/backup-postgres-integration.php` выполняется **только** с отдельной `cajeer_test_*` БД и `CAJEER_PG_TEST=1`; smoke `tests/backup-smoke.php` покрывает crypto/stream/повреждения/отказы клиента без настоящей PostgreSQL.
