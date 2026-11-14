# Fixed read-only CIM collector (Windows PowerShell 5.1).
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
function Identity { return [string](Get-ItemPropertyValue -LiteralPath 'HKLM:\SOFTWARE\Microsoft\Cryptography' -Name 'MachineGuid') }
$before = Identity
$os = Get-CimInstance Win32_OperatingSystem
$cpu = Get-CimInstance Win32_PerfFormattedData_PerfOS_Processor -Filter "Name='_Total'"
$disk = Get-CimInstance Win32_LogicalDisk -Filter "DeviceID='$($os.SystemDrive)'"
# Casting a missing CIM field to an integer would report an invented zero.
if ($null -eq $cpu.PercentProcessorTime -or $null -eq $os.TotalVisibleMemorySize -or $null -eq $os.FreePhysicalMemory -or $null -eq $disk.Size -or $null -eq $disk.FreeSpace -or $os.TotalVisibleMemorySize -le 0 -or $disk.Size -le 0) { throw 'Required metric unavailable' }
$net = @(Get-CimInstance Win32_PerfRawData_Tcpip_NetworkInterface | ForEach-Object {
    if ($null -eq $_.BytesReceivedPersec -or $null -eq $_.BytesSentPersec -or [string]::IsNullOrEmpty($_.Name)) { throw 'Network counter unavailable' }
    @{name=[string]$_.Name; rx=([string]$_.BytesReceivedPersec); tx=([string]$_.BytesSentPersec)}
})
if ((Identity) -ne $before) { throw 'Identity changed' }
@{identity=$before; cpu_ratio=([double]$cpu.PercentProcessorTime/100); memory_total=([long]$os.TotalVisibleMemorySize*1024); memory_available=([long]$os.FreePhysicalMemory*1024); disk_total=[long]$disk.Size; disk_available=[long]$disk.FreeSpace; network=$net} | ConvertTo-Json -Depth 5 -Compress
