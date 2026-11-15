# Stable Core Acceptance — 1.0.1

Patch сохраняет API v1 и все 64 PostgreSQL миграции. Исходный канал `development` не является подтверждением production acceptance. Используйте инструкции сборки, staging, capacity, DR и независимого review из [1.0.0](Stable-Core-Release-1.0.0.md), но с новым номером и candidate ID. Старые approval/evidence для 1.0.0 не подходят к 1.0.1.

## Что выполняется

- `release:check --strict` проверяет настоящий write/read/delete в storage, включая отсутствие объекта после DELETE; проверяет Vault через фактическое шифрование, INSERT/read/decrypt и rollback. Запускайте от пользователя PHP-сервиса с его `.env`.
- `scripts/verify-release.php --strict --deployment-root=...` создаёт отдельную пустую тестовую PostgreSQL БД для каждого сценария, удаляет её после выполнения. Требуется `CREATEDB` на отдельном тестовом PostgreSQL (не на production), `CAJEER_PG_TEST=1` и `CAJEER_PG_TEST_DATABASE=cajeer_test_*`. Отдельная пустая installer DB по-прежнему обязательна. После принудительного SIGKILL проверьте оставшиеся `cajeer_test_ci_*` вручную.
- `candidate-release.py accept` получает результаты всех strict шагов в приватном временном файле; при любом сбое, skip (ненулевой exit), отсутствующем обязательном сценарии или несовпадении candidate ID отчёт не создаётся. Формат 3 содержит `strict_verification` (точный набор из 166 шагов: имена, exit, длительность) плюс прежние hash/commit/capacity/soak. Publication проверяет формат и подпись, не принимает format 2 для 1.0.1.
- HTTP soak: без redirect, запросы no-cache, отказ при ненулевом Age, точный media type, ограничение размера и времени. Deployment проверяется до тестов, после strict тестов и по завершении 72 часов. Подписанная публикация сохраняет точные байты архива.

## Порядок upgrade

На отдельном release directory 1.0.1, после остановки всех writers и переноса согласованной конфигурации/данных/marker из 1.0.0:

```sh
php bin/cajeer maintenance:enable
php bin/cajeer upgrade:apply --previous=/opt/cajeer-releases/1.0.0 \
  --archive=/secure/pre-upgrade-1.0.0.cjdr --key-file=/secure/backup.key --offline
php bin/cajeer release:check --strict --json
php bin/cajeer inventory:integrity --json
php bin/cajeer maintenance:disable
php bin/cajeer health:ready
```

До upgrade сохраните исходный релиз, архив, ключи, идентичность БД и storage. Upgrade проверяет точный predecessor и миграционные checksums. При ошибке не отключайте maintenance; повторите с теми же аргументами после исправления или выполните изолированный recovery по исходному runbook.

## Приёмка и публикация

```sh
bash scripts/build-release.sh
python3 scripts/candidate-release.py seal
python3 scripts/release-distribution.py stage --directory storage/tmp/candidate \
  --destination /opt/cajeer-releases/1.0.1-staging --expected-id "$CANDIDATE_ID"
# Настройте disposable staging, .env, БД, Redis, Vault, live devices, browser и тестовый PostgreSQL.
python3 scripts/candidate-release.py accept --manifest storage/tmp/candidate/.release-candidate.json \
  --deployment-root /opt/cajeer-releases/1.0.1-staging --report /secure/acceptance-1.0.1.json
```

По результатам реальной приёмки и независимых security/compatibility/capacity/DR review подпишите canonical `stable-approval.json` (version `1.0.1`, acceptance hash нового отчёта) отдельным ключом. Передайте `acceptance.json`, `stable-approval.json`, `stable-approval.sig` в каталог sealed candidate. Выполните `release-distribution.py publish` и `install` как в 1.0.0 с новыми destination и ID. Не выдавайте тестовые данные, старый отчёт или успешные локальные smoke за production approval.
