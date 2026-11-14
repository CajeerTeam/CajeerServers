$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
function V($x) { if ($null -eq $x -or [string]$x -eq '') { return $null }; return [string]$x }
function Identity { return [string](Get-ItemPropertyValue -LiteralPath 'HKLM:\SOFTWARE\Microsoft\Cryptography' -Name 'MachineGuid') }
$before = Identity
$sections = @{}
$sections.disks = @(Get-CimInstance -ClassName Win32_DiskDrive | ForEach-Object { @{id=(V $_.DeviceID); index=(V $_.Index); model=(V $_.Model); interface_type=(V $_.InterfaceType); size_bytes=(V $_.Size); status=(V $_.Status)} })
$sections.partitions = @(Get-CimInstance -ClassName Win32_DiskPartition | ForEach-Object { @{id=(V $_.DeviceID); disk_index=(V $_.DiskIndex); index=(V $_.Index); type=(V $_.Type); size_bytes=(V $_.Size); offset_bytes=(V $_.StartingOffset)} })
$sections.volumes = @(Get-CimInstance -ClassName Win32_Volume -Filter 'DriveType=3' | ForEach-Object { @{id=(V $_.DeviceID); drive_letter=(V $_.DriveLetter); label=(V $_.Label); filesystem=(V $_.FileSystem); size_bytes=(V $_.Capacity); available_bytes=(V $_.FreeSpace)} })
$after = Identity
if ($before -ne $after) { throw 'Machine identity changed' }
foreach ($rows in $sections.Values) { if ($rows.Count -gt 10000) { throw 'Collection limit' } }
$result = @{identity=$after; scope='storage'; sections=$sections}
$result | ConvertTo-Json -Depth 6 -Compress
