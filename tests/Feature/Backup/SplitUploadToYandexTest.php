<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Listeners\Backup\SplitUploadToYandex;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Events\BackupWasSuccessful;
use Tests\TestCase;

class SplitUploadToYandexTest extends TestCase
{
    private const NAME = 'TestApp';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'backup.backup.name' => self::NAME,
            'backup.backup.split_upload.disk' => 'yandex_disk',
            // 1 МиБ — мелкие части, чтобы тест гонял быстро.
            'backup.backup.split_upload.max_part_mb' => 1,
            'backup.backup.split_upload.keep_parts_days' => 16,
            // Свежепроцессная верификация в тестах выключена: subprocess не
            // видит Storage::fake. Сама команда покрыта VerifyYandexPartTest.
            'backup.backup.split_upload.verify' => false,
        ]);
        Storage::fake('local');
        Storage::fake('yandex_disk');
    }

    private function seedLocalArchive(string $timestamp, int $bytes): string
    {
        $path = self::NAME.'/'.$timestamp.'.zip';
        Storage::disk('local')->put($path, str_repeat('A', $bytes));

        return $path;
    }

    public function test_big_archive_is_split_into_exact_parts(): void
    {
        $this->seedLocalArchive('2026-08-22-17-09-58', 2 * 1024 * 1024 + 500);

        (new SplitUploadToYandex)->handle(new BackupWasSuccessful('local', self::NAME));

        $target = Storage::disk('yandex_disk');
        $base = self::NAME.'/2026-08-22-17-09-58';
        $this->assertTrue($target->exists($base.'.part-01-of-03.zip'));
        $this->assertTrue($target->exists($base.'.part-02-of-03.zip'));
        $this->assertTrue($target->exists($base.'.part-03-of-03.zip'));
        $this->assertSame(1024 * 1024, $target->size($base.'.part-01-of-03.zip'));
        $this->assertSame(1024 * 1024, $target->size($base.'.part-02-of-03.zip'));
        $this->assertSame(500, $target->size($base.'.part-03-of-03.zip'));
    }

    public function test_concatenated_parts_reproduce_original_bytes(): void
    {
        $original = random_bytes(2 * 1024 * 1024 + 123);
        Storage::disk('local')->put(self::NAME.'/2026-08-22-17-09-58.zip', $original);

        (new SplitUploadToYandex)->handle(new BackupWasSuccessful('local', self::NAME));

        $joined = '';
        foreach ([1, 2, 3] as $i) {
            $joined .= Storage::disk('yandex_disk')->get(
                self::NAME."/2026-08-22-17-09-58.part-0{$i}-of-03.zip"
            );
        }
        $this->assertSame($original, $joined, 'Склейка частей обязана дать байт-в-байт исходный архив.');
    }

    public function test_small_archive_uploads_whole_under_original_name(): void
    {
        $this->seedLocalArchive('2026-08-22-17-09-58', 100);

        (new SplitUploadToYandex)->handle(new BackupWasSuccessful('local', self::NAME));

        $target = Storage::disk('yandex_disk');
        $this->assertTrue($target->exists(self::NAME.'/2026-08-22-17-09-58.zip'));
        $this->assertSame(100, $target->size(self::NAME.'/2026-08-22-17-09-58.zip'));
        $this->assertCount(0, array_filter(
            $target->allFiles(self::NAME),
            fn (string $f) => str_contains($f, '.part-')
        ), 'Малый архив не должен дробиться на части.');
    }

    public function test_rerun_is_idempotent_and_does_not_duplicate(): void
    {
        $this->seedLocalArchive('2026-08-22-17-09-58', 2 * 1024 * 1024 + 500);

        $listener = new SplitUploadToYandex;
        $listener->handle(new BackupWasSuccessful('local', self::NAME));
        $listener->handle(new BackupWasSuccessful('local', self::NAME));

        $parts = array_values(array_filter(
            Storage::disk('yandex_disk')->allFiles(self::NAME),
            fn (string $f) => str_contains($f, '.part-')
        ));
        $this->assertCount(3, $parts, 'Повторный прогон не должен плодить дубли частей.');
    }

    public function test_stale_group_is_pruned_but_recent_foreign_group_survives(): void
    {
        $stale = Carbon::now()->subDays(30)->format('Y-m-d-H-i-s');
        $recent = Carbon::now()->subDays(2)->format('Y-m-d-H-i-s');
        $target = Storage::disk('yandex_disk');
        // Легаси-обрезок с полным именем — тоже группа под чистку.
        $target->put(self::NAME."/{$stale}.zip", 'stub');
        $target->put(self::NAME."/{$recent}.part-01-of-02.zip", 'x');

        $this->seedLocalArchive('2026-08-22-17-09-58', 100);
        (new SplitUploadToYandex)->handle(new BackupWasSuccessful('local', self::NAME));

        $this->assertFalse($target->exists(self::NAME."/{$stale}.zip"), 'Группа старше keep_parts_days удаляется целиком.');
        $this->assertTrue($target->exists(self::NAME."/{$recent}.part-01-of-02.zip"), 'Свежая чужая группа не трогается.');
    }

    public function test_broken_target_disk_does_not_break_local_backup_flow(): void
    {
        config(['backup.backup.split_upload.disk' => 'missing_disk']);
        $this->seedLocalArchive('2026-08-22-17-09-58', 100);

        (new SplitUploadToYandex)->handle(new BackupWasSuccessful('local', self::NAME));

        $this->assertTrue(
            Storage::disk('local')->exists(self::NAME.'/2026-08-22-17-09-58.zip'),
            'Сбой off-site ноги не должен портить локальную копию.'
        );
    }

    public function test_resume_completes_partial_group_from_previous_run(): void
    {
        // Старый архив A: обрыв прошлым прогоном оставил на диске части 01+02.
        $originalA = random_bytes(2 * 1024 * 1024 + 500);
        $stemA = '2026-08-22-17-09-58';
        Storage::disk('local')->put(self::NAME."/{$stemA}.zip", $originalA);
        $target = Storage::disk('yandex_disk');
        $target->put(self::NAME."/{$stemA}.part-01-of-03.zip", substr($originalA, 0, 1024 * 1024));
        $target->put(self::NAME."/{$stemA}.part-02-of-03.zip", substr($originalA, 1024 * 1024, 1024 * 1024));

        // Новейший локальный архив B — основной ствол этого прогона.
        $this->seedLocalArchive('2026-08-23-10-00-00', 100);

        (new SplitUploadToYandex)->handle(new BackupWasSuccessful('local', self::NAME));

        $tail = $target->get(self::NAME."/{$stemA}.part-03-of-03.zip");
        $this->assertSame(substr($originalA, 2 * 1024 * 1024), $tail, 'Докатка обязана долить точный хвост архива.');
        $this->assertSame(
            $originalA,
            $target->get(self::NAME."/{$stemA}.part-01-of-03.zip")
                .$target->get(self::NAME."/{$stemA}.part-02-of-03.zip")
                .(string) $tail,
            'Склейка докачанной группы обязана дать байт-в-байт исходный архив.'
        );
        $this->assertTrue($target->exists(self::NAME.'/2026-08-23-10-00-00.zip'), 'Основной ствол прогона не должен пострадать от докатки.');
    }

    public function test_resume_skips_group_whose_local_archive_is_gone(): void
    {
        // Неполная группа без локального архива — недокатываема: живёт до
        // retention-чистки, трогать её нельзя.
        $target = Storage::disk('yandex_disk');
        $target->put(self::NAME.'/2026-08-19-00-00-00.part-01-of-02.zip', 'x');

        $this->seedLocalArchive('2026-08-22-17-09-58', 100);
        (new SplitUploadToYandex)->handle(new BackupWasSuccessful('local', self::NAME));

        $this->assertTrue($target->exists(self::NAME.'/2026-08-19-00-00-00.part-01-of-02.zip'));
        $this->assertFalse($target->exists(self::NAME.'/2026-08-19-00-00-00.part-02-of-02.zip'));
    }

    public function test_resume_skips_group_laid_out_by_other_part_size(): void
    {
        // Группа из другого конфига (total=2 при текущем раскладе в 4 части) —
        // не докатывается: байты не сойдутся, мусор доживает до retention.
        $target = Storage::disk('yandex_disk');
        $target->put(self::NAME.'/2026-08-21-00-00-00.part-01-of-02.zip', 'x');

        Storage::disk('local')->put(self::NAME.'/2026-08-21-00-00-00.zip', str_repeat('A', 3 * 1024 * 1024 + 500));
        $this->seedLocalArchive('2026-08-22-17-09-58', 100);

        (new SplitUploadToYandex)->handle(new BackupWasSuccessful('local', self::NAME));

        $this->assertSame('x', $target->get(self::NAME.'/2026-08-21-00-00-00.part-01-of-02.zip'));
        $this->assertFalse($target->exists(self::NAME.'/2026-08-21-00-00-00.part-02-of-02.zip'));
    }

    /**
     * H3410: resumeOffsite() (точка входа backup:resume-yandex-parts, теперь
     * поднимается systema-yandex-resume.timer вне cron.service) обязана
     * закончиться ГРОМКОЙ строкой исхода даже когда докатывать было нечего —
     * тихий выход неотличим от «упал до первой строки».
     */
    public function test_resume_offsite_logs_a_loud_completion_line_when_nothing_is_incomplete(): void
    {
        $this->seedLocalArchive('2026-08-22-17-09-58', 100);

        Log::spy();

        (new SplitUploadToYandex)->resumeOffsite();

        Log::shouldHaveReceived('info')
            ->with('split-upload: докатка завершена, неполных групп не осталось')
            ->once();
    }

    /**
     * H3410: каждый PUT части логирует свою длительность и байты/сек —
     * единственный способ увидеть стагнацию ДО того, как она станет
     * получасовым зависанием (24-08-2026 SOS-разбор).
     */
    public function test_uploading_a_part_logs_duration_and_throughput(): void
    {
        $this->seedLocalArchive('2026-08-22-17-09-58', 2 * 1024 * 1024 + 500);

        Log::spy();

        (new SplitUploadToYandex)->handle(new BackupWasSuccessful('local', self::NAME));

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'PUT')
                    && str_contains($message, 'завершён')
                    && array_key_exists('bytes', $context)
                    && array_key_exists('seconds', $context)
                    && array_key_exists('bytes_per_sec', $context);
            })
            ->atLeast()->times(1);
    }
}
