# CajeerServers 0.1.4 — установка

CajeerServers — веб-ресурс, не операционная система. **Первичная установка действительно создаёт PostgreSQL-схему, первого администратора и состояние установки.** Вход пользователей появится в версии 0.1.5; наличие записи администратора в 0.1.4 ещё не предоставляет работающий UI login.

## Требования

- PHP 8.4+, расширения `pdo`, `pdo_pgsql`, `json`, `openssl`, `sodium`; Argon2id доступен через `password_hash`.
- PostgreSQL 17+ с **пустой** БД и пользователем, способным создавать таблицы и extension `pgcrypto` (это требование миграции 0001).
- Nginx + PHP-FPM, DocumentRoot **строго** `public/`; для установки через браузер нужен HTTPS, либо HTTP на localhost при `APP_ENV=local`.
- Права на создание/замену `.env` в корне проекта, запись в `storage/app`, `storage/logs`, `storage/tmp` и `storage/cache`.
- Для удалённой БД в production установка требует `DB_SSLMODE=verify-full` и корректную проверку серверного сертификата.
- Резервная копия данных, если вы обновляете уже существующий репозиторий. Не применяйте установщик к БД с существующими пользователями/неучтённой схемой.

## Вариант A: CLI

```bash
php bin/cajeer install:check
php bin/cajeer install:status
# Секреты создавайте вне репозитория и не передавайте в argv.
umask 077
printf '%s\n' 'your-long-random-password' > /tmp/cajeer-admin-secret
printf '%s\n' 'database-secret' > /tmp/cajeer-db-secret
php bin/cajeer install \
  --url=https://cajeerservers.example.com \
  --env=production \
  --db-host=127.0.0.1 \
  --db-database=cajeerservers \
  --db-username=cajeer \
  --db-password-file=/tmp/cajeer-db-secret \
  --admin-email=admin@example.com \
  --admin-name=Administrator \
  --admin-password-file=/tmp/cajeer-admin-secret
rm -f /tmp/cajeer-admin-secret /tmp/cajeer-db-secret
php bin/cajeer install:status
php bin/cajeer migrate:validate
```

Используйте специально подготовленные файлы с правами `0600`. При отсутствии `--admin-*` CLI может спросить адрес/имя и скрыто запросить пароль, если терминал поддерживает `stty`; для CI обязательно задавайте секрет через файл. Если `.env` уже содержит настройки БД, опции DB_* можно опустить. При установке на удалённый PostgreSQL установите `--db-sslmode=verify-full` и `--db-sslrootcert=...`. Не выводите токены и пароли в pipeline-логах.

Можно использовать `php installer/cli/install.php` с теми же флагами.

## Вариант B: Web Installer

```bash
# Выполняется только локально на сервере, stdout содержит секретный код:
php bin/cajeer install:web-token
```

Откройте `https://ваш-домен/install`, введите одноразовый код и параметры PostgreSQL/администратора. Запросы POST защищены session CSRF-токеном, одноразовым кодом и требованием HTTPS. После завершения локальный токен аннулируется, `/install` отвечает 410. **Не размещайте** `installer/web/` как отдельный веб-каталог и не публикуйте корень проекта; обслуживайте маршрут через `public/index.php`.

## Порядок действий и восстановление

Установщик проверяет расширения, права и версии, валидирует данные, проверяет подключение к PostgreSQL и отсутствие существующих пользователей/установки, затем атомарно записывает `.env` (0600), выполняет миграции и в PostgreSQL-транзакции создаёт администратора, назначает роль `administrator`, записывает `cajeer_installation` и событие `installation.completed`. Локальный marker `storage/app/installed.json` появляется **после успешного коммита**. Второй административный аккаунт повторной установкой создать нельзя.

Если процесс оборвался **после** коммита транзакции, но **до** записи локального marker, запустите с серверного CLI:

```bash
php bin/cajeer install:recover
```

Команда проверяет существование записи `cajeer_installation` в PostgreSQL и восстанавливает только локальный marker, не создавая пользователей повторно. Если сбой произошёл до коммита, повторите установку после устранения причины. Если схема содержит пользователей, но не содержит installation marker, автоматическая установка **отказывается** от вмешательства: требуется ручная сверка состояния БД.

## Проверки

```bash
APP_ENV=local php tests/runtime-smoke.php
APP_ENV=local php tests/http-smoke.php
APP_ENV=local php tests/database-smoke.php
APP_ENV=local php tests/installer-smoke.php
# Отдельный реальный integration test на пустой disposable PostgreSQL 17/18:
CAJEER_INSTALL_PG_TEST=1 CAJEER_PG_TEST_DATABASE=cajeer_installer_test \
 CAJEER_PG_TEST_USERNAME=cajeer CAJEER_PG_TEST_PASSWORD='test-only' \
 php tests/installer-postgres-integration.php
```

**Важно:** последний тест изменяет переданную БД. Он намеренно не очищает созданные таблицы, чтобы не удалять данные без ведома оператора. Используйте только отдельную тестовую БД с именем на `_test`; удаление после теста выполняйте вручную.
