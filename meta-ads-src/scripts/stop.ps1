# Stops the dashboard properly.
#
# `Stop-ScheduledTask` alone is not enough: it kills serve.ps1 and leaves the
# node process it launched still holding port 3100. This stops both.
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File C:\meta\scripts\stop.ps1
#
# Start it again with: Start-ScheduledTask -TaskName MetaAdsDashboard

$ErrorActionPreference = 'Stop'

$port    = 3100
$pidFile = 'C:\meta\logs\server.pid'

try { Stop-ScheduledTask -TaskName MetaAdsDashboard; Write-Output 'Stopped task MetaAdsDashboard.' }
catch { Write-Output "Task not running or not registered: $($_.Exception.Message)" }

Start-Sleep -Seconds 1

$owner = (Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue |
    Select-Object -First 1 -ExpandProperty OwningProcess)

if (-not $owner) {
    Write-Output "Port $port is free."
    return
}

# Only kill what we started. A hand-run `npm run dev` on this port is the
# user's, and silently killing their terminal would be rude.
$recorded = if (Test-Path $pidFile) { (Get-Content $pidFile -Raw).Trim() } else { '' }
if ($recorded -and [int]$recorded -eq [int]$owner) {
    Stop-Process -Id $owner -Force
    Write-Output "Stopped server pid $owner; port $port released."
}
else {
    Write-Output "Port $port is held by pid $owner, which this script did not start. Left alone."
}
