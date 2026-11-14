# OS / Network Connectors Stable — 0.9.0

Релиз стабилизации существующих production-драйверов и их сборщиков. Не вводит новые схемы БД, REST endpoints или заглушки. Поддерживаемые режимы: Debian/Ubuntu по SSH, Windows Server по WinRM HTTPS (Basic только внутри TLS), SNMPv3 authPriv (SHA-256/SHA-512, AES128), RouterOS 7 REST HTTPS. Существующие профили доверия, Vault, RBAC, очередь, метрики, события, topology/IPAM и UI сохраняются.

## Исправления

- SSH: выход процесса не означает EOF канала; дочитываются stdout/stderr после exit-status. Не принимается ответ без завершения команды. PHP ограничивает helper монотонным deadline; проверяется тип credential до запуска процесса.
- WinRM: проверяется единственный SOAP Header/Body и RelatesTo, включая HTTP 500 Receive timeout; чужой CommandId отклоняется. Таймаут отдельного вызова ограничен остатком общего времени; очистка Shell выполняется отдельным коротким запросом. UTF-8 byte limit проверяется в helper. Windows-метрики с отсутствующим CIM-свойством больше не превращаются в нули; IPv6 zone suffix не является частью адреса.
- Linux: CPU-счётчики при отсутствии прогресса или регрессии повторно измеряются, а не делятся на ноль. Метрики проверяют identity до и после замера; PHP требует целочисленные байты и положительные totals.
- SNMP: `ifNumber=0` корректен; изменение `ifNumber` во время сбора отвергается; дедлайны измеряются монотонными часами. Лимит простой inventory по-прежнему 64 интерфейса; расширенная discovery поддерживает до 2048, но не считает невидимые MIB-таблицы полными.
- RouterOS: нативный размер экспортного файла читается через `/rest/file?name=...&.proplist=name,size` до и после чтения. Каждый непустой блок прибавляется в точном byte offset; короткий блок не означает EOF, а преждевременный пустой блок и изменение размера отвергаются. Сохраняются проверка identity, обязательная очистка и лимит 1 MiB. `.rsc` остаётся текстовым export без show-sensitive, не полным бинарным backup. Для сборщика нужны права export, file/read, чтение метаданных файла и удаление созданного временного файла.

## Приёмка реальных устройств

Использовать отдельный стенд и операторские учётные данные с минимально необходимыми правами. CLI **не создаёт записи в БД**, не меняет Vault и не заменяет E2E очереди: он вызывает ровно существующие ConnectorRegistry/CollectionRuntimeService и проверяет данные, полученные драйверами. RouterOS временно создаёт `.rsc` и удаляет его, требуется явное согласие.

```bash
php scripts/verify-os-network-live.php /secure/os-network-acceptance.json
```

Конфигурация: верхнеуровневые ключи `allowed_networks` (непустой список CIDR), `python` (абсолютный путь к Python окружению с Paramiko), `allow_routeros_export` (true), `targets` (4–16 элементов, минимум по одному ssh, winrm, snmp, routeros). Можно включить одновременно Debian и Ubuntu. Каждый target имеет ровно следующие поля:

| Поле | Значение |
|---|---|
| provider | ssh / winrm / snmp / routeros |
| host, port | DNS/IP и целочисленный порт |
| credential_type | ssh_password / ssh_private_key / winrm / snmp_v3 / routeros_api соответственно |
| secret_file | абсолютный путь к обычному приватному JSON-файлу credential, права 0600 или 0400, без symlink |
| profile | существующий профиль OsNetworkProfile, полученный и сверенный вне автоматической приёмки |
| ca_file | абсолютный путь к CA для WinRM/RouterOS или null (системное хранилище); для SSH/SNMP null |
| expected_name | ожидаемое имя hostname/sysName/RouterOS identity, точное совпадение |
| expected_interface | имя интерфейса из детального снимка, для Windows Win32_NetworkAdapter.Name, SNMP ifName/ifDescr |

SSH profile: `primary_pin` = SHA-256 SSH public key blob в hex, `identity_hash` = SHA-256 `ssh:<machine-id>`, `key_type` = тип ключа. WinRM: `primary_pin` = SHA-256 DER TLS certificate в hex, `identity_hash` = SHA-256 `winrm:<MachineGuid без дефисов в нижнем регистре>`. RouterOS: `primary_pin` TLS DER SHA-256, `identity_hash` = SHA-256 `routeros:<имя identity>`. SNMP: `engine_id` = hex engineID, `object_id` = sysObjectID с начальной точкой. Не используйте результаты неизвестного сетевого узла как автоматически доверенные pins.

Формат секретов совпадает с `CredentialProfile`: SSH password — username/password, key — username/private_key/необязательная passphrase; WinRM — username/password/auth=basic; SNMPv3 — username/auth_protocol/auth_passphrase/privacy_protocol/privacy_passphrase; RouterOS — username/password. Файлы не включать в репозиторий, не передавать секреты через argv.

Результат: JSON с provider, списком проверок, безопасными error codes. Код 0 — все указанные устройства прошли; 1 — хотя бы одна проверка не прошла; 2 — неправильная конфигурация/файл. При проваленной health последующие запросы этого устройства не выполняются; остальные устройства проверяются. Проверяются ожидаемое имя и профиль, inventory, обязательные метрики, все заявленные scope, ожидаемый интерфейс, начальный и следующий event cursor (нужны видимые события за последние 24 часа) и RouterOS export. SNMP topology проверяет доступность интерфейсов; пустая LLDP-таблица не подтверждает наличие связей, поэтому отдельно сравните реальные соседи и VLAN в UI. Для полного системного acceptance нужны читаемые журналы Linux/Windows, рабочий systemd и доступные CIM/registry/MIB scopes. Недоступные функции не считаются PASS.

Строгая проверка всего релиза требует `CAJEER_OS_NETWORK_ACCEPTANCE_CONFIG=/secure/os-network-acceptance.json` наряду с прежними PVE/PBS, VMmanager, PostgreSQL, Redis, browser E2E и deployment gate:

```bash
CAJEER_OS_NETWORK_ACCEPTANCE_CONFIG=/secure/os-network-acceptance.json php scripts/verify-release.php --strict
```

## Проверки

- `python3 tests/host-transport-integration.py --ssh` — WSMan TLS fixture и настоящий SSH сеанс Paramiko, включая поздний stdout после exit-status. `--postgres` добавляет существующие проверки сохранения в БД.
- `bash tests/snmp-protocol-integration.sh --postgres` — реальный net-snmp daemon и PostgreSQL.
- `python3 tests/routeros-https-integration.py --postgres` — TLS fixture, 31 сценарий, включая короткие блоки и обрезанный export, существующая проверка хранения/версий.
- `python3 tests/os-network-live-acceptance-integration.py` — реальный CLI с TLS-стендами, отказ при отсутствующих провайдерах, безопасный отчёт и удаление временного файла.
- `python tests/windows-native-agent-integration.py` на Windows Server — реальные PowerShell 5.1 CIM/Registry/Event Log, PHP normalization, event cursor. Это отдельная CI job, не заменяет native WinRM HTTPS acceptance.
- `php scripts/verify-release.php` — прежние регрессии и новые проверки CLI.

Новые миграции отсутствуют; применить имеющиеся до 0060 включительно. Канал `development` сохраняется до подтверждённой строгой приёмки на реальных устройствах. Никакой тестовый fixture не является подтверждением работы физического маршрутизатора или Windows Server WinRM. Full staging/browser/PG и нативный Windows/SSH/SNMP должны пройти в целевом окружении.
