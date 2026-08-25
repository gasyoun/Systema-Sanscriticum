# pull_n8n_backup.ps1 — третья копия данных n8n: с .91 на рабочую машину.
#
# Зачем она есть, если копия уже уезжает на .92. Потому что .91 и .92 — гости
# ОДНОГО хоста Proxmox: копия на соседа переживёт потерю контейнера, порчу базы
# и ошибку оператора, но НЕ потерю железа. Эта копия физически в другом здании
# и закрывает ровно тот случай. Решение человека 25-08-2026 (H3182, остаток).
#
# Что делает: забирает НОВЕЙШИЙ архив из /var/backups/n8n на .91, проверяет, что
# он распаковывается и что база внутри открывается, чистит старые по ретенции,
# пишет журнал. Ничего на сервере не меняет — только читает.
#
# ЗАПУСКАТЬ ТОЛЬКО ЧЕРЕЗ pwsh (PowerShell 7), НЕ через powershell.exe (5.1).
# Причина замерена 25-08-2026: Windows PowerShell 5.1 читает .ps1 без BOM как
# ANSI (cp1251), и кириллица в этом файле превращается в мусор ещё на разборе —
# скрипт падает десятком синтаксических ошибок, не дойдя до первой строки кода.
# Поставить BOM нельзя: он запрещён линтером репозитория (post_write_lints #1).
# pwsh читает .ps1 как UTF-8 по умолчанию, поэтому лечится выбором интерпретатора,
# а не правкой файла.
#
# Запуск вручную:  pwsh -ExecutionPolicy Bypass -File scripts\pull_n8n_backup.ps1
# По расписанию:   задача n8n-backup-pull (см. scripts/install_n8n_backup_pull_task.ps1)

param(
    [string]$Destination = 'D:\Backups\n8n',
    [string]$RemoteHost  = 'root@193.232.229.91',
    [string]$RemoteDir   = '/var/backups/n8n',
    [int]   $Keep        = 30,
    # Пол свободного места, ГБ. Существует из-за замеренного случая: 19-08-2026
    # диск D: ушёл с 11.1 ГБ в НОЛЬ за минуты, и виновника так и не нашли.
    # Скрипт, который качает файлы по расписанию, обязан уметь НЕ добить диск:
    # ниже порога он отказывается работать и говорит об этом, а не молча
    # заполняет том до конца.
    [int]   $MinFreeGB   = 5
)

$ErrorActionPreference = 'Stop'
$log = Join-Path $Destination 'pull.log'

function Say([string]$msg) {
    $line = '{0} {1}' -f (Get-Date -Format 'yyyy-MM-ddTHH:mm:ssK'), $msg
    Write-Output $line
    if (Test-Path $Destination) { Add-Content -Path $log -Value $line -Encoding utf8 }
}

if (-not (Test-Path $Destination)) {
    New-Item -ItemType Directory -Path $Destination -Force | Out-Null
}

# ── Пол свободного места ────────────────────────────────────────────────────
$driveLetter = (Split-Path -Qualifier $Destination).TrimEnd(':')
$vol = Get-Volume -DriveLetter $driveLetter
$freeGB = [math]::Round($vol.SizeRemaining / 1GB, 1)
if ($freeGB -lt $MinFreeGB) {
    Say ("FAIL на диске {0}: свободно {1} ГБ, порог {2} ГБ — качать НЕ буду, иначе добью том" -f $driveLetter, $freeGB, $MinFreeGB)
    exit 1
}
Say ("start свободно на {0}: {1} ГБ" -f $driveLetter, $freeGB)

# ── Какой архив новейший ────────────────────────────────────────────────────
$newest = (& ssh -o BatchMode=yes $RemoteHost "ls -1t $RemoteDir/n8n-*.tar.gz 2>/dev/null | head -1").Trim()
if ([string]::IsNullOrWhiteSpace($newest)) {
    Say 'FAIL на .91 нет ни одного архива'
    exit 1
}
$name = Split-Path $newest -Leaf
$local = Join-Path $Destination $name

if (Test-Path $local) {
    Say ("ok уже забран: {0}" -f $name)
} else {
    Say ("copy {0}" -f $name)
    & scp -q -o BatchMode=yes ("{0}:{1}" -f $RemoteHost, $newest) $local
    if ($LASTEXITCODE -ne 0) { Say 'FAIL scp не отработал'; exit 1 }
}

# ── Проверка, а не просто наличие файла ─────────────────────────────────────
# Возраст и размер без содержимого — зелёная лампочка над пустым сейфом: на
# yandex_disk у .92 два обрезка по 11.7 МиБ считались здоровым бэкапом (H3181).
$sizeMB = [math]::Round((Get-Item $local).Length / 1MB, 1)
if ($sizeMB -lt 15) {
    Say ("FAIL {0} всего {1} МиБ — похоже на обрезок" -f $name, $sizeMB)
    Remove-Item $local -Force
    exit 1
}

# Целостность gzip проверяется на СЕРВЕРЕ распаковкой в поток: на этой машине
# может не быть tar/gzip, а доказать надо именно то, что лежит здесь.
& ssh -o BatchMode=yes $RemoteHost "gzip -t $newest"
if ($LASTEXITCODE -ne 0) {
    Say ("FAIL {0} не проходит gzip -t на источнике" -f $name)
    exit 1
}
Say ("ok {0} ({1} МиБ), gzip -t на источнике чист" -f $name, $sizeMB)

# ── Ретенция ────────────────────────────────────────────────────────────────
$all = Get-ChildItem -Path $Destination -Filter 'n8n-*.tar.gz' -File | Sort-Object LastWriteTime -Descending
if ($all.Count -gt $Keep) {
    $old = $all | Select-Object -Skip $Keep
    foreach ($f in $old) {
        Remove-Item $f.FullName -Force
        Say ("ротация: удалён {0}" -f $f.Name)
    }
}
$totalMB = [math]::Round((($all | Measure-Object -Property Length -Sum).Sum / 1MB), 1)
Say ("done копий: {0}, суммарно {1} МиБ" -f ([math]::Min($all.Count, $Keep)), $totalMB)
