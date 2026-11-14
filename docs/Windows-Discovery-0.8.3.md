# Windows Discovery 0.8.3

Шесть read-only сборщиков `winrm.system`, `winrm.software`, `winrm.services`, `winrm.storage`, `winrm.network`, `winrm.updates` выполняются через существующий WinRM HTTPS/WSMan transport. Поддерживаемая целевая среда — Windows Server 2022/2025 с Windows PowerShell 5.1, CIM и NetTCPIP. Новые сборщики требуют профиль доверия (CA + hostname + TLS SHA-256 pin и MachineGuid). Настройка WinRM, сертификата, Vault и профиля — в документации 0.8.1. Не отключайте TLS verification и не используйте HTTP Basic.

## Обновление и эксплуатация

1. Обновить файлы, остановив workers; выполнить `php bin/cajeer migrate` (0055), пересобрать Admin UI и перезапустить workers.
2. Настроить профиль WinRM, привязать credential с auth=basic, проверить health. У учётной записи должны быть права на WinRM shell, локальный CIM, NetTCPIP и чтение HKLM. Не требуется ввод пароля в команду, установка агента или произвольный PowerShell.
3. Создать Inspect-планы для необходимых scope. System/services/storage/network: 300 с; software/updates: 3600 с. Базовые `winrm.inventory` Sync и `winrm.metrics` Inspect продолжают работать.
4. В «Коллекторы» открыть Windows Discovery; выбрать scope, искать и просматривать историю. `GET /connectors/targets/{id}/windows-discovery?scope=updates` возвращает состояние, время и snapshot. RBAC требует connectors.targets.read и inventory.assets.read, а target проверяется в Workspace.

## Источники и границы

- System: Win32_OperatingSystem, Win32_ComputerSystem, Win32_Processor; build, product type, модель, CPU, RAM, boot time.
- Software: обе registry views HKLM Uninstall, только DisplayName/Version/Publisher/InstallDate и ID. Нет Win32_Product/MSI repair, per-user software или Store apps.
- Services: Win32_Service, name/display name/state/start mode/status/PID; не читаются credentials, service command lines или account names.
- Storage: Win32_DiskDrive, Win32_DiskPartition, Win32_Volume (локальные fixed volumes); размеры и свободное место в байтах, диск/раздел по индексу, volume GUID и drive letter. Не собираются network drives, folder mount paths, Storage Spaces pool topology.
- Network: Win32_NetworkAdapter, Get-NetIPAddress, Get-NetRoute ActiveStore. Привязка по interface index; IPAM не изменяется.
- Updates: Win32_QuickFixEngineering (только CBS QFE, не полный Windows Update inventory); InstalledOn сохраняется как исходная строка, формат зависит от Windows. CBS/WU reboot keys и PendingFileRenameOperations дают три локальных признака, а не доказательство отсутствия необходимых обновлений. Нет WUA online scan, install или reboot. [Microsoft QFE](https://learn.microsoft.com/en-us/windows/win32/cimwin32prov/win32-quickfixengineering).

Полнота обозначает успешное завершение фиксированных проб, а не универсальную полноту inventory. Неизвестные значения null. Поля ограничены allowlist, до 10 000 строк на раздел, 750 КБ нормализованного снимка, 800 КБ транспортного результата. WinRM получает результат несколькими WSMan Receive; ошибки, stderr, timeout или identity mismatch отклоняют весь scope. Старое наблюдение остаётся доступным; ротация/изменение профиля инвалидирует текущий снимок. Сохранение выполняется в той же транзакции, что и результат job. История не перезаписывается.

## Приёмка

`php tests/windows-discovery-smoke.php` и `python3 tests/host-transport-integration.py` проверяют нормализацию и настоящий локальный HTTPS WSMan transport. Fixture проверяет точный фиксированный EncodedCommand, но не исполняет CIM на Windows. Полная приёмка требует настоящего Windows Server, действующего WinRM TLS-профиля и PostgreSQL; `python3 tests/host-transport-integration.py --postgres` запускает сценарий Vault/worker в disposable PostgreSQL (`CAJEER_PG_TEST=1`, `CAJEER_PG_TEST_DATABASE=cajeer_test_*`).
