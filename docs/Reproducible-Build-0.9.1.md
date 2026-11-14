# 0.9.1 — Reproducible Build & Dependency Stability

## Статус

Это рабочая инфраструктура locked build; релиз остаётся `development`. В исходном 0.9.0 отсутствовали три lockfile. SDK `package-lock.json` в 0.9.1 сгенерирован npm из кеша настоящего TypeScript 5.8.3; чистый `npm ci --offline`, `tsc` и TLS SDK integration выполнены. Composer и Admin lock **не приложены**: Composer/сетевые registry недоступны в среде подготовки. Без них полный release build намеренно завершится ошибкой. Ни локальная полная сборка Nuxt, ни PHPUnit с Composer, ни онлайн-аудит, ни запуск GitHub Actions не подтверждены.

## Поддерживаемая сборочная среда

Linux x86-64 glibc ≥ 2.34 (CI Ubuntu 24.04), PHP 8.4/8.5 с DOM/XML/XMLWriter/mbstring/PDO/sodium/openssl и рабочими драйверами PostgreSQL/SNMP, Node **22.23.5** (`.nvmrc`, включает npm **10.9.9**), Composer **2.11.2**, CPython 3.12 с `venv`, GNU tar/gzip/flock/git/sha256sum. PHP solver platform 8.4.0 не отменяет проверку реального PHP через `composer check-platform-reqs`. PHP и Python minor branches получают patch-обновления в CI; фактические версии записываются в отчёт. Проверяйте toolchain при security updates. Источники версий: https://nodejs.org/dist/index.json и https://getcomposer.org/download/.

## Получить недостающие lockfiles

В доверенной сетевой среде с указанным toolchain:

```bash
bash scripts/resolve-dependencies.sh
# проверить diff и audit, затем зафиксировать все три файла
git add composer.lock admin/package-lock.json packages/ts-sdk/package-lock.json
git commit -m 'Resolve reviewed release dependencies'
```

Резолвер работает в пустом временном каталоге без `node_modules`; не исполняет lifecycle scripts и не заменяет lockfiles до успешного разрешения всех деревьев и audit. Обновление Composer намеренно может выбрать новые совместимые версии; npm сохраняет существующие совместимые записи. Не запускать resolver на production. Ручной GitHub Actions workflow **Maintainer dependency lock resolution** выполняет эти же операции на чистом runner, отдаёт `candidate-dependency-locks` для ревью и проверяет сборку на локальном CI-only commit без push. После проверки закоммитьте скачанные lockfiles в свой репозиторий. Если workflow падает, его артефакт lockfiles не означает прошедшую сборку.

## Сборка и проверка

```bash
bash scripts/build-release.sh
# проверка контрольной суммы архива (из корня проекта)
grep '  storage/tmp/release-build.tar.gz$' storage/tmp/release-build.sha256 | sha256sum -c -
```

Скрипт требует чистый committed checkout (архив без .git помечается `source_commit=unavailable`); не разрешает зависимости, блокирует неверные или отсутствующие locks, проверяет registry integrity, выполняет Composer/npm audit и проверку Python lock через PyPI vulnerability metadata, создаёт временный hash-locked Python venv, очищает старые результаты, выполняет `composer install`, `npm ci`, platform check, SDK build, Nuxt typecheck/build, production SSR HTTP smoke из отдельной копии выходного каталога, SDK HTTPS integration, два независимых SDK builds с сравнением SHA-256, PHPUnit и native release verification. Фиксирует SHA-256 входов и выходов, фактические версии инструментов и commit. `storage/tmp/release-build.tar.gz` содержит только `admin/.output` и `packages/ts-sdk/dist`; это не полный deployment bundle. Архив нормализует владельца/mtime/порядок, но не заявляет bit-for-bit идентичность Nuxt между платформами. Старый отчёт и архив удаляются при старте новой сборки; успешно проверенный результат публикуется только после всех проверок. Строгая live/staging приёмка остаётся отдельной (`php scripts/verify-release.php --strict`).

## SSH-агенты

Для Linux x86-64 / glibc ≥ 2.34 / CPython 3.12:

```bash
python3.12 -m venv /opt/cajeer-connectors/venv
bash scripts/install-agent-dependencies.sh /opt/cajeer-connectors/venv/bin/python
```

Файл `core/Connector/agents/requirements-linux-py312.lock` содержит семь pinned wheels с опубликованными PyPI SHA-256: paramiko, bcrypt, cryptography, invoke, PyNaCl, cffi, pycparser. Источники: https://pypi.org/project/paramiko/5.0.1/, https://pypi.org/project/bcrypt/5.0.0/, https://pypi.org/project/cryptography/50.0.2/, https://pypi.org/project/invoke/2.2.1/, https://pypi.org/project/PyNaCl/1.6.2/, https://pypi.org/project/cffi/2.0.0/, https://pypi.org/project/pycparser/2.23/. Pip проверяет хеши, запрещает sdists, разрешает зависимости только в рамках lock, затем выполняется `pip check` и imports. Установка этих wheels и SSH fixture под этим окружением должны пройти в CI; локально доступен только Python 3.13, загрузка wheels невозможна. Исходный requirements.txt сохранён для других платформ, но не является locked production profile.

## Проверки и ограничения

- `node --test tests/dependency-locks.test.mjs` — реальный SDK lock и отрицательные случаи (drift, отсутствующий lock, symlink, отсутствующий hash, недоверенный registry).
- `npm ci --prefix packages/ts-sdk --offline` — при наличии корректного npm-кеша; затем `npm run --prefix packages/ts-sdk build` и `bash tests/connector-sdk-integration.sh`.
- `bash tests/sdk-reproducible-build.sh` — две чистые установки и сравнение полного `dist` (локально пройдено с настоящим npm-кешем).
- `node --test tests/admin-build-smoke.test.mjs` — локальный HTTP harness, положительный и отрицательные сценарии; не подменяет запуск Nuxt.
- `python3 tests/python-lock-audit-test.py` — проверка настоящего lock и отрицательные случаи metadata/audit.
- `php scripts/verify-release.php` — native regression; не заменяет полный Composer/Nuxt build.
- PostgreSQL 17/18, Redis, Windows native и browser CI сохранены. GitHub Actions и онлайн-аудиты требуют фактического выполнения на сетевом runner.
- Новых миграций нет. До фиксации Composer/Admin locks и успешных CI gates этап Reproducible Build **не закрыт**. Канал stable не выставлен.
