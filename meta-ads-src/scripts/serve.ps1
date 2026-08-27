# Keeps the dashboard listening on http://localhost:3100 for as long as this
# user is logged in. Registered as the scheduled task "MetaAdsDashboard" (see
# install-autostart.ps1); not meant to be run by hand, though it is harmless to.
#
# Three things this handles that a bare `npm run start` does not:
#   * a crash or an OOM kill is followed by a restart instead of a dead port
#   * a manually started `npm run dev` on 3100 is left alone, and this takes
#     over again once that one stops
#   * an orphaned server from a previous supervisor is reclaimed. Task Scheduler
#     kills this script without killing the node process it launched, so on the
#     next logon the port would otherwise be held by a stale build forever.
#
# Binds 127.0.0.1 deliberately: the dashboard holds Meta, Shopify and Anthropic
# credentials, and nothing here should answer another machine on the network.

$ErrorActionPreference = 'Stop'

$root    = 'C:\meta'
$port    = 3100
$node    = 'C:\Program Files\nodejs\node.exe'
$nextBin = Join-Path $root 'node_modules\next\dist\bin\next'
$logDir  = Join-Path $root 'logs'
$logFile = Join-Path $logDir 'dashboard.log'
$outFile = Join-Path $logDir 'next-stdout.log'
$errFile = Join-Path $logDir 'next-stderr.log'
$pidFile = Join-Path $logDir 'server.pid'

New-Item -ItemType Directory -Force -Path $logDir | Out-Null

function Write-Log([string]$message) {
    # Timestamps are local time; this log is only ever read on this machine.
    $stamp = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
    Add-Content -Path $logFile -Encoding utf8 -Value "$stamp  $message"
}

function Rotate-Log {
    # One rollover file is enough to explain a crash without growing forever.
    if ((Test-Path $logFile) -and ((Get-Item $logFile).Length -gt 5MB)) {
        Move-Item $logFile "$logFile.1" -Force
    }
}

function Drain([string]$path, [string]$label) {
    # Start-Process can only redirect to a file, and truncates it on each start,
    # so fold each run's output into the one log before the next run clobbers it.
    if (-not (Test-Path $path)) { return }
    $text = (Get-Content $path -Raw -ErrorAction SilentlyContinue)
    if ($text -and $text.Trim()) {
        Add-Content -Path $logFile -Encoding utf8 -Value "--- $label ---"
        Add-Content -Path $logFile -Encoding utf8 -Value $text.TrimEnd()
    }
}

function Test-ServerHealthy {
    # A listening socket is not the same as a working dashboard, and the
    # difference decides whether an existing server gets killed. Ask it.
    try {
        $r = Invoke-WebRequest "http://127.0.0.1:$port/" -UseBasicParsing -TimeoutSec 10
        return $r.StatusCode -eq 200
    }
    catch { return $false }
}

function Get-PortOwner {
    (Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue |
        Select-Object -First 1 -ExpandProperty OwningProcess)
}

# Only one supervisor may run. The task itself is set to IgnoreNew, but the
# repeating trigger plus a hand-run copy could otherwise produce two, and two
# supervisors fight: each sees the other's server, matches it against the shared
# pid file, kills it, and starts its own, forever.
$mutex = New-Object System.Threading.Mutex($false, 'Global\MetaAdsDashboardSupervisor')
try { $holdsLock = $mutex.WaitOne(0) }
catch [System.Threading.AbandonedMutexException] { $holdsLock = $true }
if (-not $holdsLock) {
    Write-Log "another supervisor already running; exiting (pid $PID)"
    exit 0
}

Write-Log "supervisor starting (pid $PID)"
Set-Location $root

while ($true) {
    Rotate-Log

    $owner = Get-PortOwner
    if ($owner) {
        $recorded = if (Test-Path $pidFile) { (Get-Content $pidFile -Raw).Trim() } else { '' }
        if ($recorded -and [int]$recorded -eq [int]$owner) {
            # Our own server, outliving the supervisor that started it. Only
            # replace it if it has actually stopped serving.
            #
            # Killing a healthy orphan on sight caused a real outage on
            # 2026-08-17: a supervisor reclaimed a working server at 17:48:21,
            # was itself killed before it could start the replacement, and the
            # dashboard stayed down until the next trigger five minutes later.
            # An orphan that still answers is doing its job -- leave it running
            # and watch it. stop.ps1 is how you deliberately replace one.
            if (Test-ServerHealthy) {
                Write-Log "orphaned server (pid $owner) is healthy; adopting it"
                while (Test-ServerHealthy) { Start-Sleep -Seconds 15 }
                Write-Log "adopted server (pid $owner) stopped responding"
            }
            else {
                Write-Log "replacing unhealthy orphaned server (pid $owner)"
                Stop-Process -Id $owner -Force -ErrorAction SilentlyContinue
                Start-Sleep -Seconds 2
            }
        }
        else {
            # Someone else, almost always a hand-started `npm run dev`, owns the
            # port. Yield to it and check back rather than crash-looping.
            Write-Log "port $port held by pid $owner (not ours); waiting"
            Start-Sleep -Seconds 30
        }
        continue
    }

    Write-Log "starting: next start -p $port -H 127.0.0.1"
    try {
        # -WindowStyle Hidden, NOT -NoNewWindow. Sharing this script's console
        # means a console control event (logoff, a closed host window, Ctrl+C
        # anywhere on that console) is delivered to node as well, killing the
        # server and the supervisor in the same instant with no chance to log or
        # restart. That is exactly what took the dashboard down on 2026-08-17:
        # the task recorded 0xC000013A, STATUS_CONTROL_C_EXIT. Its own console
        # insulates the server from anything aimed at this process.
        $proc = Start-Process -FilePath $node `
            -ArgumentList @($nextBin, 'start', '-p', $port, '-H', '127.0.0.1') `
            -WorkingDirectory $root -WindowStyle Hidden -PassThru `
            -RedirectStandardOutput $outFile -RedirectStandardError $errFile

        Set-Content -Path $pidFile -Encoding ascii -Value $proc.Id
        Write-Log "server pid $($proc.Id)"

        Wait-Process -Id $proc.Id
        Drain $outFile 'stdout'
        Drain $errFile 'stderr'
        Write-Log "server pid $($proc.Id) exited with code $($proc.ExitCode)"
    }
    catch {
        Write-Log "failed to launch: $_"
    }

    # A tight restart loop on a permanent failure (bad build, missing node) would
    # spin the CPU and bury the real error in log noise.
    Start-Sleep -Seconds 5
}
