# CajeerServers 0.2.9 — Complete Inventory Admin UI & E2E

## Реализованная функциональность

Это по-прежнему self-hosted PHP веб-ресурс, а не ОС. Данные CMDB считываются через уже работающие REST API. Новые удалённые команды и гипервизорные коннекторы не вводятся.

- `/inventory` — рабочая таблица с RBAC, фильтрами, пагинацией, CRUD и переходом в карточку каждого объекта.
- `/inventory/{uuid}` — новая карточка asset: версия и ETag, редактирование разрешённых manual-полей, soft-delete/restore, IPAM-интерфейсы и адреса, связи, журнал ревизий и только идентификаторы credential bindings. Секреты и encrypted payload никогда не читаются.
- `/ipam`, `/history`, `/topology`, `/catalog`, `/transfer`, `/workspaces` — сохраняют контекст `workspace_id` через адресную строку. При переходе из карточки дополнительно передаётся `asset_id` и выбирается соответствующий объект, если он доступен.
- Import UI повторно использует тот же `Idempotency-Key` при повторной отправке после пропавшего HTTP-ответа. Изменение файла или workspace сбрасывает ключ. После создания batch запуск повторно недоступен; статус обновляется каждые 3 секунды только при открытой активной вкладке, пока batch выполняется. Данные импортируются worker'ом, а не браузером.
- Списки связанных ресурсов на карточке показывают **только первые 100 результатов каждого API**, это не полный topology index. Для полного набора предусмотрены специализированные страницы с API-пагинацией. Элементы скрыты по permissions, но безопасность обеспечивается серверной проверкой RBAC и workspace membership.

## Быстрые локальные проверки (не равны browser E2E)

```bash
php scripts/verify-release.php
cd admin
node --experimental-strip-types tests/navigation-contract.mjs
node tests/static-check.mjs
```

Используются реально исполняемые тесты URL-контекста и нативные backend smoke-наборы. Для полноценного сборочного контроля необходимы `composer.lock`, `admin/package-lock.json`, установленный Composer, `npm ci`, PHP 8.4/8.5 и `npm run typecheck && npm run build`. **Lock-файлы не создавать вручную без разрешения пакетов через registry**. Отсутствие lock-файла должно приводить к отказу строгого release-gate, а не к ложному PASS.

## Browser E2E на отдельном стенде

**Не запускать на production.** Нужны установленный PHP backend с PostgreSQL 17/18, рабочий Nuxt SSR под тем же origin, Redis/Queue Worker для transfer-теста и отдельная тестовая БД, которую можно пересоздать. Тесты создают объекты и исторические события, а часть импортных наблюдений не удаляется.

```bash
cd admin
npm ci
npx playwright install chromium
export E2E_BASE_URL=https://staging.example.test
export E2E_ADMIN_EMAIL=admin@staging.example.test
export E2E_ADMIN_PASSWORD='...'
export E2E_ALLOW_WRITES=1
export E2E_ISOLATED_STAGING=1
export E2E_TRANSFER_WRITABLE=1
export E2E_HISTORY_WRITABLE=1
npm run test:e2e:preflight
npm run test:e2e
```

`E2E_ADMIN_MFA` может быть задан для аккаунта с TOTP, но истекающий короткоживущий код необходимо обновлять на время прогона. Рекомендуется отдельный staging-пользователь с нужными permissions и выключенной 2FA **только внутри disposable окружения**.

Новый `inventory-journey.spec.ts` проверяет создание asset через браузер, редактирование c ETag и реальный отказ `412` при устаревшей версии, навигацию между подсистемами с сохранением workspace и очистку тестового объекта. `import-idempotency.spec.ts` перехватывает ответ только **после реального HTTP POST на backend**, возвращает браузеру искусственную ошибку 502 и проверяет, что повторная отправка с тем же ключом возвращает тот же PostgreSQL batch. Эти тесты не являются mock-only.

В `.github/workflows/e2e-staging.yml` есть отдельный вручную запускаемый GitHub Actions workflow с обязательным подтверждением disposable стенда, HTTPS и секретами из environment `inventory-e2e-staging`. До реального выполнения этого workflow и PostgreSQL integration **нельзя считать E2E или production-допуск пройденными**. Отсутствующий `admin/package-lock.json` намеренно блокирует CI.

## Обновление 0.2.8 → 0.2.9

1. Создать резервную копию PostgreSQL и приложения.
2. Наложить ZIP-патч 0.2.9 **на полную версию 0.2.8** с сохранением относительных путей.
3. Проверить `php bin/cajeer version`, `php bin/cajeer migrate:validate` и `php scripts/verify-release.php`.
4. Выполнить `npm ci && npm run typecheck && npm run build` после подготовки настоящего lockfile.
5. Пройти браузерные сценарии на disposable staging; затем `php scripts/verify-release.php --strict` с требуемыми PostgreSQL/Redis/HTTP/E2E условиями.

**Новых миграций PostgreSQL и новых REST endpoints в 0.2.9 нет** — UI использует уже реализованные сервисы 0.2.3–0.2.8. Ни одна операция CMDB не должна возвращать фиктивные данные.
