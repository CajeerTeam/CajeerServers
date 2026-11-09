# CajeerServers Admin UI — 0.1.9

## Архитектура

- PHP 8.4+/PostgreSQL 17+ — источник истины для учётных записей, RBAC, сессий, очередей и аудита.
- Nuxt 3 SSR — визуальный интерфейс. Браузер не хранит токены в localStorage, а cookie выставляет PHP.
- Nginx размещает UI (`/`) и API (`/api/v1/`) **на одном origin**. API не требует CORS, а проверка `Origin`, `SameSite` и CSRF продолжает работать.
- Во время SSR страницы не раскрывают персональные данные и не обращаются к привилегированным API. После загрузки клиент проверяет `/api/v1/admin/session` и переходит на `/login`, если сессии нет.
- Рабочие страницы: login, dashboard, diagnostics, tasks, scheduler, users, roles, audit, sessions, API tokens, password, TOTP MFA.
- Inventory, Proxmox, VMmanager, Scripts и Sync пока **не объявлены работающими**. Сведения об этом явно отображаются на старых страницах.

## Установка и запуск

1. Создайте резервную копию PostgreSQL и обновите PHP-проект до 0.1.9.
2. Выполните `php bin/cajeer migrate` (включая `0007_admin_ui_permissions.sql`).
3. Установите Node.js 20+ и Composer-зависимости в целевом окружении.
4. В `admin/` выполните `npm install`, `npm run build`. Отдельно зафиксируйте `package-lock.json` и включите его в релиз после успешной сборки.
5. Запустите Node SSR-процесс через systemd: `node admin/.output/server/index.mjs`, задайте `NITRO_HOST=127.0.0.1`, `NITRO_PORT=3000`, рабочую директорию проекта.
6. Используйте `examples/nginx/cajeerservers-tls.conf`, подставив DNS, TLS-сертификат, PHP-FPM сокет и root.
7. `APP_URL` должен точно совпадать с публичным адресом панели: `https://servers.example.com` (схема + хост + порт при необходимости).
8. Отдельно запустите queue worker и scheduler (`php bin/cajeer queue:work --max-seconds=3600`, `scheduler:run` раз в минуту) с системным supervision/cron. UI не выполняет задачи внутри PHP HTTP-запроса.
9. Проверьте `php bin/cajeer doctor:deep`, `php bin/cajeer migrate:validate`, `npm run test:static`, `npm run test:e2e` на **изолированной тестовой установке**.

### Тесты браузера

```bash
cd admin
npm install
npx playwright install chromium
E2E_BASE_URL=https://staging.example.com \
E2E_ADMIN_EMAIL=admin@example.com E2E_ADMIN_PASSWORD='test-only-secret' \
npm run test:e2e
```

Для аккаунта с включённой TOTP передайте `E2E_ADMIN_MFA` (актуальный код, не записывать в репозиторий). Если отдельный queue worker запущен, добавьте `E2E_EXPECT_WORKER=1`, чтобы проверить фактическое завершение задачи. E2E-тест добавляет диагностическую задачу, поэтому **не запускайте его в production**.

## Права версии 0.1.9

Миграция создаёт `admin.dashboard.read`, `admin.jobs.read`, `admin.jobs.execute`, `admin.scheduler.read` и назначает их существующей роли `administrator`. Другим ролям права автоматически не выдаются. Все admin API требуют серверной сессии, а запись — CSRF. Bearer-токены не дают административного доступа.

## Безопасность

- Привилегированная навигация скрывается по permissions, но реальные проверки всегда проводятся на PHP backend.
- `POST /admin/jobs` принимает только `runtime.diagnostics` или `storage.probe`, не принимает shell/PHP код.
- Результаты задач и ошибки workers не выгружаются целиком в браузер: UI получает только статусы и разрешённые атрибуты.
- Записи audit append-only. Доступ к журналу требует `security.audit.read`.
- Для установки используются проверяемые migrator и CLI; база и Redis должны быть доступны до начала проверок E2E.
- Если Node/NPM зависимости не устанавливаются, сборка Nuxt не может считаться проверенной. Запуск PHP smoke не заменяет browser E2E.
