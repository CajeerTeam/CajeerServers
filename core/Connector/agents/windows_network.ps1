$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
function V($x) { if ($null -eq $x -or [string]$x -eq '') { return $null }; return [string]$x }
function Identity { return [string](Get-ItemPropertyValue -LiteralPath 'HKLM:\SOFTWARE\Microsoft\Cryptography' -Name 'MachineGuid') }
$before = Identity
$sections = @{}
$sections.interfaces = @(Get-CimInstance -ClassName Win32_NetworkAdapter | Where-Object { ($_.InterfaceIndex -gt 0) } | ForEach-Object { @{index=(V $_.InterfaceIndex); name=(V $_.Name); mac=(V $_.MACAddress); speed_bps=(V $_.Speed); status=(V $_.NetConnectionStatus)} })
$sections.addresses = @(Get-NetIPAddress | ForEach-Object { @{index=(V $_.InterfaceIndex); address=(V (([string]$_.IPAddress).Split('%')[0])); prefix=(V $_.PrefixLength); family=(V $_.AddressFamily); state=(V $_.AddressState)} })
$sections.routes = @(Get-NetRoute -PolicyStore ActiveStore | ForEach-Object { @{index=(V $_.InterfaceIndex); destination=(V (([string]$_.DestinationPrefix) -replace '%[^/]+','')); gateway=(V (([string]$_.NextHop).Split('%')[0])); family=(V $_.AddressFamily); metric=(V $_.RouteMetric)} })
$after = Identity
if ($before -ne $after) { throw 'Machine identity changed' }
foreach ($rows in $sections.Values) { if ($rows.Count -gt 10000) { throw 'Collection limit' } }
$result = @{identity=$after; scope='network'; sections=$sections}
$result | ConvertTo-Json -Depth 6 -Compress
