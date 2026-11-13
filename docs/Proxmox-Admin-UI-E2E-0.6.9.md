# Proxmox Admin UI & E2E — 0.6.9

## Работа с панелью

Откройте `/proxmox` из основного меню. Выберите Workspace и PVE либо PBS. Поиск, страницы и список подключений работают через `/api/v1/connectors/targets`. URL хранит только идентификаторы и представление, без токенов. Пример: `/proxmox?workspace_id=<uuid>&target_id=<uuid>&view=guests`. Чужое подключение не отображается в выбранном Workspace. Для открытия панели нужны `workspaces.read`, `connectors.targets.read`, `inventory.assets.read`.

- **PVE:** кластер и узлы, гости, хранилища и сеть, история UPID, управление гостями, планы сбора. Загружается только выбранный раздел. Время наблюдения, частичные данные и история показываются в соответствующей панели. Это сохранённые наблюдения, не непрерывный поток состояния гипервизора.
- **PBS:** datastores, backup groups/snapshots, namespaces, задания и планы `pbs.backups`. Нет удалённых изменяющих действий.
- **Настройки и Vault:** создание target, TLS profile, привязка credentials остаются в существующем разделе `/connectors`; переход сохраняет Workspace и target.

Для первого сбора PVE настройте доверенный TLS-профиль, создайте планы `proxmox.cluster`, `proxmox.guests`, `proxmox.storage-network`, `proxmox.tasks`. Используйте Sync для сопоставления гостей с Inventory, Inspect для наблюдений. PBS использует `pbs.backups` Inspect. План запускается через существующий scheduler/worker, не через браузерный запрос к гипервизору.

### Управление гостями

Из актуального списка гостей кнопка «Управление» открывает `/proxmox?...&view=actions&guest=qemu%3A101`. Форма фиксирует выбранный external_id; точный GET `/connectors/targets/{id}/resources?external_id=qemu%3A101` возвращает только соответствия внутри target и Workspace. Для конфликтующего, отсутствующего или не синхронизированного ресурса форма не выбирает другой VMID автоматически. Для выбора другого гостя вернитесь к списку или используйте «Все VM / LXC».

Изменяющие действия сохраняют ограничения 0.6.6/0.6.7: active target, owner/manager, отдельные права, Vault, свежий MFA, точное подтверждение, If-Match, Idempotency-Key, live preflight и UPID. Вход в нативную консоль выполняется отдельно в Proxmox. Отправка в PVE при неизвестном результате не повторяется автоматически. Снимки не допускают rollback работающего гостя или RAM-state. Не используйте UI как механизм обхода HA, ACL и блокировок PVE.

## Обновление 0.6.8 → 0.6.9

Замените изменённые файлы. Новых SQL-миграций нет. В стандартном окружении с проверенными lockfiles установите зависимости и пересоберите Admin:

```sh
cd admin
npm ci --no-audit --no-fund
npm run typecheck
npm run build
```

Если исходный архив не содержит lockfiles, сначала получите/проверьте их по процедуре `scripts/resolve-dependencies.sh`; `npm ci` не работает без lockfile. Строгий release gate требует lockfiles, собранный Admin, PostgreSQL, Redis, backend-интеграцию и browser E2E. Канал `development` не означает, что эти проверки можно пропустить перед production.

## Реальный E2E на изолированном staging

`admin/tests/e2e/proxmox-admin.spec.ts` — сценарий Playwright с реальными cookie-сессиями, CSRF, API, БД, Vault, workers и отдельным HTTPS-fixture. Никаких browser route mocks. Он создаёт тестовый Workspace и подключения, выполняет реальные коллекции, открывает специализированную страницу, создаёт и удаляет снимок синтетической VM 101, сверяет UPID, читает PBS, проверяет history navigation и изоляцию viewer. Консольная ссылка проверяется, но не открывается. Полный тест требует реального приложения на disposable staging; нельзя запускать на production. Существующие сценарии lifecycle и data collection также обязательны.

1. Запустите обновлённый `tests/connector-e2e-fixture.py` **только** в отдельной тестовой сети. Он отвечает PVE и PBS в зависимости от Authorization и никогда не обращается к реальному гипервизору. Для тестового DNS-имени подготовьте сертификат с корректным SAN. Используйте новый процесс fixture на каждый полный прогон (его состояние in-memory). Изолируйте сертификат и токены от production.
2. Разрешите fixture в `CONNECTOR_ALLOWED_NETWORKS` staging. Настройте `PBS_CA_FILE` на тестовую CA (или self-signed fixture certificate), доступную PHP/worker, и проверьте DNS/SAN. PVE дополнительно проверяет SHA-256 DER pin. Не отключайте TLS. Настройте `CONNECTOR_WRITE_ACTIONS=true` согласно `.env.example` и запустите scheduler/worker; используйте отдельного администратора с TOTP. Не используйте настоящие PVE/PBS credentials.
3. В workflow `e2e-staging.yml` укажите адрес disposable staging и данные fixture; подтвердите disposable environment. Для ручного запуска задайте `E2E_BASE_URL`, `E2E_ADMIN_EMAIL`, `E2E_ADMIN_PASSWORD`, `E2E_ADMIN_TOTP_SECRET`, `E2E_CONNECTOR_UI=1`, `E2E_CONNECTOR_FIXTURE_HOST`, `E2E_CONNECTOR_FIXTURE_PORT`, `E2E_CONNECTOR_FIXTURE_PIN`, `E2E_ALLOW_WRITES=1`, `E2E_ISOLATED_STAGING=1`. Остальные E2E-флаги см. workflow. Не сохраняйте значения секретов в Git.
4. Установите Chromium Playwright и выполните `bash scripts/verify-browser-e2e.sh` из корня. Новый `proxmox-admin.spec.ts` включён в обязательный JSON report policy; пропущенные, отсутствующие, flaky или неуспешные сценарии не проходят gate. Удалите disposable БД и остановите fixture после запуска. Traces/screenshots/videos отключены для защиты MFA и секретов.

Локальные проверки без развёрнутого staging:

```sh
php scripts/verify-release.php
python3 tests/proxmox-admin-fixture-integration.py
```

Вторая команда запускает тот же TLS-fixture и реальные PHP-драйверы: PVE cluster, guest, storage topology, console, snapshot create/read/delete, task archive, PBS discovery. Это интеграция протокола, а не замена browser E2E.
