# Registers serve.ps1 as a per-user scheduled task so the dashboard is already
# running at http://localhost:3100 whenever you sit down at this machine.
#
# Run once:   powershell -NoProfile -ExecutionPolicy Bypass -File C:\meta\scripts\install-autostart.ps1
# Re-running is safe: it replaces the existing task.
# Remove it:  Unregister-ScheduledTask -TaskName MetaAdsDashboard -Confirm:$false

$ErrorActionPreference = 'Stop'

$taskName = 'MetaAdsDashboard'
$script   = 'C:\meta\scripts\serve.ps1'
$user     = "$env:USERDOMAIN\$env:USERNAME"

if (-not (Test-Path $script)) { throw "missing $script" }

$action = New-ScheduledTaskAction `
    -Execute 'powershell.exe' `
    -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$script`"" `
    -WorkingDirectory 'C:\meta'

# Two triggers. Logon covers the normal case; the repeating one is what makes
# the dashboard actually dependable, because it re-runs every 5 minutes forever.
# Anything that kills the whole process tree -- a console control event, a forced
# kill, an OOM that takes the supervisor with it -- is recovered within five
# minutes instead of waiting for the next sign-in. Re-running while it is already
# up is a no-op: the task is IgnoreNew and serve.ps1 holds a named mutex.
$atLogon = New-ScheduledTaskTrigger -AtLogOn -User $user
$repeat = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval (New-TimeSpan -Minutes 5) `
    -RepetitionDuration (New-TimeSpan -Days 1)

# "Repeat indefinitely" is an absent Duration in the task XML. PowerShell 5.1
# will not build that directly -- -RepetitionInterval demands a duration, and
# [TimeSpan]::MaxValue serializes to P99999999DT23H59M59S, which the scheduler
# rejects outright -- so set a valid one above and clear it here.
$repeat.Repetition.Duration = ''

$trigger = @($atLogon, $repeat)

# ExecutionTimeLimit 0 means "never time out". The default kills long-running
# tasks after three days, which would silently take the dashboard down.
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit ([TimeSpan]::Zero) `
    -RestartCount 3 `
    -RestartInterval (New-TimeSpan -Minutes 1)

$principal = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger `
    -Settings $settings -Principal $principal `
    -Description 'Keeps the read-only Meta ads dashboard serving on localhost:3100.' `
    -Force | Out-Null

Start-ScheduledTask -TaskName $taskName
Write-Output "Registered and started scheduled task '$taskName'."
