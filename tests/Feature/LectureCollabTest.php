<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LectureDraft;
use App\Services\Lecture\LectureStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase C: advisory-лок редактирования + откат data.json к бэкапу.
 */
class LectureCollabTest extends TestCase
{
    use RefreshDatabase;

    private function draft(): LectureDraft
    {
        return LectureDraft::create(['title' => 'Лекция', 'status' => LectureDraft::STATUS_BUILT]);
    }

    // ── Advisory-лок ─────────────────────────────────────────────────────────

    /** @test */
    public function heartbeat_acquires_and_other_user_is_blocked(): void
    {
        $draft = $this->draft();
        $this->assertNull($draft->activeLock());

        $this->assertTrue($draft->heartbeatLock(1, 'Аня'));
        $draft->refresh();

        $this->assertNotNull($draft->activeLock());
        $this->assertTrue($draft->isLockedByOther(2));
        $this->assertFalse($draft->isLockedByOther(1)); // владелец не «другой»

        // Второй редактор не может перехватить активный лок.
        $this->assertFalse($draft->heartbeatLock(2, 'Боря'));
        $this->assertSame('Аня', $draft->fresh()->activeLock()['name']);
    }

    /** @test */
    public function release_frees_the_lock(): void
    {
        $draft = $this->draft();
        $draft->heartbeatLock(1, 'Аня');
        $draft->refresh()->releaseLock(1);

        $this->assertNull($draft->fresh()->activeLock());
    }

    /** @test */
    public function stale_lock_expires_after_ttl(): void
    {
        $draft = $this->draft();
        // Лок без хартбита дольше TTL → протух.
        $draft->forceFill(['meta' => ['lock' => [
            'user_id' => 1, 'name' => 'Аня',
            'at' => now()->subSeconds(LectureDraft::LOCK_TTL_SECONDS + 60)->toIso8601String(),
        ]]])->save();

        $this->assertNull($draft->activeLock());
        $this->assertFalse($draft->isLockedByOther(2));
        // Протухший лок можно перехватить.
        $this->assertTrue($draft->heartbeatLock(2, 'Боря'));
    }

    // ── Откат к бэкапу ───────────────────────────────────────────────────────

    private function seedBackups(LectureDraft $draft): void
    {
        Storage::disk('local')->put("lectures/{$draft->id}/data.json", '{"v":"current"}');
        Storage::disk('local')->put("lectures/{$draft->id}/backups/20260101_010101.json", '{"v":"old"}');
        Storage::disk('local')->put("lectures/{$draft->id}/backups/20260102_020202.json", '{"v":"newer"}');
    }

    /** @test */
    public function list_backups_returns_newest_first_with_labels(): void
    {
        Storage::fake('local');
        $draft = $this->draft();
        $this->seedBackups($draft);

        $backups = app(LectureStorage::class)->listBackups($draft);

        $this->assertCount(2, $backups);
        $this->assertSame('20260102_020202.json', $backups[0]['file']); // новый сверху
        $this->assertSame('02.01.2026 02:02:02', $backups[0]['label']);
    }

    /** @test */
    public function restore_backup_overwrites_data_json_and_keeps_a_pre_restore_copy(): void
    {
        Storage::fake('local');
        $draft = $this->draft();
        $this->seedBackups($draft);

        app(LectureStorage::class)->restoreBackup($draft, '20260101_010101.json');

        $this->assertSame('{"v":"old"}', Storage::disk('local')->get("lectures/{$draft->id}/data.json"));
        // Текущее состояние сохранено перед откатом (обратимость).
        $preRestore = collect(Storage::disk('local')->files("lectures/{$draft->id}/backups"))
            ->first(fn ($f) => str_contains($f, '_pre_restore'));
        $this->assertNotNull($preRestore);
        $this->assertSame('{"v":"current"}', Storage::disk('local')->get($preRestore));
    }

    /** @test */
    public function restore_rejects_path_traversal(): void
    {
        Storage::fake('local');
        $draft = $this->draft();
        $this->seedBackups($draft);

        $this->expectException(RuntimeException::class);
        app(LectureStorage::class)->restoreBackup($draft, '../../data.json');
    }

    /** @test */
    public function restore_rejects_missing_backup(): void
    {
        Storage::fake('local');
        $draft = $this->draft();
        $this->seedBackups($draft);

        $this->expectException(RuntimeException::class);
        app(LectureStorage::class)->restoreBackup($draft, '20990101_000000.json');
    }
}
