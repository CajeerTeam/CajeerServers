$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$os = Get-CimInstance -ClassName Win32_OperatingSystem
$cs = Get-CimInstance -ClassName Win32_ComputerSystem
$disks = @(Get-CimInstance -ClassName Win32_DiskDrive | ForEach-Object { @{ name = [string]$_.DeviceID; size_bytes = [long]$_.Size } })
$interfaces = @(Get-CimInstance -ClassName Win32_NetworkAdapterConfiguration -Filter 'IPEnabled=True' | ForEach-Object { @{ name = [string]$_.SettingID; addresses = @($_.IPAddress | ForEach-Object { ([string]$_).Split('%')[0] }) } })
$result = @{
 identity = [string](Get-ItemPropertyValue -LiteralPath 'HKLM:\SOFTWARE\Microsoft\Cryptography' -Name 'MachineGuid')
 name = [string]$cs.Name
 os_family = 'windows'
 os_name = [string]$os.Caption
 os_version = [string]$os.Version
 cpu_count = [int]$cs.NumberOfLogicalProcessors
 memory_bytes = [long]$cs.TotalPhysicalMemory
 disks = $disks
 interfaces = $interfaces
}
$result | ConvertTo-Json -Depth 5 -Compress
