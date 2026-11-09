# Scheduler — CajeerServers 0.1.7

Планировщик хранит задания в **PostgreSQL**, независимо от выбранного драйвера очереди (`QUEUE_CONNECTION=redis` или `pgsql`). Миграция `0006_queue_scheduler.sql` создаёт `scheduler_tasks` и `scheduler_outbox`.

## Использование

```bash
php bin/cajeer scheduler:add --name=diagnostic-every-15m \
  --cron='*/15 * * * *' --timezone=Europe/Amsterdam \
  --type=runtime.diagnostics --queue=default
php bin/cajeer scheduler:add --name=retention-every-night \
  --cron='0 3 * * *' --timezone=UTC --type=queue.prune \
  --payload-file=/secure/queue-retention.json
php bin/cajeer scheduler:list
php bin/cajeer scheduler:disable --name=diagnostic-every-15m
php bin/cajeer scheduler:enable --name=diagnostic-every-15m
php bin/cajeer scheduler:run
```

Пример `/secure/queue-retention.json`: `{"queue":"default","days":7}`.

В ОС размещается одна запись обычного cron `* * * * * <php-binary> /path/to/bin/cajeer scheduler:run` (или systemd timer); это **не делает CajeerServers операционной системой**. Защита от конкурентного запуска: PostgreSQL row-level locks с `SKIP LOCKED`, unique `(task_id,due_at)`. Задания due атомарно перемещаются в outbox и затем отправляются в Redis/PostgreSQL queue с постоянным UUID. При сбое после отправки, но до записи успеха dispatcher повторяет отправку безопасно с тем же UUID — драйверы дедуплицируют ID.

Cron из **5 полей**, поддержаны `*`, диапазоны, списки, шаги и часовые пояса IANA; правило для DOM/DOW при одновременном ограничении — OR (традиционный cron). В DST-пропусках несуществующее местное время пропускается. Пропущенные запускаемые периоды обрабатываются постепенно по `SCHEDULER_BATCH` (по умолчанию 25); следите за backlog. `scheduler:run` возвращает ненулевой exit code при ошибке outbox dispatch. Удаление зарегистрированного задания через CLI пока не предусмотрено — оно выполняется управляемой SQL-операцией.

**Важно:** сначала закончите installer, примените миграцию и запустите queue workers. Статус scheduler — это публикация задания, а не подтверждение выполнения; фактический результат находится в очереди.
