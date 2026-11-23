<#
.SYNOPSIS
    Install NOCView's Sangfor collector as an always-on Windows task.

.DESCRIPTION
    Replaces the "run it again and again" workflow: the task starts at boot,
    keeps one long-running `snmp_pusher.php --loop` process, and Windows
    restarts it after a crash, a reboot or a network outage.

    Run PowerShell as Administrator:
        cd C:\xampp\htdocs\nocview\deploy
        .\install-windows-task.ps1

    Then check C:\xampp\htdocs\nocview\tmp\pusher.log and collector_status.php.

.PARAMETER PhpPath
    Path to php.exe. Default: C:\xampp\php\php.exe

.PARAMETER AppDir
    NOCView folder. Default: C:\xampp\htdocs\nocview

.PARAMETER IntervalSec
    Seconds between polls. Default: 60 (one history sample per site per minute).

.PARAMETER Uninstall
    Remove the task instead of creating it.
#>
param(
    [string]$PhpPath = 'C:\xampp\php\php.exe',
    [string]$AppDir = 'C:\xampp\htdocs\nocview',
    [int]$IntervalSec = 60,
    [switch]$Uninstall
)

$TaskName = 'NOCView Sangfor Collector'
$ErrorActionPreference = 'Stop'

if ($Uninstall) {
    if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
        Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
        Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
        Write-Host "Removed scheduled task '$TaskName'."
    } else {
        Write-Host "Task '$TaskName' is not installed."
    }
    # The loop process may have been started by the task; stop it too.
    Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -like '*snmp_pusher.php*--loop*' } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    return
}

if (-not (Test-Path $PhpPath))  { throw "php.exe not found: $PhpPath" }
if (-not (Test-Path (Join-Path $AppDir 'snmp_pusher.php'))) { throw "snmp_pusher.php not found in $AppDir" }

$logDir = Join-Path $AppDir 'tmp'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null
$logPath = Join-Path $logDir 'pusher.log'

$arguments = @(
    ('"' + (Join-Path $AppDir 'snmp_pusher.php') + '"'),
    '--loop',
    "--interval=$IntervalSec",
    ('--log="' + $logPath + '"')
) -join ' '

$action = New-ScheduledTaskAction -Execute $PhpPath -Argument $arguments -WorkingDirectory $AppDir
$triggers = @(
    New-ScheduledTaskTrigger -AtStartup
    New-ScheduledTaskTrigger -AtLogOn
)

$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -RestartCount 999 `
    -RestartInterval (New-TimeSpan -Minutes 1) `
    -ExecutionTimeLimit ([TimeSpan]::Zero)

# SYSTEM has no interactive session, so the collector keeps running with the
# console closed and after a logoff - exactly what "24/7 without babysitting" needs.
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $triggers `
    -Settings $settings -Principal $principal -Force | Out-Null

Start-ScheduledTask -TaskName $TaskName

Write-Host "Installed '$TaskName'."
Write-Host "  php      : $PhpPath"
Write-Host "  interval : ${IntervalSec}s"
Write-Host "  log      : $logPath"
Write-Host "  status   : https://your-site/collector_status.php"
Write-Host ""
Write-Host "Check it with:  Get-ScheduledTaskInfo -TaskName '$TaskName'"
