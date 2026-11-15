# Runtime Reliability & Recovery — 0.9.5

## Обновление

Остановить HTTP, scheduler, queue и connector workers и прочие writers. Сохранить рабочий release 0.9.4, `.env`, storage и ключи. Наложить изменения на новую копию исходников. В установленной системе выполнить:

```sh
php bin/cajeer maintenance:enable
php bin/cajeer upgrade:apply --previous=/opt/cajeer-releases/0.9.4 \
  --archive=/secure/pre-upgrade-0.9.4.cjdr --key-file=/secure/backup.key --offline
php bin/cajeer migrate:validate
php bin/cajeer maintenance:disable
php bin/cajeer health:ready
```

Миграция 0063 добавляет durable retry metadata к Scheduler outbox и ошибку расписания; прежние pending/sent записи, очередь, grants и планы сохраняются. Все 62 исторические миграции неизменны.

## Queue и worker

- `queue:work` для общих PostgreSQL/Redis задач, `connector:work` исключительно для отдельной PostgreSQL очереди. Redis не принимает `connector.execute` и не резервирует/очищает `connectors`.
- PostgreSQL использует receipt + live lease, `clock_timestamp()`, ограниченные recovery sweeps и `SKIP LOCKED`. Redis обновляет job и processing sorted set одним Lua-скриптом. Истёкший или заменённый receipt не может продлить lease, ACK или release.
- CLI требует PHP pcntl. Watchdog завершает процесс SIGALRM, если reservation, handler или checkpoint зависает; запас перед lease — одна секунда. `SIGTERM`/`SIGINT` прекращают новые reservations и дают закончить текущую задачу. `--max-seconds` ограничивает intake, а не длительность всех checkpoint задачи.
- Импорт продлевает lease до и внутри транзакции каждой строки, проверяет её перед commit; PostgreSQL renewal использует то же подключение и фиксируется вместе с транзакцией. При Redis renewal нет распределённой транзакции с PostgreSQL, поэтому domain receipt/checkpoint и session advisory lock остаются обязательными. После падения процесс продолжает с последнего committed checkpoint. Повтор после потери ACK завершённого импорта возвращает сохранённый outcome без повторного reconcile.
- Generic handler retries: capped exponential backoff + jitter, после исчерпания attempts — failed. `queue:retry` разрешён для общих задач, но не для connector runs; для них используется redrive через план и новую авторизацию при необходимости. Не добавлять произвольные неидемпотентные handlers без отдельного domain receipt.
- Redis сохраняет JSON payload/result отдельно от Lua-представления: cjson округляет большие integers. Новые записи не теряют их точность; исторические Redis записи, уже округлённые прежним драйвером, нельзя восстановить автоматически. Дедупликация сравнивает JSON-объекты независимо от порядка ключей, сохраняет порядок массивов и различает строки/числа.

```sh
php bin/cajeer queue:work --queue=default --max-seconds=300 --lease=120
php bin/cajeer queue:stats --queue=default
php bin/cajeer queue:stats --queue=connectors
php bin/cajeer queue:failed --queue=default
php bin/cajeer queue:retry --id=JOB_UUID
php bin/cajeer connector:work 20
```

Redis должен быть выделенным durable экземпляром с AOF/persistence, без eviction queue keys. Lease/ACK не гарантируют exactly-once при потере всей Redis БД, переключении на отставшую реплику или удалении pending outbox/queue history. Не менять queue backend при наличии pending deliveries без согласованного offline переноса. Connector queue хранится в PostgreSQL независимо от `QUEUE_CONNECTION`.

## Scheduler

Scheduler coalesces missed occurrences: один job за пропущенный интервал простоя, next_run_at вычисляется строго после текущего времени БД. Исторический backlog не воспроизводится поминутно. Пока у задания есть pending outbox, новые due-слоты объединяются с ним (`coalesced` в отчёте tick), не создавая бесконечный backlog при отказе очереди. Некорректное расписание выключается с `last_error=invalid_schedule`, соседние задачи продолжаются. Оператор проверяет и исправляет расписание перед повторным enable.

Каждая outbox запись независимо повторяет отправку после 5–10 секунд, далее экспоненциальный backoff с jitter до 900 секунд. PostgreSQL push выполняется в savepoint, чтобы rollback ошибки не уничтожил retry metadata; Redis push повторяет тот же outbox UUID. Pending запись не удаляется при ошибке. Отключение расписания не отменяет уже созданный outbox или queued job. Неустранимые конфликты видны оператору и требуют исправления; автоматического удаления pending записи нет.

```sh
php bin/cajeer scheduler:run --batch=25
php bin/cajeer scheduler:list
php bin/cajeer scheduler:outbox --limit=100
php bin/cajeer scheduler:stats
```

`dispatch_errors` и `schedule_errors` делают scheduler:run nonzero. `scheduler:outbox` не выводит payload/credentials. `queue:stats` показывает expired_leases, `scheduler:stats` — invalid, retrying, due. Настроить alert на устойчивый рост этих значений.

## Connector recovery и действия

Existing PostgreSQL target/provider/workspace advisory locks, Vault/grant revalidation, fence при commit и атомарный connector domain result сохранены. Connector worker ограничивает каждую попытку 240 секундами при lease 300 секунд; SIGTERM останавливает intake. Busy contention классифицируется и повторяется с jitter; circuit open и terminal auth ошибки сохраняют прежнюю политику. Истёкшие deliveries восстанавливаются bounded batches. После lost ACK committed результат повторно используется, remote work не запускается повторно. После crash между remote POST и сохранением результата action остаётся `dispatching`; explicit refresh под target lock переводит его в `unknown`. Новый POST по этому guest блокирован до operator reconciliation/ack. Никакого автоматического повторного destructive POST.

## systemd

Опциональные units находятся в `deploy/systemd`: `cajeer-queue-worker.service`, `cajeer-connector-worker.service`, `cajeer-scheduler.service`, `cajeer-scheduler.timer`. Проверить user/group cajeer, /opt/cajeer, PHP pcntl, storage/key permissions, конфигурацию агентов и подключения БД. Копировать units только в контролируемом deployment, затем `systemctl daemon-reload` и `systemctl enable --now cajeer-queue-worker.service cajeer-connector-worker.service cajeer-scheduler.timer`. Для upgrade сначала `systemctl stop cajeer-scheduler.timer cajeer-scheduler.service cajeer-queue-worker.service cajeer-connector-worker.service` и все другие writers. Supervisor restart не заменяет резервное копирование и recovery базы.

## Verification

`php tests/runtime-reliability-smoke.php` проверяет потерю receipt, корректную обработку payload, запрет reserved queue и реальное завершение зависшего дочернего процесса. `php scripts/verify-release.php` включает suite. Реальные `tests/infrastructure-postgres-integration.php`, `tests/infrastructure-redis-integration.php`, `tests/transfer-postgres-integration.php`, `tests/connector-actions-postgres-integration.php`, `tests/upgrade-postgres-integration.php` подключены к существующему CI и strict release verification. Их запуск требует disposable PostgreSQL 17/18, Redis и pdo_pgsql. Отдельно выполнить полный `php scripts/verify-release.php --strict` с live acceptance и браузером перед stable.
