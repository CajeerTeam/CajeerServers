$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
function Identity { return [string](Get-ItemPropertyValue -LiteralPath 'HKLM:\SOFTWARE\Microsoft\Cryptography' -Name 'MachineGuid') }
function ReadEvents($channel,$xpath,$max) {
  try { Get-WinEvent -LogName $channel -FilterXPath $xpath -Oldest -MaxEvents $max -ErrorAction Stop }
  catch { if ($_.FullyQualifiedErrorId -notlike 'NoMatchingEventsFound*') { throw } }
}
function T($event) { $event.TimeCreated.ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ss.ffffffZ') }
function Text($value,$max) { $s=[string]$value; if($s.Length -gt $max){if([char]::IsHighSurrogate($s[$max-1])){$max--}; return $s.Substring(0,$max)}; return $s }
$before=Identity
$previous=ConvertFrom-Json ([Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('__CURSOR_BASE64__')))
$cursor=@{}; $rows=@(); $more=$false
foreach($channel in @('System','Application')) {
  $anchor=$previous.$channel
  if($null -ne $anchor){
    $id=[long]$anchor.id
    if($id -lt 1){throw 'Invalid event cursor'}
    $match=@(ReadEvents $channel "*[System[EventRecordID=$id]]" 1)
    if($match.Count -ne 1 -or (T $match[0]) -ne $anchor.at){throw 'Event log anchor missing'}
    $xpath="*[System[EventRecordID > $id]]"; $cursor[$channel]=@{id=[string]$id;at=[string]$anchor.at}
  }else{$xpath='*[System[TimeCreated[timediff(@SystemTime) <= 86400000]]]'}
  $events=@(ReadEvents $channel $xpath 51)
  if($events.Count -gt 50){$more=$true}
  foreach($e in ($events | Select-Object -First 50)){
    $id=[string]$e.RecordId; $at=T $e
    $severity=switch([int]$e.Level){1{2} 2{3} 3{4} 4{6} 5{7} default{6}}
    $message='';try{$message=Text $e.Message 1024}catch{$message='Event '+[string]$e.Id}
    $rows+=@{key=($channel+':'+$id+':'+$at);channel=$channel;event_at=$at;severity=$severity;facility=$null;hostname=(Text $e.MachineName 255);application=(Text $e.ProviderName 255);message=$message}
    $cursor[$channel]=@{id=$id;at=$at}
  }
}
if((Identity) -ne $before){throw 'Identity changed'}
@{identity=$before;events=@($rows);cursor=$cursor;more=$more}|ConvertTo-Json -Depth 8 -Compress
