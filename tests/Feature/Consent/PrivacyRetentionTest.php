<?php

declare(strict_types=1);

namespace Tests\Feature\Consent;

use App\Logging\MaskPersonalData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 152-ФЗ ст. 5 ч. 7: маскирование почты и телефонов в логах; обнуление
 * устаревших IP командой privacy:prune (флаг privacy_prune, дефолт OFF).
 */
class PrivacyRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_masking_hides_emails_and_phones(): void
    {
        $masked = MaskPersonalData::mask('Заявка ivan.petrov@yandex.ru, тел. +7 (918) 192-50-84 и 89181925084; заказ 14540');

        $this->assertStringNotContainsString('ivan.petrov@yandex.ru', $masked);
        $this->assertStringContainsString('iv***@yandex.ru', $masked);
        $this->assertStringNotContainsString('192-50-84', $masked);
        $this->assertStringNotContainsString('89181925084', $masked);
        $this->assertStringContainsString('+7 *** ***-**-84', $masked);
        // Номера заказов и суммы не трогаем.
        $this->assertStringContainsString('заказ 14540', $masked);
    }

    public function test_masking_is_wired_into_default_log_channels(): void
    {
        $this->assertContains(MaskPersonalData::class, (array) config('logging.channels.daily.tap'));
        $this->assertContains(MaskPersonalData::class, (array) config('logging.channels.single.tap'));
    }

    public function test_prune_dry_run_counts_without_changing_and_apply_nulls_old_ips(): void
    {
        config()->set('privacy.ip_retention_days', 30);
        config()->set('privacy.ip_tables', ['leads' => ['ip_address', 'created_at']]);

        $old = DB::table('leads')->insertGetId(['name' => 'old', 'contact' => 'x', 'ip_address' => '10.0.0.1', 'created_at' => now()->subDays(40), 'updated_at' => now()]);
        $fresh = DB::table('leads')->insertGetId(['name' => 'new', 'contact' => 'y', 'ip_address' => '10.0.0.2', 'created_at' => now()->subDays(5), 'updated_at' => now()]);

        $this->artisan('privacy:prune')->expectsOutputToContain('К обнулению: 1')->assertExitCode(0);
        $this->assertSame('10.0.0.1', DB::table('leads')->where('id', $old)->value('ip_address'));

        $this->artisan('privacy:prune', ['--apply' => true])->expectsOutputToContain('Обнулено: 1')->assertExitCode(0);
        $this->assertNull(DB::table('leads')->where('id', $old)->value('ip_address'));
        $this->assertSame('10.0.0.2', DB::table('leads')->where('id', $fresh)->value('ip_address'));
    }

    public function test_scheduled_run_is_noop_while_flag_off(): void
    {
        $this->assertFalse((bool) config('features.privacy_prune'));

        DB::table('leads')->insert(['name' => 'old', 'contact' => 'x', 'ip_address' => '10.0.0.1', 'created_at' => now()->subYears(2), 'updated_at' => now()]);

        $this->artisan('privacy:prune', ['--scheduled' => true])->assertExitCode(0);
        $this->assertSame('10.0.0.1', DB::table('leads')->value('ip_address'));
    }
}
