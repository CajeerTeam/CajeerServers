# Connector Framework Stable — 0.5.0

## Upgrade 0.4.9 → 0.5.0

Сделайте проверенный encrypted backup БД, storage и ключей; сохраните рабочую копию кода 0.4.9. Остановите scheduler, connector workers и входящий трафик на время обновления. Распакуйте changed-files ZIP поверх корня 0.4.9. Новых миграций нет, но все предыдущие миграции до 0028 должны быть применены. Не удаляйте записи connector_runs, queue_jobs или connector_actions вручную: они нужны для восстановления и идемпотентности.

```bash
php bin/cajeer migrate
php bin/cajeer vault:check
php scripts/verify-release.php
php bin/cajeer connector:doctor --json
```

Проверьте CIDR allowlist, доверенные CA и SSH known_hosts. `connector:doctor` проверяет регистрацию всех пяти драйверов, системные зависимости, конфигурацию и согласованность PostgreSQL (runs/queue/grants/events); это read-only диагностика, не проверка доступности каждой удалённой системы. Она возвращает exit 1 при отсутствии обязательной зависимости. На worker требуются PHP CLI pcntl, pdo_pgsql, sodium, openssl; для SNMP ext-snmp; для SSH Python с Paramiko из `core/Connector/agents/requirements.txt`. Для SSH с активными targets обязателен known_hosts. `CONNECTOR_ALLOWED_NETWORKS` должен содержать реальные разрешённые management-сети.

## Changes and guarantees

- HTTPS: TLS 1.2/1.3, pinned IP, original peer name, no redirect/proxy, fixed I/O deadline, strict framing and size limits. Разрешён HTTP/1.0/1.1; gzip, chunk extensions и неоднозначные Content-Length/Transfer-Encoding отклоняются. DNS resolution выполняется системным resolver и отдельно не ограничивается I/O deadline; worker имеет process alarm 240s. Настройте resolver timeouts на хосте.
- Registry: неизменяемые metadata в рамках процесса, валидация результатов всех драйверов, пустой health/rotation proof недопустим. Custom PHP SDK доверенный, не sandbox. Write actions только встроенный Proxmox.
- Vault: active binding любого провайдера требует проверку pending version. Отсутствующий драйвер не является поводом обхода проверки; восстановите драйвер или явно отключите/отвяжите target перед ротацией. Ручная операция повторно проверяет действующие RBAC и MFA session перед commit. Автоматический worker использует существующий service grant fence.
- Queue: source of truth — `connector_runs.result`, queue ACK содержит только run ID/status; завершённый run восстанавливается после последней истёкшей lease, не выполняя второй удалённый запрос. Cancelled queued jobs завершены без новой попытки. Общая connector queue остаётся PostgreSQL независимо от backend очереди остальных jobs. Для advisory locks необходима выделенная сессия PostgreSQL.
- Actions: выключение CONNECTOR_WRITE_ACTIONS запрещает новые POST power commands, но не блокирует MFA/RBAC-защищённое чтение удалённой задачи и acknowledgement ранее отправленного действия. Неизвестный результат не повторяется автоматически. Target, credential и binding должны оставаться пригодными для безопасной проверки.
- SDK: публичный PHP SDK v1 и TS package 0.5.0; TypeScript 5.8.3 pinned. Существующие API маршруты и schema не меняются.

## Проверка релиза и эксплуатация

```bash
python3 tests/connector-stability-https-integration.py
python3 tests/connector-https-integration.py
python3 tests/vmmanager-https-integration.py
python3 tests/connector-actions-https-integration.py
bash tests/connector-sdk-integration.sh
```

На доверенном build host с доступом к registry выполните `bash scripts/resolve-dependencies.sh`, проверьте и зафиксируйте настоящие composer.lock, admin/package-lock.json и packages/ts-sdk/package-lock.json. После сборки используйте `composer install` и `npm ci`; не генерируйте lock на production. Strict gate не пропускает отсутствующие зависимости/сборку. Запустите на отдельном disposable staging `php scripts/verify-release.php --strict` с изолированными `CAJEER_PG_TEST=1`, `CAJEER_PG_TEST_DATABASE=cajeer_test_*`, отдельной installer DB, Redis и HTTP/E2E переменными согласно предыдущим release docs. CI включает PostgreSQL 17/18, PHP 8.4/8.5, Redis, SSH/WinRM, SNMP, HTTPS и SDK; staging E2E workflow отдельно. Проверяйте все пять драйверов также на разрешённых реальных appliances.

После успешной проверки запустите `php bin/cajeer scheduler:run` и `php bin/cajeer connector:work 20` через существующий supervisor/cron. Проверьте health, inspect, sync, rotation и recovery для каждого провайдера; при неизвестном результате power action сверяйте состояние на Proxmox вручную. Для rollback остановите трафик и workers, восстановите сохранённый код 0.4.9; поскольку схема не меняется, БД не откатывайте автоматически. Если повреждены данные, используйте проверенный backup recovery, не повторяйте автоматически удалённые действия.

## Release evidence

Native smoke, реальные локальные TLS fixtures и TypeScript SDK проверяются отдельно. Они не заменяют интеграционный прогон PostgreSQL/Redis, SSH/SNMP на настоящих протоколах, Nuxt build и Playwright или тесты с реальными appliances. В окружении подготовки отсутствовали PostgreSQL/pdo_pgsql, ext-snmp/snmpd, Paramiko и доступ к npm registry. Composer/npm lockfiles не подделывались, полная production certification остаётся заблокированной до прохождения strict gate. Release channel остаётся development до подтверждения этого gate.
