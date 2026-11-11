# CajeerServers 0.3.9 — Credentials Vault Admin UI & E2E

## Исполняемый контур

`/vault` — реальная Nuxt UI-страница поверх PHP REST и PostgreSQL Vault из 0.3.2–0.3.5 (не локальная демонстрация, не mock и не endpoint без операции). При наличии `vault.credentials.read` и `workspaces.read` после входа показываются доступные пользователю Workspace и записи без секретов. Для workspace-role `owner` или `manager` дополнительные `vault.credentials.create/update/delete` открывают соответствующие формы; проверки на сервере выполняются независимо от отображения кнопок. Значения секрета никогда не читаются повторно.

Операции:

1. Выбор Workspace, server-backed список с безопасной пагинацией, выбор credential; ETag берётся из свежего `GET /api/v1/vault/credentials/{id}`.
2. Создание с использованием разрешённых профилей, полученных из `GET /api/v1/vault/profiles`. Поддерживаются все 9 типов: `generic_secret`, `api_token`, `proxmox_api`, `vmmanager_api`, `ssh_password`, `ssh_private_key`, `winrm`, `snmp_v2c`, `snmp_v3`. Поля profile payload зашифровываются на сервере.
3. Редактирование имени/описания и переключение `active`/`disabled`. Всякая запись отправляет `If-Match`; серверный конфликт 412 НЕ повторяется без подтверждения — UI загружает актуальную версию.
4. Прямая замена секрета по `PUT .../secret` после дополнительного подтверждения; либо двухфазная ротация `POST .../rotation`, просмотр *только метаданных версий*, явное подтверждение внешней проверки и `POST .../activate`, либо отмена через `POST .../cancel`.
5. Архивирование по `DELETE` с проверкой `If-Match`. Активные bindings блокируют удаление на сервере; отсоединяйте их в `/connectors` и `/transfer`.

Каждая запись использует сессионный `api.write`, включает CSRF-токен и `no-store`; статус и история никогда не включают plaintext/ciphertext. Формы не используют `localStorage`, `sessionStorage`, IndexedDB, URL-query или clipboard для секретных значений. Поля очищаются по завершении запроса, смене записи/Workspace/профиля и при размонтировании страницы. **Важное ограничение:** браузер всё равно содержит введённый секрет в памяти/поле до отправки, а браузерные DevTools и менеджер паролей могут получить к нему доступ; UI не может обеспечить гарантированное физическое стирание JS-строк из памяти. Приватные ключи вводятся в многострочное поле; экран/запись сессии во время ввода нужно защищать. Расширения браузера могут читать содержимое полей.

Использование новых секретов внешними коннекторами по-прежнему требует полноценного workspace + Inventory binding, `vault.credentials.use`, MFA step-up и существующего серверного `SecretAccessService`. Vault UI не раскрывает grant или секрет в браузере.

## Локальные независимые проверки

```bash
node admin/tests/static-check.mjs
node --experimental-strip-types admin/tests/vault-ui-contract.mjs
php scripts/verify-release.php
```

Это native проверки кода и контракта клиентской логики; **не** полноценная Nuxt сборка и **не** браузерная интеграция.

## Настоящий Playwright E2E на disposable staging

Требования: установленный PHP 8.4+/PostgreSQL 17+ с применёнными миграциями, работающий Vault master key (проверка через `php bin/cajeer vault:check`), включённые cookie-сессии/CSRF, работающий Nuxt Admin и API на одном origin (HTTPS вне loopback), отдельная тестовая БД, администратор с global Vault CRUD и owner/manager Workspace. Тесты создают и архивируют credentials с синтетическими значениями, никакие production credentials не применяются. Существующие bindings этих тестовых credentials не создаются.

```bash
cd admin
npm ci
npx playwright install chromium
export E2E_BASE_URL='https://staging.example.invalid'
export E2E_ADMIN_EMAIL='e2e-admin@example.invalid'
export E2E_ADMIN_PASSWORD='set-in-secret-store-not-shell-history'
export E2E_ISOLATED_STAGING=1
export E2E_ALLOW_WRITES=1
export E2E_VAULT_UI=1
# E2E_ADMIN_MFA при необходимости
npm run test:e2e:preflight
npx playwright test vault-ui.spec.ts
```

В текущем `playwright.config.ts` используется Chromium из Desktop Chrome device preset. Для workflow `.github/workflows/e2e-staging.yml` нужен специальный `inventory-e2e-staging` GitHub environment и подтверждение одноразового staging URL.

Покрытые реальные сценарии: вход, чтение профилей, создание строкового и структурированного профиля, отсутствие secrets в доступных REST-ответах и UI, workspace isolation, disable/enable, конкурирующая запись с 412, stage/cancel/stage/activate, прямая замена и архивирование, best-effort cleanup при падении. При отдельных `E2E_VAULT_VIEWER_EMAIL`, `E2E_VAULT_VIEWER_PASSWORD` (опционально `E2E_VAULT_VIEWER_MFA`) проверяется viewer: отсутствие формы записи и отказ 403 на create, update, delete.

Для сценариев Vault отключены Playwright traces, screenshot и video, чтобы не архивировать вводимые синтетические credentials в CI artifacts. Используйте **только тестовые** учётные данные и уничтожайте изолированный staging dataset после тестов. Обычные API-access logs также должны маскировать тела запросов с `secret`.

## Release ограничения

Новых миграций и REST маршрутов для 0.3.9 нет: используются ранее реализованные реальные Vault endpoints. Тесты требуют живой PostgreSQL, PHP session, Vault key и браузер, а не mock-server. Само наличие Playwright спецификаций не является доказательством их успешного запуска. Перед deploy обязательны Nuxt `npm ci`, typecheck и build, реальный Vault PostgreSQL integration, браузерный E2E на изолированном стенде и отдельно проверенные storage backup/restore. В исходном архиве 0.3.8 отсутствовали composer/npm lock-файлы; без их действительного создания/фиксации строгий release-gate остаётся непроходимым.
