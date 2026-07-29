<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FollowUpTask;
use App\Services\WorkQueueReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GC-C3 (H1836) — пиннинг дефолта флага `crm_follow_up_tasks`, по прецеденту
 * DealFlagDefaultTest/SrsFlagDefaultTest: две независимые проверки — значение
 * конфига И наблюдаемое поведение при OFF.
 *
 * Этот класс НИЧЕГО не переопределяет: ни config(), ни env — иначе он проверял
 * бы собственную подстановку, а не реальный дефолт.
 */
class FollowUpTaskFlagDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_crm_follow_up_tasks_defaults_to_false(): void
    {
        $this->assertFalse(
            (bool) config('features.crm_follow_up_tasks'),
            'GC-C3 обязан быть ВЫКЛ по умолчанию — это deploy-рубильник, включение отдельным шагом деплоя'
        );
    }

    public function test_crm_reminders_is_a_separate_switch_and_stays_off_too(): void
    {
        // Спека §7 F6: два флага, а не один расширенный. Проверяем, что новый
        // флаг не «наследует» состояние старого ни в одну сторону.
        $this->assertFalse((bool) config('features.crm_reminders'));
        $this->assertFalse((bool) config('features.crm_follow_up_tasks'));
    }

    public function test_the_fifth_bucket_is_inert_by_default(): void
    {
        FollowUpTask::factory()->overdue()->create();

        $report = app(WorkQueueReport::class);

        $this->assertCount(0, $report->followUpTasksDue(), 'при выключенном флаге бакет обязан быть пуст на проде');
        $this->assertSame(0, $report->counts()['follow_ups']);
    }
}
