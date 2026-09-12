$info = @{
  hostname = $env:COMPUTERNAME
  os = (Get-CimInstance Win32_OperatingSystem).Caption
}
$info | ConvertTo-Json -Compress
