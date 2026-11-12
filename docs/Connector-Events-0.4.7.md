# Connector Webhooks, Events & Signed Payloads — 0.4.7

## Обновление

Остановить connector workers, сделать проверенный backup и выполнить `php bin/cajeer migrate` (0027). Продолжить `php bin/cajeer connector:work 20` через supervisor/systemd или cron. Никаких новых библиотек не требуется. HTTPS в production обязателен. PostgreSQL — источник истины, Redis не используется для connector jobs.

## Создание источника

1. В существующем Workspace создать активный target, привязать Inventory asset и Vault credential. Проверить health и sync.
2. Через действующую админ-сессию получить одноразовый MFA Vault grant и выпустить service grant с `operation=sync` (см. Connector-Execution-0.4.2.md). Использовать только grant владельца текущей сессии; срок до 30 дней.
3. `POST /api/v1/connectors/targets/{id}/webhook-sources` с JSON `{"grant_id":"<uuid>"}`, cookie сессии и `X-CSRF-Token`. Ответ 201 содержит `data.id` (source UUID), `data.secret` (64 ASCII hex characters, использовать как строку, НЕ hex-decode) и `data.key_version`. Secret возвращается один раз; сохранить в secret store отправителя. Не записывать его в логи. List возвращает только metadata.
4. Endpoint отправителя: `POST /api/v1/connectors/webhooks/{sourceId}/events`. Протокол Cajeer v1, не vendor-specific webhook. Поддерживается произвольный источник, способный сформировать указанный HMAC; vendor adapter должен преобразовать native события.

## Протокол

JSON: `{"event_id":"evt-123","type":"resource.updated","resource_id":"qemu:101"}`. Типы `resource.created`, `resource.updated`, `resource.deleted` требуют resource_id; `inventory.refresh` допускает его отсутствие. ID — ASCII 1–128, resource_id — ASCII 1–180, дополнительные поля запрещены. Событие является подсказкой для полного авторитетного sync данного target, а не командой или снапшотом. Даже delete не удаляет Inventory; правила reconciliation 0.4.3 сохраняются. Позднее событие также читает актуальный API, поэтому не применяет старые значения. Поддержка адресного sync и native vendor webhook adapters отложена.

Подпись: `sha256=` + HMAC-SHA256(secret, `v1.<unix seconds>.<source UUID>\n<raw body>`). UUID lower-case; `\n` — один байт LF. `X-Cajeer-Timestamp` и `X-Cajeer-Signature` обязательны, дрейф ±300 s. Body до 16 KiB, application/json. Использовать доверенный TLS. Пример отправителя Python (stdlib):

```python
import os, json, time, hmac, hashlib, urllib.request
source = os.environ['CAJEER_WEBHOOK_SOURCE']
secret = os.environ['CAJEER_WEBHOOK_SECRET'].encode('ascii')
# Persist event_id AND exact body in sender outbox before first send.
body = json.dumps({'event_id':'evt-123','type':'resource.updated',
                   'resource_id':'qemu:101'}, separators=(',', ':')).encode()
ts = str(int(time.time()))
signature = 'sha256=' + hmac.new(secret, b'v1.' + ts.encode() + b'.' +
                               source.encode() + b'\n' + body, hashlib.sha256).hexdigest()
req = urllib.request.Request(os.environ['CAJEER_BASE_URL'].rstrip('/') +
    '/api/v1/connectors/webhooks/' + source + '/events', data=body,
    headers={'Content-Type':'application/json','X-Cajeer-Timestamp':ts,
             'X-Cajeer-Signature':signature}, method='POST')
# For production, configure CA trust and retry temporary errors with same body and new timestamp.
with urllib.request.urlopen(req, timeout=15) as response:
    print(response.read().decode())
```

202 means inbox and queue run are durably committed, not that remote sync completed. Re-sign exact same bytes and event_id after timeout; returns same event ID and latest run_id with deduplicated=true. Different bytes under same ID return 409, even if JSON is semantically equivalent. 401 invalid signature or revoked source; 403 expired/revoked grant; 429 rate limit (120/min per source) or queue backpressure (10 pending runs per grant), retry with backoff and jitter. 4xx configuration errors require operator intervention. Unknown sources do not create rate-limit records. Configure ingress global/IP rate and body limits independently.

## Execution, monitoring, redrive

Each accepted event and queue job committed together in PG. Queue payload empty; worker uses registered provider's `sync` operation with existing lease/fencing and live Vault grant checks. A source revoke also fences previously accepted runs before secret retrieval and before commit. A network request already in progress cannot be unsent. Remote errors follow existing circuit breaker/retry. No second event dispatcher process required; enqueue occurs inside reception transaction and worker performs remote operations asynchronously.

`GET /api/v1/connectors/targets/{id}/events?limit=50&offset=0` returns normalized metadata, latest run, status (queued/running/succeeded/dead_letter/cancelled) and dispatch history. `GET /api/v1/connectors/targets/{id}/runs/{runId}` gives actual run status/result/error. `POST /api/v1/connectors/targets/{id}/events/{eventId}/redrive` with `{"grant_id":"<uuid>"}` and CSRF: only failed/cancelled events, same target, live owned sync grant, active source, max 10 dispatches. Successful/pending events cannot redrive. Rotation does not discard accepted events; revoke is final and disallows redrive. If grant expired, issue another, create new source for new incoming events, redrive old events explicitly with new grant (if old source not revoked).

`POST /api/v1/connectors/targets/{id}/webhook-sources/{sourceId}/rotate` returns new secret once, increments version, old key immediately rejected (no overlap). `DELETE .../webhook-sources/{sourceId}` revokes. Key envelopes are bound to source UUID/workspace/type and rewrapped by offline Vault master-key rotation. Backups include new PostgreSQL tables. No raw body, signature, plaintext secret or caller-supplied credentials stored in inbox/queue. Metadata IDs may be sensitive: do not put secrets in IDs. Event IDs and dispatch history retained indefinitely in this release for replay protection; provision DB storage, and define a future explicit retention/archive policy before high-volume deployments.

## Verification

`php tests/connector-events-smoke.php` checks signing, timestamp, scope binding, input limits, encryption, DI and HTTP route authentication. `php tests/connector-events-postgres-integration.php` requires disposable `cajeer_test_*` database and `CAJEER_PG_TEST=1` with connection env (same as other integration tests). It exercises migrations, MFA grant, signed inbox, actual worker/reconciliation with deterministic SNMP session fixture, duplicates, cancellation/redrive, key rotation/revoke and master-key rewrap. Included in strict verification and CI. Run `php scripts/verify-release.php` and staging integration gates before deployment. Admin UI for sources/events deferred to 0.4.9.
