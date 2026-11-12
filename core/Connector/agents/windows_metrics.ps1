# Fixed read-only CIM collector (Windows PowerShell 5.1).
$ErrorActionPreference = 'Stop'
$os = Get-CimInstance Win32_OperatingSystem
$cpu = Get-CimInstance Win32_PerfFormattedData_PerfOS_Processor -Filter "Name='_Total'"
$disk = Get-CimInstance Win32_LogicalDisk -Filter "DeviceID='$($os.SystemDrive)'"
$net = @(Get-CimInstance Win32_PerfRawData_Tcpip_NetworkInterface | ForEach-Object { @{name=[string]$_.Name; rx=([string]$_.BytesReceivedPersec); tx=([string]$_.BytesSentPersec)} })
@{identity=[string](Get-ItemPropertyValue -LiteralPath 'HKLM:\SOFTWARE\Microsoft\Cryptography' -Name 'MachineGuid'); cpu_ratio=([double]$cpu.PercentProcessorTime/100); memory_total=([long]$os.TotalVisibleMemorySize*1024); memory_available=([long]$os.FreePhysicalMemory*1024); disk_total=[long]$disk.Size; disk_available=[long]$disk.FreeSpace; network=$net} | ConvertTo-Json -Depth 5 -Compress
