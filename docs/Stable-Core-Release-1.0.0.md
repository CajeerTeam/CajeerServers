# Stable Core Release — 1.0.0

Версия 1.0.0 сохраняет функциональный scope 0.9.9 и все 64 SQL-миграции. Исходный канал `development` означает, что production-приёмка ещё не подтверждена. Статус `stable` присваивается **публикации неизменённого архива**, а не редактированием VERSION или уже принятого manifest. Команды ниже выполняют реальную сборку, проверку, распаковку, приёмку и публикацию. Наличие команд не означает, что указанные внешние проверки уже пройдены.

## Исправления runtime

- Local storage разрешён только в выделенной `storage/files` (или её дочернем каталоге) либо в отдельной абсолютной директории вне исходников. Проверяется фактический путь существующих предков, включая ещё не созданный каталог и переходы через ссылки. Нельзя использовать public, config, core, storage/app, родительский каталог релиза или путь, содержащий `..`. Перенесите старые данные из небезопасного расположения до обновления. Backup/restore и deployment gate используют эту же проверку. Не публикуйте storage через aliases Nginx/Apache.
- `maintenance:enable` закрывает admission, затем ожидает текущие операции. До отключения maintenance можно выполнить `release:check --strict --json`; несоответствие installation marker блокирует строгую проверку.
- Строгий deployment gate требует все 64 применённые миграции, собранный SDK JS + declarations, Composer vendor и Nuxt SSR. TypeScript compiler/node_modules не нужен на production-сервере.
- На этапе сборки безопасные внутренние ссылки в vendor, Nuxt output и SDK materialize в обычные файлы. Внешние, сломанные и циклические ссылки отклоняются. Проверка SSR выполняется после этой операции.

## Матрица целевой совместимости

PHP 8.4/8.5, PostgreSQL 17/18, Redis 7, Linux single-host deployment, Node 22 и точные npm/Composer из `.nvmrc` и `scripts/build-toolchain.sh`. SSH agents: Linux x86-64, glibc 2.34+, CPython 3.12. Chromium Playwright из reviewed lock. Провайдеры: существующие Proxmox VE/PBS, VMmanager 6 KVM/LXD, Debian/Ubuntu SSH, Windows WinRM HTTPS, SNMPv3 authPriv, RouterOS 7 HTTPS. Это целевая матрица до фактического прохождения CI/live acceptance. Не заявляется поддержка MySQL, HA, S3 full DR или произвольных сетевых вендоров.

## 1. Сборка

В чистом Git-checkout на доверенном build host с доступом к registry:

```sh
bash scripts/resolve-dependencies.sh   # если отсутствуют reviewed lockfiles
# Проверить и закоммитить composer.lock, admin/package-lock.json и SDK lock.
bash scripts/build-release.sh
python3 scripts/candidate-release.py seal
```

В сборке нет обхода lockfiles или dependency audit. Архив в `storage/tmp/candidate/` содержит tracked source, vendor, Nuxt SSR и SDK. Не содержит `.env`, данных инсталляции, ключей, Python virtualenv и build-time npm node_modules. В том числе содержит исходники тестов и Composer dev packages, необходимые существующему release pipeline; PHP document root строго `public/`. Подготовьте внешнее locked Python venv, системные компоненты и сервисы по инструкциям 0.9.1/0.9.7.

## 2. Развёртывание из точного архива на staging

Получите candidate ID (SHA-256 manifest) из доверенного вывода seal. Не доверяйте ID, полученному только из HTTP проверяемого сервера. Распакуйте архив в новый каталог; не используйте `tar` поверх работающей установки:

```sh
python3 scripts/release-distribution.py stage \
  --directory storage/tmp/candidate \
  --destination /opt/cajeer-releases/1.0.0-staging \
  --expected-id "$CANDIDATE_ID"
```

Проверяются SHA-256, SBOM, все содержимое и типы членов TAR, точный список файлов и фактическое содержимое после распаковки. Публикация каталога атомарна, повторное использование существующего target запрещено. Новый каталог приватный (0700); настройте ownership/traversal для сервисного пользователя: код/manifest должны быть read-only для него, `storage/` writable, `.env` приватный 0600. Код и данные не копируются из production автоматически. Настройте отдельную disposable БД и Redis, выполните Installer, инициализируйте Vault и trust profiles. Запустите PHP, Nuxt, scheduler, workers. Все пути сервиса и Nginx должны указывать на этот релиз, а не на checkout. Не допускайте сторонних файлов в дереве релиза кроме `.env` и runtime `storage/`.

```sh
python3 scripts/candidate-release.py verify-deployment \
  --root /opt/cajeer-releases/1.0.0-staging --expected-id "$CANDIDATE_ID"
```

## 3. Приёмка

На acceptance host необходимы build checkout с теми же исходниками и output, реальные PostgreSQL/Redis test services, отдельная установленная disposable staging инсталляция, её файловая система и возможность выполнить её PHP CLI от service user, все live-provider устройства, полный Playwright environment. Используйте настройки `docs/Stable-Core-Release-Candidate-0.9.9.md` и предыдущих release docs. `CAJEER_SMOKE_URL` и `E2E_BASE_URL` должны указывать на одно disposable staging приложение. Настройки runtime читаются из `.env` **развёрнутой инсталляции**; build/test DB credentials не передаются в её deployment check. Не запускайте acceptance на рабочей production БД.

```sh
python3 scripts/candidate-release.py accept \
  --manifest storage/tmp/candidate/.release-candidate.json \
  --deployment-root /opt/cajeer-releases/1.0.0-staging \
  --report /secure/acceptance-1.0.0.json
```

Проверяется exact deployment до и после тестов, отдельный production release gate, готовность API и sealed SSR, полный strict verification, браузерные тесты, реальные PostgreSQL/Redis, live connectors, восстановление и capacity profile: 100k assets, 1k targets, 1m samples, 100k events, 50 clients, P95 каждого сценария не выше 500 ms. Порог — цель roadmap, не подтверждённый benchmark. Встроенный 72-часовой HTTP soak проверяет `/api/v1/system`, `/api/v1/ready`, `/login`, candidate ID, TLS и отсутствие пропусков. Он не заменяет нагрузку collectors/Redis, полноценный 24h workload, RPO/RTO или проверку фактической доставки alerts. Нужен долговременный supervised host. При отказе/прерывании новый успешный отчёт не создаётся; ранее сформированный acceptance report удаляется, посторонний файл не перезаписывается. Одновременные прогоны на один report запрещены.

Отчёт format 2 связывает SHA-256 архива, commit, candidate ID, strict/deployment exit, capacity profile, duration и число probes. Он имеет `promotion_ready:false`: обязательны независимые review security, CI matrix (PHP/PG + Windows), production capacity/24h workload и реальный DR drill на отдельной инфраструктуре. Их фактические результаты необходимо сохранить и проверить отдельно. Не подписывайте тестовые fixtures или неполный отчёт.

## 4. Публикация stable без изменения принятого архива

Ответственный за релиз получает acceptance report, CI evidence и результаты независимой приёмки. После их проверки он составляет canonical JSON `stable-approval.json` (UTF-8, отсортированные ключи, compact separators, завершающий LF) с точными полями:

```json
{"acceptance_sha256":"<sha256 actual acceptance.json>","archive_sha256":"<sha256 actual archive>","candidate_id":"<trusted candidate id>","channel":"stable","format":1,"reviews":{"compatibility_matrix":"passed","disaster_recovery":"passed","production_capacity":"passed","security":"passed"},"version":"1.0.0"}
```

Это пример **формата**, не готовое доказательство. Hashes вычисляются по реально полученным файлам; все reviews должны быть фактически выполнены. Подписывайте утверждение только приватным ключом ответственного за выпуск, хранящимся вне build/staging. Например, Ed25519 через OpenSSL:

```sh
openssl pkeyutl -sign -inkey /secure/release-signing.key -rawin \
  -in /secure/stable-approval.json -out /secure/stable-approval.sig
```

Скопируйте actual acceptance report под именем `acceptance.json`, approval и signature в каталог исходного sealed candidate. Публичный ключ передайте по независимому доверенному каналу (не берите его из проверяемого архива).

```sh
python3 scripts/release-distribution.py publish \
  --directory storage/tmp/candidate \
  --destination /srv/releases/1.0.0 \
  --expected-id "$CANDIDATE_ID" --public-key /secure/trusted-release.pub
```

Команда проверяет формат/полноту отчёта, идентичность архива, 72h duration/probe count, точные reviews и подпись OpenSSL. Она публикует исходный TAR, checksum, manifest, SBOM и три файла доказательств в новый каталог, с повторной проверкой копий и fsync. Ни архив, ни исходный commit не изменяются. Это локальная stable distribution, не автоматическая отправка на GitHub; публикацию файлов в GitHub Releases выполняет ответственный оператор. Подпись удостоверяет решение доверенного reviewer, а не автоматически доказывает качество ручной проверки. Не храните секреты в отчётах или release artifacts.

Для установки принятой stable-сборки:

```sh
python3 scripts/release-distribution.py install \
  --directory /srv/releases/1.0.0 \
  --destination /opt/cajeer-releases/1.0.0 \
  --expected-id "$CANDIDATE_ID" --public-key /secure/trusted-release.pub
```

Без подписи и принятого отчёта доступна только команда `stage` для кандидата. Не называйте её результат сертифицированным stable deployment.

## 5. Upgrade 0.9.9 → 1.0.0

Остановите HTTP, Nuxt, scheduler, workers, syslog receiver и внешние writers. Сохраните полную прежнюю версию и ключи. Настройте новый release directory с прежними `.env`, storage и installation marker (сервисный пользователь, приватные permissions). Скопируйте согласованное состояние из старой установки; не делайте новый install поверх существующей БД. После настройки safe storage path:

```sh
php bin/cajeer maintenance:enable
php bin/cajeer upgrade:apply \
  --previous=/opt/cajeer-releases/0.9.9 \
  --archive=/secure/pre-upgrade-0.9.9.cjdr \
  --key-file=/secure/backup.key --offline
php bin/cajeer release:check --strict --json
php bin/cajeer inventory:integrity --json
php bin/cajeer maintenance:disable
php bin/cajeer health:ready
```

Все 64 миграции неизменны. Upgrade проверяет точную 0.9.9 source version/marker, backup и checksums, сохраняет старый completed journal, выполняет post-upgrade verification и только затем обновляет marker. Rollback — восстановление в отдельную БД и каталоги через existing `upgrade:restore`, без перезаписи исходной БД. Для более старых версий сначала выполните их поддерживаемую цепочку обновлений. Полные команды DR и production configuration: `Installation-Upgrade-Migration-0.9.2.md`, `Production-Operations-DR-0.9.7.md`.

## Статус исходников и support

В предоставленном 0.9.9 архиве отсутствовали Composer и Admin lockfiles, и сетевое разрешение не доступно в среде подготовки патча. Настоящие lockfiles нельзя заменить пустыми или вымышленными. В 1.0.0 SDK lock сохранён и проверен offline npm ci. Код и новые regressions реализованы; полная сборка и stable approval **не сформированы**. До реальной acceptance этот исходный ZIP — обновление к целевой версии 1.0.0, не принятый production distribution. После выпуска принятого stable bundle `/api/v1` сохраняется по SemVer; несовместимые изменения требуют следующей major версии, security fixes выпускаются patch-релизами. Не объявляется LTS срок или SLA без отдельно принятой политики поддержки.
