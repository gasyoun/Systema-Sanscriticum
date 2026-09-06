<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Roles;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Компрометация-сторож (H4194, SLI-3 census S3): admin/capability-user count
 * + webroot .php inventory, каждый против baseline-файла.
 *
 * Motive: ни guards:verify, ни cabinet:probe не считают админов и не смотрят
 * на файлы в вебруте. C1 (15+1 rogue admins) и C2/C3 (дропперы,
 * `galex_patch.php`) прошли мимо всех HTTP-200 мониторов — компрометация
 * этого класса не трогает ни одну наблюдаемую поверхность.
 *
 * Baseline пишется ПЕРВЫМ прогоном (пустой файл = «ещё не видели машину»,
 * а не «ноль админов ожидается») и после этого read-only для самой пробы —
 * обновление baseline осознанный человеческий шаг (--write-baseline), иначе
 * атакующий, добавивший админа ДО первого прогона после компрометации,
 * тихо переписал бы себе новую норму.
 */
class CheckCompromiseIntegrity extends Command
{
    protected $signature = 'guards:compromise-integrity
        {--dry : Только показать вердикт, без уведомлений и без записи baseline}
        {--write-baseline : Осознанно принять текущее состояние как новую норму}';

    protected $description = 'Рост числа админов / новые .php в вебруте против baseline — компрометация вне HTTP-поверхностей';

    public function handle(): int
    {
        $alerts = [];

        $alerts = array_merge($alerts, $this->checkAdminCount());
        $alerts = array_merge($alerts, $this->checkWebrootPhpInventory());

        if ($alerts === []) {
            $this->info('Компрометация не обнаружена: baseline держится.');

            return self::SUCCESS;
        }

        $this->warn('Расхождение с baseline:');
        foreach ($alerts as $alert) {
            $this->line('  • '.$alert);
        }

        if ($this->option('dry')) {
            $this->comment('--dry: уведомления не отправлены.');

            return self::SUCCESS;
        }

        $this->notifyAdmins('Возможная компрометация сервера', implode(' ', $alerts));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function checkAdminCount(): array
    {
        $path = (string) config('guard_pack.admin_baseline_path');
        $count = User::query()->whereIn('role', [Roles::SUPER_ADMIN, Roles::ADMIN])->count();

        if (! File::exists($path)) {
            $this->writeBaseline($path, ['admin_count' => $count, 'written_at' => now()->toIso8601String()]);
            $this->comment("baseline записан впервые: {$count} админ(ов) — {$path}");

            return [];
        }

        $baseline = json_decode((string) File::get($path), true);
        $baselineCount = is_array($baseline) ? (int) ($baseline['admin_count'] ?? 0) : 0;

        $this->line("Админов сейчас: {$count} (baseline: {$baselineCount}).");

        if ($this->option('write-baseline')) {
            $this->writeBaseline($path, ['admin_count' => $count, 'written_at' => now()->toIso8601String()]);
            $this->info("baseline обновлён: {$count} админ(ов).");

            return [];
        }

        if ($count > $baselineCount) {
            return ["admin-count: было {$baselineCount}, стало {$count} — новый(е) админ(ы) вне baseline ({$path})"];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function checkWebrootPhpInventory(): array
    {
        $path = (string) config('guard_pack.webroot_php_baseline_path');
        $dir = (string) config('guard_pack.webroot_scan_dir');

        if (! File::isDirectory($dir)) {
            $this->comment("webroot-каталог {$dir} не найден — проверка пропущена.");

            return [];
        }

        $current = $this->scanPhpFiles($dir);

        if (! File::exists($path)) {
            $this->writeBaseline($path, ['files' => $current, 'written_at' => now()->toIso8601String()]);
            $this->comment('webroot php-baseline записан впервые: '.count($current).' файл(ов) — '.$path);

            return [];
        }

        $baseline = json_decode((string) File::get($path), true);
        $baselineFiles = is_array($baseline) && isset($baseline['files']) ? (array) $baseline['files'] : [];

        if ($this->option('write-baseline')) {
            $this->writeBaseline($path, ['files' => $current, 'written_at' => now()->toIso8601String()]);
            $this->info('webroot php-baseline обновлён: '.count($current).' файл(ов).');

            return [];
        }

        $new = array_values(array_diff($current, $baselineFiles));
        if ($new === []) {
            return [];
        }

        $sample = array_slice($new, 0, 5);
        $more = count($new) > 5 ? ' …+'.(count($new) - 5) : '';

        return ['webroot-php: '.count($new).' новый(е) .php вне baseline: '.implode(', ', $sample).$more];
    }

    /**
     * @return list<string>
     */
    private function scanPhpFiles(string $dir): array
    {
        $files = [];
        foreach (File::allFiles($dir) as $file) {
            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $files[] = str_replace('\\', '/', $file->getRelativePathname());
        }
        sort($files);

        return $files;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeBaseline(string $path, array $data): void
    {
        if ($this->option('dry')) {
            return;
        }
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function notifyAdmins(string $title, string $body): void
    {
        $recipients = User::query()
            ->whereIn('role', [Roles::SUPER_ADMIN, Roles::ADMIN])
            ->get();

        if ($recipients->isEmpty()) {
            $this->error('Нет получателей с ролью админа — уведомление не отправлено.');

            return;
        }

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title($title)
                ->danger()
                ->body($body)
                ->sendToDatabase($recipient);
        }

        $this->info('Уведомление отправлено получателям: '.$recipients->count());
    }
}
