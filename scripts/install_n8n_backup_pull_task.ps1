# install_n8n_backup_pull_task.ps1 — поставить ежедневную задачу «забрать копию n8n».
#
# Ставит задачу Планировщика Windows n8n-backup-pull, которая раз в сутки зовёт
# pull_n8n_backup.ps1. Идемпотентен: повторный запуск пересоздаёт задачу с теми
# же параметрами, ничего не задваивая.
#
# Запуск (из корня репозитория, обычные права пользователя достаточно —
# задача ставится в его собственное расписание, не в системное):
#   powershell -ExecutionPolicy Bypass -File scripts\install_n8n_backup_pull_task.ps1
#
# Снять:  Unregister-ScheduledTask -TaskName n8n-backup-pull -Confirm:$false

param(
    [string]$TaskName = 'n8n-backup-pull',
    # 04:40 по местному: заведомо ПОСЛЕ ночного дампа на .91 (03:17 UTC плюс
    # разброс таймера), чтобы забирать сегодняшний архив, а не вчерашний.
    [string]$At = '04:40',
    [string]$Destination = 'D:\Backups\n8n'
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$script = Join-Path $repoRoot 'scripts\pull_n8n_backup.ps1'
if (-not (Test-Path $script)) { throw "не найден $script" }

# Именно pwsh, а не powershell.exe. Windows PowerShell 5.1 читает .ps1 без BOM
# как ANSI и спотыкается о кириллицу ещё на разборе (замер 25-08-2026), а BOM
# запрещён линтером репозитория. Путь ищем, а не хардкодим.
$pwsh = (Get-Command pwsh -ErrorAction SilentlyContinue).Source
if (-not $pwsh) { throw 'не найден pwsh (PowerShell 7) — задача без него запускаться не будет; установите PowerShell 7 или запускайте pull_n8n_backup.ps1 вручную' }

$action = New-ScheduledTaskAction -Execute $pwsh `
    -Argument ('-NoProfile -ExecutionPolicy Bypass -File "{0}" -Destination "{1}"' -f $script, $Destination)

$trigger = New-ScheduledTaskTrigger -Daily -At $At

# StartWhenAvailable: машина рабочая, её выключают. Без этого пропущенный запуск
# просто теряется, и «ежедневная копия» тихо становится «когда повезёт».
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable `
    -DontStopIfGoingOnBatteries -AllowStartIfOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Hours 1)

Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger `
    -Settings $settings -Description 'Ежедневно забирает свежую копию данных n8n с .91 (H3182, остаток 25-08-2026)' | Out-Null

Write-Output ("задача {0} поставлена на {1} ежедневно; назначение {2}" -f $TaskName, $At, $Destination)
Get-ScheduledTask -TaskName $TaskName | Select-Object TaskName, State
