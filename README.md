# CajeerServers

**CajeerServers** — русскоязычная self-hosted веб-оболочка для наблюдения и управления инфраструктурой. Она **не является ОС**, не заменяет Proxmox/VMmanager и не требует установки обязательного системного агента на управляемые устройства. Запланированы интеграции через API, собственные скрипты, webhooks, collectors, exporters и прочие источники данных.

## Версия 0.1.1 — Application Bootstrap & Runtime

Это **исполняемое ядро запуска**, не готовая панель управления серверами. Реализованы:

- единый bootstrap для CLI, HTTP, тестов и будущих workers;
- процессное окружение + строгая загрузка `.env` (поддерживаются комментарии, кавычки, escape-последовательности, типизированное чтение), значения ОС имеют приоритет;
- ленивое кешируемое чтение конфигурации по вложенным ключам;
- DI-контейнер (instances, singleton, transient, aliases, constructor autowiring, обнаружение циклов);
- жизненный цикл `boot → handle → terminate`;
- структурированный JSONL-логгер с редактированием секретов и обработкой ошибок без раскрытия деталей в production;
- рабочие диагностика runtime, version/about, конфигурационные команды, self-test, HTTP-корень с 404/405/503;
- GitFlic CI и автоматические smoke-тесты.

**В 0.1.1 ещё не реализованы:** HTTP Router/API `/api/v1`, мигратор и БД, установка, Auth/RBAC, Proxmox, VMmanager, очереди, webhook-интерфейсы, сбор данных и Nuxt-интеграция. Команды, относящиеся к будущим релизам, завершаются с кодом `3` и не имитируют выполнение. Имеющийся OpenAPI-файл описывает будущий контракт, **а не список уже работающих endpoints**.

## Требования

- PHP **8.4+** (целевой PHP 8.5), `ext-json`, `ext-pdo`, `ext-openssl`, `ext-sodium`.
- Nginx + PHP-FPM; Apache — необязательная альтернатива.
- Composer 2 для установки Symfony Components и других зависимостей. Встроенный runtime и CLI-диагностика запускаются без vendor; сторонние компоненты становятся доступны после `composer install`.
- Доступная на запись директория `storage/logs`, а также `storage/cache` и `storage/tmp`.

## Быстрый старт (локальная разработка)

```bash
cp .env.example .env
composer install --no-interaction
php bin/cajeer doctor
php bin/cajeer self:test
php tests/runtime-smoke.php
php -S 127.0.0.1:8080 -t public
```

`composer install` требует доступа к репозиториям пакетов, поэтому для локального smoke-тестирования без Composer можно пропустить только эту строку. HTTP-запрос `GET /` возвращает имя и версию, `GET /несуществующий-путь` возвращает 404. Полная маршрутизация будет в 0.1.2.

### Настройка production

1. `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://example.org`.
2. Сгенерируйте `APP_KEY`: `php -r 'echo "base64:", base64_encode(random_bytes(32)), PHP_EOL;'` и поместите результат в `.env` / менеджер секретов.
3. Файл `.env` не должен находиться в web root, `public/` — **единственный** DocumentRoot. Не передавайте `.env` в публичные файлы или Git.
4. Настройте права доступа PHP-FPM к `storage/{logs,cache,tmp}` и доступ только веб-пользователя к секретам.
5. Выполните `php bin/cajeer doctor --json` и проверьте `ok: true` **до запуска HTTP**.

Production-конфигурация с небезопасным ключом, включённым debug или не-HTTPS `APP_URL` возвращает HTTP **503**, а `doctor` завершается с кодом **1**. Это проверка prerequisites runtime, **не** проверка готовности PostgreSQL, Redis или соединений с гипервизорами.

## CLI

```bash
php bin/cajeer help
php bin/cajeer version
php bin/cajeer about
php bin/cajeer config:get app.name
php bin/cajeer config:check
php bin/cajeer doctor --json
php bin/cajeer self:test
```

Коды завершения: `0` — успешно; `1` — ошибка runtime/diagnostics; `2` — неверная команда/аргументы; `3` — функциональность ещё не реализована. `config:get` запрещает доступ к секретным ключам и скрывает чувствительные поля в вложенных массивах.

`.env` загружается один раз при запуске процесса. Файлы до 1 МиБ, кодировка UTF-8; многосрочные значения и `${VAR}`-подстановка в 0.1.1 не поддерживаются. Значения процесса имеют приоритет; значения `.env` не публикуются через API.

## Тестирование

```bash
APP_ENV=local APP_DEBUG=false php tests/runtime-smoke.php
APP_ENV=local APP_DEBUG=false php bin/cajeer self:test
# после composer install:
APP_ENV=local APP_DEBUG=false vendor/bin/phpunit
```

Доступные native smoke-тесты не требуют Composer. GitFlic CI выполняет smoke-тесты на PHP 8.4 и PHP 8.5, Composer-установку и PHPUnit. На целевых production-хостах зависимости следует **зафиксировать в `composer.lock`** после разрешения Composer и проверить готовый lock-file в CI. Этот архив не содержит сгенерированный непроверенный lock-file.

## Структура

- `core/` — PHP runtime, конфигурация, DI, logging, CLI.
- `public/index.php` — безопасная HTTP-точка входа.
- `bin/cajeer` — единая точка входа CLI.
- `config/` — конфигурация.
- `admin/` — будущий Nuxt UI.
- `api/` — планируемый OpenAPI-контракт.
- `modules/`, `packages/`, `wiki/` — будущая экосистема и документация.

Следующая версия **0.1.2 — HTTP Kernel & REST API**: Symfony HttpFoundation, роутинг, middleware, JSON-валидация и контракты ответов.
