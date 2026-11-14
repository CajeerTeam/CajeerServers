$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
function V($x) { if ($null -eq $x -or [string]$x -eq '') { return $null }; return [string]$x }
function Identity { return [string](Get-ItemPropertyValue -LiteralPath 'HKLM:\SOFTWARE\Microsoft\Cryptography' -Name 'MachineGuid') }
$before = Identity
$sections = @{}
$sections.hotfixes = @(Get-CimInstance -ClassName Win32_QuickFixEngineering | Where-Object { $_.HotFixID } | ForEach-Object { @{id=(V $_.HotFixID); description=(V $_.Description); installed_on=(V $_.InstalledOn)} })
$base = [Microsoft.Win32.RegistryKey]::OpenBaseKey([Microsoft.Win32.RegistryHive]::LocalMachine,[Microsoft.Win32.RegistryView]::Registry64)
try {
 function Exists($path) { $k = $base.OpenSubKey($path); if ($null -eq $k) { return 'false' }; $k.Dispose(); return 'true' }
 $cbs = Exists 'SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending'
 $wu = Exists 'SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired'
 $sm = $base.OpenSubKey('SYSTEM\CurrentControlSet\Control\Session Manager')
 if ($null -eq $sm) { throw 'Session Manager unavailable' }
 try { $pending = [string]([bool]($sm.GetValue('PendingFileRenameOperations') -or $sm.GetValue('PendingFileRenameOperations2'))); $pending = $pending.ToLowerInvariant() } finally { $sm.Dispose() }
 $detected = ($cbs -eq 'true' -or $wu -eq 'true' -or $pending -eq 'true').ToString().ToLowerInvariant()
 $sections.reboot = @(@{cbs=$cbs; windows_update=$wu; pending_file_rename=$pending; detected=$detected})
} finally { $base.Dispose() }
$after = Identity
if ($before -ne $after) { throw 'Machine identity changed' }
foreach ($rows in $sections.Values) { if ($rows.Count -gt 10000) { throw 'Collection limit' } }
$result = @{identity=$after; scope='updates'; sections=$sections}
$result | ConvertTo-Json -Depth 6 -Compress
