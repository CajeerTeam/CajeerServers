# Очереди и cache — CajeerServers 0.1.7

Рабочие драйверы: **Redis** (RESP2 socket с AUTH/TLS) и **PostgreSQL 17+**. RabbitMQ пока не поддерживается; выбор `QUEUE_CONNECTION=rabbitmq` завершается ошибкой. Для PostgreSQL очередь хранится в `queue_jobs`, созданной миграцией `0006_queue_scheduler.sql`.

## Конфигурация

```dotenv
CACHE_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_USERNAME=
REDIS_PASSWORD=
REDIS_DB=0
REDIS_TLS=false
REDIS_CACHE_PREFIX=cajeer:cache:
REDIS_QUEUE_PREFIX=cajeer:queue:
QUEUE_CONNECTION=redis
QUEUE_NAME=default
QUEUE_LEASE_SECONDS=60
```

На production при удалённом Redis необходим `REDIS_TLS=true` с валидным сертификатом. В рантайме при `APP_ENV=production` не-локальный plaintext Redis запрещён. У Redis должны быть ограниченные ACL-права только на собственное пространство ключей и необходимые команды (`PING`, `GET`, `SET`, `DEL`, `EXISTS`, `EVAL`, `ZRANGE*`, `Z*`, `TIME`, `AUTH`, `SELECT`). Скрипты Lua выполняются сервером Redis атомарно. Cache TTL обязателен.

## CLI

Сначала выполнить установку и миграции: `php bin/cajeer migrate`. Фоновые операции доступны только после установки приложения.

```bash
php bin/cajeer cache:check
php bin/cajeer cache:put --key=system.example --value=hello --ttl=300
php bin/cajeer cache:get --key=system.example
php bin/cajeer cache:delete --key=system.example

php bin/cajeer queue:push --type=storage.probe --queue=default --attempts=3
php bin/cajeer queue:push --type=runtime.diagnostics --delay=10
php bin/cajeer queue:work --once --queue=default
php bin/cajeer queue:work --max-seconds=3600 --queue=default --lease=60
php bin/cajeer queue:status --id=<uuid>
php bin/cajeer queue:failed --queue=default --limit=50
php bin/cajeer queue:retry --id=<uuid>
php bin/cajeer queue:prune --queue=default --days=7 --limit=500
```

`queue:push` допускает только зарегистрированные типы: `runtime.diagnostics` (проверка окружения), `storage.probe` (реальная проверка записи/чтения/удаления случайного объекта), `queue.prune` (удаление истории завершённых/failed задач старше указанного числа дней). Передача аргументов задач: `--payload-file=/path/to/json-object`. **Никаких shell команд, eval или произвольных PHP-функций worker не выполняет.** Фактический результат хранится в backend очереди и виден через `queue:status`; ошибки сохраняются в безопасном виде без текстов исключений, которые могут содержать секреты.

Worker использует reservation token и lease. При падении worker задача вновь доступна после истечения lease; после превышения `max_attempts` перемещается в failed. Для кратковременных встроенных обработчиков 60 секунд достаточно; при добавлении долгих задач потребуется lease renewal, обработка отмены и идемпотентность внешних действий. `queue:work --max-seconds=3600` удобно запускать как systemd service под непривилегированным пользователем, с autorestart. Запускайте несколько воркеров для масштабирования; очередь поддерживает конкуренцию.

`queue:prune` необходимо выполнять регулярно; по умолчанию история не удаляется сама. Это важно для Redis memory usage и PostgreSQL table bloat.

## Тесты

```bash
php tests/infrastructure-smoke.php
CAJEER_REDIS_TEST=1 php tests/infrastructure-redis-integration.php
CAJEER_PG_TEST=1 CAJEER_PG_TEST_DATABASE=cajeer_test_queue \
  CAJEER_PG_TEST_USERNAME=cajeer CAJEER_PG_TEST_PASSWORD='<test-secret>' \
  php tests/infrastructure-postgres-integration.php
```

Не направляйте интеграционный PostgreSQL-тест в production-БД. Redis-тест использует собственный случайный prefix и не выполняет `FLUSHDB`/`FLUSHALL`.
