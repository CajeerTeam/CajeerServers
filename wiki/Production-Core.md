# CajeerServers 0.2.0 — Production-oriented Core

## Что представляет собой релиз

Это веб-приложение PHP 8.4+ и Nuxt, не ОС, не прошивка и не замена Proxmox/VMmanager. 0.2.0 стабилизирует существующие runtime, installer, session auth, RBAC/2FA, очередь, планировщик и веб-админку; **не** включает рабочее управление Proxmox, VMmanager, Windows/Linux или роутерами. Исходники коннекторов-заготовок не подключены к публичным операциям.

## Требования к живому развёртыванию

- PHP 8.4+ с расширениями `pdo_pgsql`, `json`, `pdo`, `openssl`, `sodium`; PostgreSQL 17+; Redis; Nginx + PHP-FPM; Node.js 22+ для Nuxt SSR.
- HTTPS, корректный `APP_URL`, уникальный сильный `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`.
- PostgreSQL и Redis доступны по loopback/приватной сети; для удалённой PostgreSQL требуется `DB_SSLMODE=verify-full` с CA, для удалённого Redis — TLS.
- `public/` — **единственный** веб-root PHP; `storage/`, `.env`, `vendor/`, `config/` и миграции не доступны из браузера.
- Не записывать production-пароли в shell history, GitFlic, CI logs или URL. Использовать секреты окружения и файлы с правами 0600.
- Очереди и scheduler запускаются отдельно под непривилегированным service user. Проверяйте retention, ресурсы, резервные копии БД и тренировку восстановления.

## Воспроизводимые зависимости

Сборочный контейнер этой итерации не смог достичь npm registry и не имел Composer. Поэтому **lock-файлы не сгенерированы искусственно**. На доверенном хосте с интернетом выполните:

```bash
bash scripts/resolve-dependencies.sh
```

Скрипт реально разрешает зависимости, создаёт `composer.lock` и `admin/package-lock.json`, устанавливает пакеты, запускает Nuxt typecheck/build и native suite. Перед выпуском просмотрите и сохраните оба lock-файла в основной репозиторий. При следующих сборках используйте только `composer install` и `npm ci`; никогда `npm update` в production.

## Проверки релиза

```bash
php bin/cajeer release:check --json
php scripts/verify-release.php

# После установки и настройки production/staging:
php bin/cajeer migrate:status
php bin/cajeer migrate:validate
php bin/cajeer doctor:deep
php bin/cajeer release:check --strict --json
```

`release:check` не заменяет настоящие integration/E2E. Он возвращает **ненулевой exit code** на несогласованную версию, недостающую зависимость, нарушение TLS или неготовую БД. Режим `--strict` также требует приватную `.env`, завершённую установку, соответствие `installation_id` локального marker и PostgreSQL, все применённые проверенные миграции (в 0.2.1 их восемь), работающие Redis/queue/scheduler/storage, `vendor/autoload.php`, lock-файлы и `admin/.output/server/index.mjs`.

### Изолированный PostgreSQL/Redis

Создайте две независимые тестовые PostgreSQL 17+ БД: `cajeer_test_core` и **пустую** `cajeer_test_installer_test`. Никогда не используйте боевую БД: интеграционный тест установщика создаёт начальную схему и администратора. Используйте изолированный Redis с тестовыми namespace. Затем:

```bash
export CAJEER_PG_TEST=1
export CAJEER_PG_TEST_DATABASE=cajeer_test_core
export CAJEER_INSTALL_PG_TEST_DATABASE=cajeer_test_installer_test
export CAJEER_PG_TEST_HOST=127.0.0.1
export CAJEER_PG_TEST_USERNAME=cajeer_test
# CAJEER_PG_TEST_PASSWORD должен поступать из защищённого CI secret
export CAJEER_REDIS_TEST=1
export CAJEER_REDIS_HOST=127.0.0.1
```

Запуск `php scripts/verify-release.php --strict` требует также реальную установленную staging-систему (`release:check --strict`), `CAJEER_SMOKE_URL=https://...`, `E2E_BASE_URL`, отдельный E2E admin и пароль из secret store. Если чего-либо нет, **скрипт не выдаст успешный результат**. Для CI отдельные jobs могут запускать PostgreSQL/Redis integration без полного production-gate.

### Remote HTTP smoke (без модификации состояния)

```bash
CAJEER_SMOKE_URL=https://staging.example.com php scripts/smoke-http.php
```

Проверяет `/api/v1/live` (200), `/ready` (200), `/admin/session` (401 для гостя), `/metrics` (401 при включённом scrape, 404 когда endpoint выключен), неизвестный путь (404), защитные заголовки, HTTPS/HSTS и отсутствие `Set-Cookie` на неавторизованных ответах. Разрешён локальный HTTP только на loopback. Если на staging readiness отвечает 503, тест падает — это реальный результат, а не ошибка скрипта.

## Обновление с 0.1.9

1. Остановите queue workers и scheduler, зафиксируйте версию и сделайте backup PostgreSQL + конфигурации (секреты шифровать отдельно). Проверьте возможность restore.
2. Примените **только изменённые файлы** патча поверх 0.1.9, предварительно сделав копию старого дерева. Не удаляйте `.env`, `storage/`, `vendor/`, пользовательские файлы.
3. Разрешите/установите проверенные зависимости, соберите Nuxt. Примените `php bin/cajeer migrate` и `migrate:validate` (в 0.2.0 нет новых SQL-миграций).
4. Проверьте Nginx, PHP-FPM, cookies, TLS, `/api/v1/live` и `/api/v1/ready`. Возобновите workers/scheduler после проверки готовности.
5. Выполните строгую проверку и браузерный E2E **на staging**, не на production; затем повторите production-gate и passive HTTP smoke в рабочем окружении.
6. В случае отказа остановите сервисы и восстановите **совместимую пару** исходников и PostgreSQL backup. Откат отдельных PHP-файлов поверх новой схемы без проверки совместимости недопустим.

## Известные ограничения

В текущей среде PHP native smoke и анализ конфигурации доступны; нет PDO PostgreSQL, PostgreSQL/Redis серверов и npm registry. Их реальную работоспособность, E2E и сохранность данных необходимо подтвердить в целевой инфраструктуре. Серверы и гипервизоры через будущие коннекторы в 0.2.0 ещё не управляются.

## Дополнение 0.2.1

Перед строгим release-gate для новой Inventory-модели требуется `inventory:preflight --strict` после миграции 0008 и выполнения `inventory:constraints --apply`. Операции и сохранность предыдущих данных описаны в [Inventory-Readiness.md](Inventory-Readiness.md).
