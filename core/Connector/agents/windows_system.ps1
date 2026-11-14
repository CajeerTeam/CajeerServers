$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
function V($x) { if ($null -eq $x -or [string]$x -eq '') { return $null }; return [string]$x }
function Identity { return [string](Get-ItemPropertyValue -LiteralPath 'HKLM:\SOFTWARE\Microsoft\Cryptography' -Name 'MachineGuid') }
$before = Identity
$sections = @{}
$os = Get-CimInstance -ClassName Win32_OperatingSystem
$cs = Get-CimInstance -ClassName Win32_ComputerSystem
$available = $null
if ($null -ne $os.FreePhysicalMemory) { $available = [uint64]$os.FreePhysicalMemory * 1024 }
$sections.system = @(@{hostname=(V $cs.Name); os_name=(V $os.Caption); os_version=(V $os.Version); build=(V $os.BuildNumber); architecture=(V $os.OSArchitecture); product_type=(V $os.ProductType); manufacturer=(V $cs.Manufacturer); model=(V $cs.Model); cpu_count=(V $cs.NumberOfLogicalProcessors); memory_bytes=(V $cs.TotalPhysicalMemory); memory_available_bytes=(V $available); boot_time=$os.LastBootUpTime.ToUniversalTime().ToString('o')})
$sections.processors = @(Get-CimInstance -ClassName Win32_Processor | ForEach-Object { @{id=(V $_.DeviceID); model=(V $_.Name); cores=(V $_.NumberOfCores); logical_processors=(V $_.NumberOfLogicalProcessors); max_clock_mhz=(V $_.MaxClockSpeed)} })
$after = Identity
if ($before -ne $after) { throw 'Machine identity changed' }
foreach ($rows in $sections.Values) { if ($rows.Count -gt 10000) { throw 'Collection limit' } }
$result = @{identity=$after; scope='system'; sections=$sections}
$result | ConvertTo-Json -Depth 6 -Compress
