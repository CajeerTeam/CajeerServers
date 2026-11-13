# Safe Action Dispatcher — 0.6.6

Управление реальными VM/LXC в Proxmox VE 8/9. Использует существующие action service, Vault, RBAC, Inventory, TLS transport, историю задач и Admin UI, без очереди автоматических повторов POST.

## Установка и подготовка

1. Обновите исходники и выполните `php bin/cajeer migrate`. Миграция `0042_proxmox_safe_actions.sql` расширяет action CHECK, добавляет время последнего polling и индекс; старые записи сохраняются. Не редактируйте исторические миграции.
2. Настройте verified Proxmox connection profile (проверенный CA, hostname и SHA-256 pin, cluster/anchor identity). `CONNECTOR_WRITE_ACTIONS=true` включает отправку действий; по умолчанию она выключена. Для новых действий профиль обязателен даже при `PROXMOX_REQUIRE_PROFILE=false`. Старые действия доступны для чтения/подтверждения в исходной области.
3. API token должен видеть кластер/узел и гостя, иметь `VM.Audit` и `VM.PowerMgmt` на конкретный `/vms/{vmid}`. Для cluster/profile read также нужны соответствующие PVE audit permissions. Не выдавайте root token для всего кластера. Синхронизируйте CMDB, сопоставьте гостя, устраните конфликты. Запустите `proxmox.guests` для сохранения известных QEMU fingerprint.
4. В Admin UI «Удалённые действия» выберите обнаруженную VM/LXC, действие, введите точное подтверждение и свежие пароль/TOTP. Отдельный `stop` — принудительное отключение, может привести к потере данных; `shutdown` не включает forceStop, skiplock, overrule-shutdown и не повторяется как stop.
5. Для автоматического обновления результатов создайте действующий план `proxmox.tasks` / Inspect с интервалом 60 секунд, запустите worker/scheduler. План не может отправлять действия; он только сверяет известные UPID.

## API

`POST /api/v1/connectors/targets/{id}/actions` — session cookie, CSRF, `If-Match` текущего target, `Idempotency-Key` (16–128 символов). Одноразовое MFA-разрешение Vault выдается до запроса существующим `/authorize-use` workflow. Например:

```json
{"action":"lxc.reboot","external_id":"lxc:102","confirm":"lxc.reboot lxc:102"}
```

`vm.start`, `vm.shutdown`, `vm.stop`, `vm.reboot` принимают только `qemu:<VMID>`; `lxc.start`, `lxc.shutdown`, `lxc.stop`, `lxc.reboot` — только `lxc:<VMID>`. Начальное состояние для start — stopped; для остальных — running (QEMU qmpstatus=running). Paused/suspended, template, lock, HA-managed/неизвестное HA ownership, offline node, отсутствие quorum, дубликаты VMID и непроверенные ответы блокируются. Если ресурс уже находится в целевом состоянии, возвращается конфликт без POST, а не новая команда.

Запрос строится сервером: `/api2/json/nodes/{live_node}/{qemu|lxc}/{vmid}/status/{operation}`. Путь, node и параметры POST нельзя передать из клиента. Проверка live config/current выполняется дважды; изменения location, digest, состояния и известной идентичности блокируют отправку. PVE проверяет права и собственные locks при выполнении команды. Основной endpoint проверяется перед отправкой; POST никогда не переключается на fallback. Сертификат проверяется до отправки токена. В ответе проверяются формат UPID, узел, тип задачи `qm*`/`vz*` и VMID.

GET `/actions` и `/actions/{actionId}` показывают историю. POST `/actions/{actionId}/refresh` с MFA делает только GET status исходного UPID. POST `/actions/{actionId}/acknowledge` с MFA и `{"confirm":"I verified the remote result"}` предназначен для восстановления после внешней проверки. Если известный UPID еще running, acknowledgement отклоняется. Если UPID недоступен или исходная область credentials/target изменилась, оператор должен сначала вручную проверить PVE, а не угадывать результат.

## Состояния и сбои

- `dispatching`: намерение сохранено до POST. Если процесс погиб, действие остается unresolved. Ни worker, ни повтор HTTP не отправляют его автоматически.
- `submitted`: UPID принят и проверен. Новые действия на этом target/guest заблокированы до результата. Повтор с тем же ключом возвращает существующую запись, при другом payload/actor/version — конфликт.
- `failed`: preflight гарантированно не отправил POST либо известный UPID завершился с ошибкой. `error_code` различает причину. Warning в истории трактуется консервативно как failed (remote_task_warning).
- `succeeded`: известный UPID завершен с OK, не обещание, что состояние гостя никогда не изменится снова. CMDB остается независимой от этого журнала.
- `unknown`: POST мог быть исполнен, но ответ потерян/невалиден или транзакция не завершилась. Не повторять, проверить PVE вручную.
- `acknowledged`: оператор проверил неопределенный результат внешним способом. Это не подтверждение успеха.

Сборщик `proxmox.tasks` включает до 10 submitted UPID в bounded status polling, даже до появления в архиве, с сохранением времени проверки для fairness. В той же транзакции, где сохраняется история и курсор, он завершает только actions с точным совпадением target, workspace, credential/target revisions, UPID, node, worker и VMID. Записывается аудит; running/unknown не освобождают блокировку. Исторические/чужие scope не могут завершить текущую операцию. Сборщик не угадывает UPID при unknown и не делает POST.

## Границы

- Поддерживаются прямые операции не-HA гостей. HA-managed требуют отдельного HA lifecycle и отслеживания фактического состояния; они намеренно исключены.
- Между последним GET и POST остаётся внешняя гонка: PVE power API не принимает compare-and-swap digest. Внешние операторы, HA и другие независимые Cajeer target не участвуют в локальном advisory lock. Не создавайте несколько пишущих target для одного кластера. Не отключайте PVE locks.
- Fingerprint QEMU проверяется, если ранее сохранён. LXC и QEMU без SMBIOS UUID не имеют надёжного неизменяемого generation ID в данном API: VMID reuse следует проверить оператору перед синхронизацией и действием. Digest защищает изменения только внутри операции.
- MFA/состояние binding повторно проверяются до POST. Потерянный ответ требует ручного подтверждения, в том числе после ротации credentials. Не используйте автоматические retry/replay HTTP-клиентов.
- Подтверждение UPID не является проверкой работоспособности ОС/приложений или health-check после reboot.

## Проверки

`php tests/connector-actions-smoke.php`, `python3 tests/connector-actions-https-integration.py`, `python3 tests/proxmox-profile-https-integration.py`, `bash tests/connector-sdk-integration.sh`, `php scripts/verify-release.php`. Строгий gate `php scripts/verify-release.php --strict` требует изолированный PostgreSQL 17+, pdo_pgsql, реальные зависимости и staging browser fixture. PostgreSQL сценарии проверяют upgrade 0042, старые UPID, LXC mapping, durable intent/duplicate, неопределенный результат, отказ при running acknowledgement и автоматическое завершение в task collection job.
