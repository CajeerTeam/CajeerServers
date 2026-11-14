$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
function V($x) { if ($null -eq $x -or [string]$x -eq '') { return $null }; return [string]$x }
function Identity { return [string](Get-ItemPropertyValue -LiteralPath 'HKLM:\SOFTWARE\Microsoft\Cryptography' -Name 'MachineGuid') }
$before = Identity
$sections = @{}
$apps = New-Object System.Collections.Generic.List[object]
$views = @([Microsoft.Win32.RegistryView]::Registry32)
if ([Environment]::Is64BitOperatingSystem) { $views += [Microsoft.Win32.RegistryView]::Registry64 }
foreach ($view in $views) {
 $base = [Microsoft.Win32.RegistryKey]::OpenBaseKey([Microsoft.Win32.RegistryHive]::LocalMachine,$view)
 try {
  $key = $base.OpenSubKey('SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall')
  if ($null -eq $key) { continue }
  try {
   foreach ($id in $key.GetSubKeyNames()) {
    $app = $key.OpenSubKey($id)
    if ($null -eq $app) { throw 'Registry changed during collection' }
    try {
     $name = V ($app.GetValue('DisplayName',$null,[Microsoft.Win32.RegistryValueOptions]::DoNotExpandEnvironmentNames))
     if ($name) { $apps.Add(@{id=$id; registry_view=[string]$view; name=$name; version=(V ($app.GetValue('DisplayVersion'))); publisher=(V ($app.GetValue('Publisher'))); install_date=(V ($app.GetValue('InstallDate')))}) }
    } finally { $app.Dispose() }
    if ($apps.Count -gt 10000) { throw 'Collection limit' }
   }
  } finally { $key.Dispose() }
 } finally { $base.Dispose() }
}
$sections.applications = @($apps.ToArray())
$after = Identity
if ($before -ne $after) { throw 'Machine identity changed' }
foreach ($rows in $sections.Values) { if ($rows.Count -gt 10000) { throw 'Collection limit' } }
$result = @{identity=$after; scope='software'; sections=$sections}
$result | ConvertTo-Json -Depth 6 -Compress
