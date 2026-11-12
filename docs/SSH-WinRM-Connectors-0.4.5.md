# CajeerServers 0.4.5 — SSH & WinRM Connectors

## Обновление с 0.4.4

Сделайте проверенный backup, остановите connector workers и Scheduler, распакуйте архив поверх 0.4.4. Выполните `php bin/cajeer migrate` (0025), `php bin/cajeer vault:check`, `php scripts/verify-release.php`. После настройки возобновите `php bin/cajeer connector:work 20` и `php bin/cajeer scheduler:run`.

### Зависимости transport

PHP 8.4+, `proc_open`, sodium, PostgreSQL 17+, Python 3.9+ на сервере CajeerServers. WinRM использует Python stdlib; SSH дополнительно требует Paramiko 5.0.1+ (5.x) и его зависимости. Устанавливайте Python-окружение оператором вне web root; пользователь PHP не должен иметь права изменять исполняемые файлы и trust store. Пример для Linux:

```bash
python3 -m venv /opt/cajeer-connectors/venv
/opt/cajeer-connectors/venv/bin/python -m pip install -r core/Connector/agents/requirements.txt
/opt/cajeer-connectors/venv/bin/python -c 'import paramiko; print(paramiko.__version__)'
```

В `.env`:

```dotenv
CONNECTOR_PYTHON=/opt/cajeer-connectors/venv/bin/python
CONNECTOR_ALLOWED_NETWORKS=10.40.8.0/24,10.50.0.0/24
SSH_KNOWN_HOSTS_FILE=/etc/cajeer/ssh_known_hosts
WINRM_CA_FILE=/etc/cajeer/windows-ca.pem
```

CIDR — примеры, замените своими. Пути должны быть абсолютными и доступны на чтение PHP/worker. `WINRM_CA_FILE` можно оставить пустым для системного trust store. `SSH_KNOWN_HOSTS_FILE` обязателен, файл должен содержать проверенный независимо ключ целевого хоста. Не доверяйте результату `ssh-keyscan` без независимой сверки. Для порта 22 запись соответствует имени target, для нестандартного порта `[host]:port`. Нет TOFU или отключения TLS/SSH verification. Предпочтительны Ed25519, ECDSA или RSA-SHA2; устаревший RSA-SHA1 не поддерживается.

### Linux SSH

- Provider `ssh`, порт 22 (или явно настроенный), purpose `ssh`. Vault profile `ssh_password` (`username`, `password`) или `ssh_private_key` (`username`, `private_key`, опциональный `passphrase`).
- На целевом Linux — Python 3, `/etc/machine-id`, `/etc/os-release`, `/proc/meminfo`, `lsblk` (util-linux), `ip` (iproute2). Подготовьте выделенного пользователя без sudo с правами чтения этих данных и запуска фиксированного collector. Права на удалённую запись не требуются. Не включайте парольную аутентификацию, если ваша политика допускает только ключи.
- SSH host key проверяется до отправки credential. PEM/PKCS8/OpenSSH приватный ключ читается в памяти, без файла, агента или передачи ключа на сервер. SSH не принимает пользовательский command/script; запускается только встроенный `linux_collect.py`.

### Windows WinRM

- Provider `winrm`, порт 5986, purpose `winrm`. Vault profile `winrm`: `username`, `password`, **`auth: basic`**. В 0.4.5 другие способы аутентификации отклоняются, в том числе отсутствие `auth`.
- Windows Server с Windows PowerShell 5.1, рабочим WinRM HTTPS listener, сертификатом для имени target, `Basic` включённым на службе и `AllowUnencrypted=false`. Ограничьте сетевой доступ к 5986 адресом CajeerServers. Используйте локальную/доменную учётную запись с минимальными правами WSMan remote shell, чтения MachineGuid и указанных CIM-классов. Настройка listener и выдача прав зависят от локальной политики Windows; проверьте их на выделенном staging. Не включайте Basic на открытом HTTP, не отключайте TLS validation.
- WSMan создаёт временный shell, запускает фиксированный PowerShell `Get-CimInstance` и чтение `MachineGuid`, получает ограниченный stdout, закрывает shell (best-effort при обрыве). Срок простоя shell ограничен. Нет пользовательского PowerShell, WinRM HTTP, NTLM, Kerberos или произвольных write-actions.

## Использование

В `/vault` создайте credential; в `/connectors` создайте target с нужным провайдером и привяжите его к Inventory Asset и credential с одинаковым purpose. Health, inspect, sync и validate_rotation используют существующий REST/MFA-поток. Для фонового read-only запуска выдайте service grant и настройте Scheduler по инструкции 0.4.2. Ротация новой версии проходит через реальный collector; ошибка аутентификации, доверия или отсутствующая dependency не повторяются worker автоматически. Изменение target/credential или отзыв grant блокирует запись незавершённого запуска.

Синхронизация создаёт или сопоставляет один `server` на target. External ID — SHA-256 от provider и нормализованного machine-id/MachineGuid, а не имя или IP. Реальный идентификатор не возвращается; дублированные machine-id в клонированных ОС следует исправлять на самих хостах. При повторном обнаружении UUID не изменяется. Как и остальные источники, ambiguous name требует явного сопоставления; автоматического cross-target merge нет.

Сведения: OS family, OS name/version, logical CPU, RAM bytes, физические/видимые диски, их ёмкость, интерфейсы и IP. В Inventory — `attributes.host_hardware` и `ip_addresses`; интерфейсы здесь наблюдения, а не автоматические IPAM objects. Merge не удаляет другие атрибуты; явно вручную заданный `host_hardware` защищён provenance и отображается как conflict. При превышении общего лимита attributes синхронизация откатывается. История сохраняет факт изменения attributes (значения редактируемых attributes не включаются в исторические snapshot), текущие данные видны в карточке объекта.

## Проверки

```bash
php tests/host-runtime-smoke.php
python3 tests/host-transport-integration.py
/opt/cajeer-connectors/venv/bin/python tests/host-transport-integration.py --ssh
php scripts/verify-release.php
# С disposable CAJEER_PG_TEST=1 / CAJEER_PG_TEST_DATABASE=cajeer_test_*:
php tests/host-hardware-postgres-integration.php
```

HTTPS fixture действительно выполняет TLS и WSMan SOAP через PHP driver и helper, но возвращает тестовый CIM-совместимый вывод; это не Windows Server. SSH fixture поднимает локальный Paramiko server, выполняет фиксированный Linux collector через SSH, проверяет password/Ed25519/encrypted key, host-key mismatch и неверный пароль. PostgreSQL test проверяет миграцию, stable ID, hardware update, ручные атрибуты, rollback. CI и `--strict` требуют эти проверки. В текущей среде выполнены native smoke, локальный Linux collector и WinRM TLS fixture; Paramiko установить не удалось, поэтому SSH fixture не запущен, как и PostgreSQL, реальный Windows Server, Nuxt production build и Playwright. До эксплуатации пройдите их на staging. Для прямых HTTP-операций настройте PHP-FPM / reverse proxy timeout более 90 секунд либо используйте фоновые jobs. Helper ограничен 90 секундами, SSH 35 секундами сбора, WinRM 65 секундами плюс cleanup; лимиты 256 KiB stdout, до 64 дисков, интерфейсов и IP. Необработанная ошибка возвращает безопасную классификацию, без сырого ответа или секрета.
