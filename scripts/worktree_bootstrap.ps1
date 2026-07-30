<#
.SYNOPSIS
    Поднять свежий git-worktree этого репозитория к рабочему состоянию за секунды,
    а не за одиннадцать минут — и корректно снести его после.

.DESCRIPTION
    Проблема, ради которой это написано (H1929, 30-07-2026). Каждый handoff идёт в
    новом worktree (того требует защита главного дерева), а в новом worktree нет ни
    vendor/, ни .env. Полный `composer install` занимал ~11 минут wall-clock на
    правку, которая сама по себе стоила сорока. Junction на чужой vendor/ ЗАПРЕЩЁН
    (см. CLAUDE.md: PHP резолвит __DIR__ через NTFS-junction в физическую цель, и
    worktree начинает молча исполнять КОД ЧУЖОГО ДЕРЕВА — зелёный тест тогда не
    доказывает ничего). Но ФИЗИЧЕСКАЯ копия этой болезнью не страдает: пути в
    vendor/composer/autoload_*.php считаются от __DIR__ в момент выполнения, и копия
    честно указывает на свой worktree. robocopy делает эту копию примерно за минуту.

    Копия берётся ТОЛЬКО при совпадении composer.lock главного дерева с нашим —
    иначе набор пакетов разъехался бы, и это ровно тот класс тихой лжи, который
    запрет junction'ов и предотвращает. При расхождении честно зовём composer.

.PARAMETER Teardown
    Обратный ход: снести ЭТОТ worktree из главного дерева, довести дело до конца при
    отказе Windows (частичное удаление оставляет каталог без .git, git его уже не
    видит) и проверить результат.

.EXAMPLE
    pwsh -File scripts/worktree_bootstrap.ps1
.EXAMPLE
    pwsh -File scripts/worktree_bootstrap.ps1 -Teardown
#>
[CmdletBinding()]
param(
    [switch] $Teardown
)

$ErrorActionPreference = 'Stop'

$here = Split-Path -Parent $PSScriptRoot
$mainClone = (git -C $here worktree list --porcelain | Select-Object -First 1) -replace '^worktree\s+', ''
$mainClone = $mainClone -replace '/', '\'

function Say([string] $text) { Write-Host "▶ $text" -ForegroundColor Cyan }
function Ok([string] $text) { Write-Host "  ok      $text" -ForegroundColor Green }
function Warn([string] $text) { Write-Host "  warn    $text" -ForegroundColor Yellow }
function Die([string] $text) { Write-Host "✖ $text" -ForegroundColor Red; exit 1 }

# ── Teardown ────────────────────────────────────────────────────────────────
if ($Teardown) {
    if ($here -ieq $mainClone) { Die "это и есть главное дерево — сносить нечего" }

    # Самая частая причина «Permission denied» на Windows — не чужой процесс, а МЫ САМИ:
    # каталог, который является текущим для процесса, удалить нельзя. Проверяем это
    # первым и говорим прямо, вместо «закройте что-нибудь» вслепую.
    $cwd = (Get-Location).Path
    if ($cwd -ieq $here -or $cwd.StartsWith("$here\", [StringComparison]::OrdinalIgnoreCase)) {
        Die @"
запуск ИЗНУТРИ сносимого worktree ($cwd) — Windows не удалит каталог, пока он текущий.
Из главного дерева:
    cd $mainClone
    pwsh -File $here\scripts\worktree_bootstrap.ps1 -Teardown
"@
    }

    Say "Снос worktree $here"
    Push-Location $mainClone
    git worktree remove $here --force 2>&1 | Out-Null
    git worktree prune
    if (Test-Path $here) {
        # Windows держит хэндл под worktree чаще, чем хотелось бы: git успевает
        # снять регистрацию, но каталог остаётся — и висит потом неделями.
        # Хэндл нередко отпускается через мгновение после того, как git закончил,
        # поэтому одна неудачная попытка — ещё не приговор: пробуем трижды.
        Warn "git не смог удалить каталог — добиваю вручную"
        $delay = 300
        foreach ($attempt in 1..5) {
            Remove-Item -Recurse -Force $here -ErrorAction SilentlyContinue
            if (-not (Test-Path $here)) { break }
            Start-Sleep -Milliseconds $delay
            $delay *= 2
        }
    }
    Pop-Location
    if (Test-Path $here) {
        # Пустой незарегистрированный каталог — это УЖЕ успех, а не провал: работы
        # в нём не осталось, git о нём не знает, следующий Remove-Item его снесёт.
        # Измерено 30-07-2026: именно так выглядели оба живых прогона — каталог
        # исчезал сам через секунду после того, как скрипт объявлял неудачу.
        # Ложная тревога здесь дороже настоящей: она учит не верить отчёту.
        $left = @(Get-ChildItem $here -Force -ErrorAction SilentlyContinue)
        if ($left.Count -eq 0) {
            Warn "каталог опустел, но Windows ещё держит саму папку — работы в ней нет, git о ней не знает; исчезнет сама или снесите `Remove-Item -Recurse -Force $here`"
            Ok "worktree удалён и разрегистрирован"
            exit 0
        }
        Die "в каталоге остались файлы ($($left.Count) шт.) и удалить их не вышло — процесс держит что-то под $here (проводник, редактор, php, запущенный тест). Закройте его и повторите; регистрация в git уже снята."
    }
    Ok "worktree удалён и разрегистрирован"
    exit 0
}

# ── 1. vendor/ ──────────────────────────────────────────────────────────────
Say "vendor/"
$vendor = Join-Path $here 'vendor'
$existing = Get-Item $vendor -ErrorAction SilentlyContinue
if ($existing -and $existing.LinkType) {
    Die "vendor/ здесь — $($existing.LinkType) на $($existing.Target). Это запрещено (CLAUDE.md): worktree будет исполнять чужой код. Удалите (cmd /c rmdir vendor) и запустите скрипт заново."
}

if (Test-Path (Join-Path $vendor 'autoload.php')) {
    Ok "уже на месте"
}
else {
    $mainVendor = Join-Path $mainClone 'vendor'
    $lockHere = (Get-FileHash (Join-Path $here 'composer.lock') -Algorithm SHA256).Hash
    $mainLockPath = Join-Path $mainClone 'composer.lock'
    $lockMain = if (Test-Path $mainLockPath) { (Get-FileHash $mainLockPath -Algorithm SHA256).Hash } else { '' }

    if ((Test-Path (Join-Path $mainVendor 'autoload.php')) -and $lockHere -eq $lockMain) {
        Say "composer.lock совпадает с главным деревом — копирую vendor/ физически (НЕ junction)"
        # /MT — многопоточно, /NFL /NDL /NJH /NJS — без простыней в выводе.
        robocopy $mainVendor $vendor /E /MT:16 /NFL /NDL /NJH /NJS /R:1 /W:1 | Out-Null
        if ($LASTEXITCODE -ge 8) { Die "robocopy вернул $LASTEXITCODE" }
        $global:LASTEXITCODE = 0
        Ok "vendor/ скопирован из $mainClone"
    }
    else {
        Warn "composer.lock разошёлся с главным деревом (или там нет vendor/) — честный composer install"
        Push-Location $here
        composer install --no-interaction --no-progress
        Pop-Location
        Ok "composer install"
    }
}

# Автозагрузчик обязан указывать на ЭТОТ worktree, а не на донора.
Push-Location $here
# Вывод обрамляем маркерами: автозагрузка тянет пакеты, которые печатают в stdout
# свои баннеры (MadelineProto — про производительность на Windows), и без маркеров
# путь приезжает склеенным с чужим текстом, давая ложную тревогу.
$probe = (& php -r "require 'vendor/autoload.php'; echo '<<<'.realpath('app').'>>>';" 2>$null) -join ''
Pop-Location
$appPath = if ($probe -match '<<<(.+?)>>>') { $Matches[1] } else { '' }
if ($appPath -and ($appPath -notlike "$here*")) {
    Die "автозагрузчик резолвится в $appPath — это НЕ этот worktree. Так выглядит junction-ловушка; удалите vendor/ и переустановите."
}
Ok "автозагрузчик указывает на этот worktree"

# ── 2. .env ─────────────────────────────────────────────────────────────────
Say ".env"
$envPath = Join-Path $here '.env'
if (-not (Test-Path $envPath)) {
    $mainEnv = Join-Path $mainClone '.env'
    if (Test-Path $mainEnv) { Copy-Item $mainEnv $envPath; Ok ".env взят из главного дерева" }
    else { Copy-Item (Join-Path $here '.env.example') $envPath; Ok ".env из .env.example" }
}
else { Ok "уже на месте" }

Push-Location $here
if (-not (Select-String -Path $envPath -Pattern '^APP_KEY=.+' -Quiet)) {
    php artisan key:generate --ansi | Out-Null
    Ok "APP_KEY сгенерирован"
}
Pop-Location

# ── 3. Как гонять тесты, чтобы не потерять час ──────────────────────────────
Say "Готово"
Write-Host @"
  Ритм тестов (H1929 — полный набор идёт ~11 минут, не гоняйте его на каждой правке):
    php artisan test --filter=<ВашНабор>     # на итерации, секунды
    php artisan test                          # ОДИН раз в конце, лучше в фоне
    .\vendor\bin\pint --test                  # быстро, можно всегда

  Снести этот worktree, когда PR влит:
    pwsh -File scripts/worktree_bootstrap.ps1 -Teardown
"@ -ForegroundColor DarkGray
