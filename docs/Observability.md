# Observability & Operational CLI — CajeerServers 0.1.8

## Возможности

В версии 0.1.8 используются исполняемые проверки PHP runtime, установленности приложения, PostgreSQL, Redis, выбранного queue backend, PostgreSQL scheduler и local/S3 storage. Ошибки не раскрывают DSN, host, user credentials или body запросов. Неизвестные или недоступные зависимости помечаются **fail**, а не фиктивным успехом.

- `GET /api/v1/health` — старый совместимый runtime-only endpoint (не является проверкой БД).
- `GET /api/v1/live` — liveness процесса (`200`, без внешних подключений).
- `GET /api/v1/ready` — aggregate readiness (`200` либо `503`), без детальных ошибок и конфигурации.
- `GET /api/v1/metrics` — Prometheus v0.0.4, *отключён по умолчанию*, только `Authorization: Bearer <PROMETHEUS_SCRAPE_TOKEN>`.

В `/ready` local storage проверяется доступностью каталога, S3 — фактическим PUT/GET/DELETE во временном объекте. При частом опросе удалённого S3 учитывайте дополнительные API-запросы и стоимость. Глубокую проверку local storage с записью, чтением и удалением выполняет `doctor:deep`. Liveness не зависит от БД — это важно для корректного различения сбоя самого PHP и зависимостей.

## Метрики

Собираются реальные счётчики HTTP-запросов по методу и классу ответа, накопленная длительность и histogram buckets, результаты выполненных queue handler attempts и значения `cajeer_dependency_up` для проверок зависимостей. В метках **нет** URL, ID пользователя, IP, пути, имени очереди, токенов или job payload: ограниченная кардинальность исключает неконтролируемый рост рядов.

HTTP/queue counters записываются в `storage/cache/observability-metrics.json` под эксклюзивной файловой блокировкой; файл создаётся с правами `0600`. Это **per-instance** метрики, не глобальная агрегация для нескольких серверов приложения. При нескольких инстансах Prometheus должен опрашивать каждый отдельно; не размещайте счётчики на NFS без гарантий корректного flock. Для переноса метрик между разными нодами потребовалось бы отдельное time-series хранилище.

Конфигурация:

```dotenv
PROMETHEUS_ENABLED=true
PROMETHEUS_SCRAPE_TOKEN=replace-with-at-least-32-random-characters
OTEL_ENABLED=true
OTEL_SAMPLE_RATIO=0.1
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318
```

Рекомендуется генерировать scrape token: `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` — сохранить вне VCS. Не использовать пользовательские API-токены, секреты Proxmox, или пароли БД. В production scrape разрешён только по HTTPS (на Nginx передайте `HTTPS on` в PHP-FPM). Ограничьте доступ к endpoint сетевыми ACL вдобавок к bearer token. Не логируйте заголовок Authorization.

Экспорт OpenTelemetry — **opt-in**, отправляет реальный OTLP/HTTP JSON в `/v1/traces`. Поддерживается HTTPS и HTTP для loopback endpoint. Ограниченный timeout отправки — 350 мс; ошибки экспорта не ломают HTTP и worker. Экспортируются только типизированные поля `http.request.method`, `http.response.status_code`, `messaging.operation.name`, тип разрешённой задачи и статус. Не передаются URL, IP, запрос/ответ, cookies и секреты. При выключенном OTEL сетевых обращений нет. Для высокой нагрузки рекомендуем локальный OpenTelemetry Collector (на loopback).

## CLI

```bash
php bin/cajeer health:live
php bin/cajeer health:ready
php bin/cajeer doctor:deep --json
php bin/cajeer system:report --deep
php bin/cajeer metrics:render
php bin/cajeer queue:stats --queue=default
php bin/cajeer scheduler:stats
php bin/cajeer openapi:lint
php bin/cajeer security:prune --days=30 --limit=500          # только кандидаты
php bin/cajeer security:prune --days=30 --limit=500 --apply  # настоящая очистка
```

`health:ready`, `doctor:deep`, `system:report` возвращают exit code 1 при неисправности хотя бы одной обязательной зависимости, а не fake success. `openapi:lint` требует `symfony/yaml` (`composer install`) либо ext-yaml: в их отсутствие **exit code 3**, а не ложный `valid`. Для PostgreSQL queue/scheduler и security retention требуются настоящие таблицы миграций 0.1.4–0.1.7.

`security:prune` ограничен максимум 5000 строк **на таблицу за один запуск**; применённый режим удаляет устаревшие записи rate limits, login attempts, сессий и старые webhook inbox events. `audit_log` никогда не изменяется. Перед запуском в production выполняйте backup и dry-run. Программа не выполняет массовую чистку в HTTP-запросе.

## Деплой и проверки

Запускайте с Nginx/PHP-FPM по HTTPS; DB и Redis должны иметь настроенные timeout. Проверки глубокого storage создают и удаляют временные объекты, без затрагивания пользовательских данных. Установите `Prometheus` scrape interval 30–60s и алерты по `cajeer_dependency_up==0` / доступности цели. Правила-образцы: `examples/prometheus/cajeerservers-alerts.yml`; доставку уведомлений выполняет Prometheus/Alertmanager, не встроенный CajeerServers.

Нативные тесты: `php tests/observability-smoke.php`, затем прежние smoke-наборы. Для production-развёртывания необходимы отдельные проверки на PostgreSQL 17/18, Redis и выбранном S3 endpoint, которые не могут быть заменены моками.
