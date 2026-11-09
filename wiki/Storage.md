# Storage — CajeerServers 0.1.7

Рабочие адаптеры `LocalDisk` и `S3CompatibleDisk`. Максимальный размер отдельного объекта при `put/get` — **16 MiB**. Большие объекты, multipart, streaming upload и range requests пока не поддерживаются.

## Local storage

```dotenv
STORAGE_DISK=local
# Значение пустое => storage/files вне public/.
STORAGE_LOCAL_ROOT=
```

`put/get/exists/delete` обрабатывают относительные ключи вроде `backups/metadata.json`, запрещая абсолютные пути, `..`, `.` и symlinks. Запись сначала выполняется во временный файл с `0600`, затем атомарно заменяется через `rename`. Права каталога должны принадлежать непривилегированному runtime-пользователю. Storage, cache и log folders нельзя публиковать через Nginx alias.

## S3-compatible

```dotenv
STORAGE_DISK=s3
S3_ENDPOINT=https://s3.example.net
S3_BUCKET=cajeer-files
S3_REGION=us-east-1
S3_ACCESS_KEY=<private-id>
S3_SECRET_KEY=<private-secret>
S3_SESSION_TOKEN=
S3_TIMEOUT=10
```

Реализован AWS Signature V4 с SHA-256 payload и подписанными запросами GET/PUT/HEAD/DELETE (path-style endpoint), TLS peer verification, отказ от небезопасного HTTP. Для локального тестирования MinIO на `127.0.0.1` можно включить `S3_ALLOW_LOCAL_HTTP=true`; внешние HTTP endpoint запрещены даже с этим параметром. На production используйте HTTPS и credentials с разрешениями на конкретный bucket/prefix. Не помещайте секреты в CLI или логи.

## CLI и проверка

```bash
php bin/cajeer storage:check  # реальный probe put/head/get/delete
php bin/cajeer storage:put --key=reports/diagnostic.txt --file=/tmp/diagnostic.txt
php bin/cajeer storage:exists --key=reports/diagnostic.txt
php bin/cajeer storage:get --key=reports/diagnostic.txt --output=/tmp/diagnostic-download.txt
php bin/cajeer storage:delete --key=reports/diagnostic.txt
php tests/infrastructure-smoke.php
```

`storage:get` не перезаписывает существующий файл. `S3 DELETE` по спецификации идемпотентен и возвращает успех даже для отсутствующего объекта (в отличие от `LocalDisk`). Mock S3 в тестах проверяет реальную SigV4 подпись, но не является заменой испытаниям на конкретном S3-compatible провайдере.
