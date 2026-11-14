$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
function V($x) { if ($null -eq $x -or [string]$x -eq '') { return $null }; return [string]$x }
function Identity { return [string](Get-ItemPropertyValue -LiteralPath 'HKLM:\SOFTWARE\Microsoft\Cryptography' -Name 'MachineGuid') }
$before = Identity
$sections = @{}
$sections.services = @(Get-CimInstance -ClassName Win32_Service | ForEach-Object { @{name=(V $_.Name); display_name=(V $_.DisplayName); state=(V $_.State); start_mode=(V $_.StartMode); status=(V $_.Status); process_id=(V $_.ProcessId)} })
$after = Identity
if ($before -ne $after) { throw 'Machine identity changed' }
foreach ($rows in $sections.Values) { if ($rows.Count -gt 10000) { throw 'Collection limit' } }
$result = @{identity=$after; scope='services'; sections=$sections}
$result | ConvertTo-Json -Depth 6 -Compress
