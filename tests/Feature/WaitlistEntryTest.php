<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Intake;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Слой данных модуля набора: нормализация @username, авто-линк возвратного
 * студента, выведенные (не хранимые) поля истории/надёжности и связь Intake↔Group.
 */
class WaitlistEntryTest extends TestCase
{
    use RefreshDatabase;

    /** @-и t.me/ снимаются, а совпавший по хэндлу студент автоматически привязывается. */
    public function test_normalizes_username_and_autolinks_returning_student(): void
    {
        $student = User::factory()->create(['telegram_username' => 'ramaswami']);

        $entry = WaitlistEntry::create([
            'name' => 'Игорь Возвратный',
            'telegram_username' => 'https://t.me/@ramaswami',
        ]);

        $this->assertSame('ramaswami', $entry->telegram_username, '@ и t.me/ должны сниматься.');
        $this->assertSame($student->id, $entry->user_id, 'Совпавший по хэндлу студент привязывается.');
        $this->assertTrue($entry->isReturning());
    }

    /** Несматченная заявка остаётся без user_id и берёт прежнюю группу из свободного текста. */
    public function test_unmatched_entry_falls_back_to_free_text(): void
    {
        $entry = WaitlistEntry::create([
            'name' => 'Новичок без аккаунта',
            'telegram_username' => '@ghost_no_account',
            'prior_group_note' => 'Поток 3 (со слов)',
        ]);

        $this->assertNull($entry->user_id);
        $this->assertFalse($entry->isReturning());
        $this->assertSame('unknown', $entry->reliability());
        $this->assertSame(['Поток 3 (со слов)'], $entry->priorGroupNames()->all());
        $this->assertNull($entry->lastDropBlock());
    }

    /** Надёжность выводится из истории: непогашенное обещание → риск. */
    public function test_reliability_risk_from_unmet_promise(): void
    {
        $student = User::factory()->create(['telegram_username' => 'risky']);
        $course = Course::factory()->create();
        PaymentPromise::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'promised_at' => now()->addDays(3),
            'amount' => 1000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        $entry = WaitlistEntry::create(['name' => 'Р.', 'telegram_username' => 'risky']);

        $this->assertSame('risk', $entry->reliability());
        $this->assertSame('Риск неоплаты', $entry->reliabilityBadge()['label']);
        $this->assertSame('danger', $entry->reliabilityBadge()['color']);
    }

    /** Отвал из группы в прошлом → надёжность «watch». */
    public function test_reliability_watch_from_dropped_group(): void
    {
        $student = User::factory()->create(['telegram_username' => 'dropped']);
        $group = Group::create(['name' => 'Поток 5']);
        $student->groups()->attach($group->id, ['left_at' => now()->subMonth()]);

        $entry = WaitlistEntry::create(['name' => 'Д.', 'telegram_username' => 'dropped']);

        $this->assertSame('watch', $entry->reliability());
        $this->assertSame(['Поток 5'], $entry->priorGroupNames()->all());
    }

    /** Есть оплата, отвалов и долгов нет → «reliable». */
    public function test_reliability_reliable_from_paid_history(): void
    {
        $student = User::factory()->create(['telegram_username' => 'good']);
        $course = Course::factory()->create();
        // Без событий модели — не дёргаем PaymentObserver::grantAccess в тесте.
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount' => 5000,
            'tariff' => 'full',
            'status' => 'paid',
        ]));

        $entry = WaitlistEntry::create(['name' => 'Х.', 'telegram_username' => 'good']);

        $this->assertSame('reliable', $entry->reliability());
        $this->assertSame('success', $entry->reliabilityBadge()['color']);
    }

    /** Блок отвала выводится из макс. course_user.left_after_block. */
    public function test_last_drop_block_from_course_pivot(): void
    {
        $student = User::factory()->create(['telegram_username' => 'blocky']);
        $courseA = Course::factory()->create();
        $courseB = Course::factory()->create();
        $student->courses()->attach($courseA->id, ['status' => 'Покинул', 'left_after_block' => 2]);
        $student->courses()->attach($courseB->id, ['status' => 'Покинул', 'left_after_block' => 4]);

        $entry = WaitlistEntry::create(['name' => 'Б.', 'telegram_username' => 'blocky']);

        $this->assertSame(4, $entry->lastDropBlock(), 'Берётся максимальный блок отвала по всем курсам.');
    }

    /** Intake порождает группы и хранит плановые номера будущих групп (round-trip JSON). */
    public function test_intake_owns_groups_and_planned_numbers(): void
    {
        $intake = Intake::create([
            'label' => 'Июль 2026',
            'target_year' => 2026,
            'target_month' => 7,
            'planned_group_numbers' => ['61', '62'],
            'status' => 'forming',
        ]);

        $group = Group::create(['name' => 'Поток 61', 'intake_id' => $intake->id]);

        $this->assertTrue($intake->groups->pluck('id')->contains($group->id));
        $this->assertSame($intake->id, $group->intake->id);
        $this->assertSame(['61', '62'], $intake->fresh()->planned_group_numbers);
        $this->assertSame('planning', (new Intake)->status, 'Дефолт статуса — planning.');
    }

    /** Заявки привязываются к набору через intake_id. */
    public function test_waitlist_entry_belongs_to_intake(): void
    {
        $intake = Intake::create(['label' => 'Сентябрь 2026', 'target_year' => 2026, 'target_month' => 9]);
        $entry = WaitlistEntry::create(['name' => 'А.', 'intake_id' => $intake->id]);

        $this->assertTrue($intake->waitlistEntries->pluck('id')->contains($entry->id));
        $this->assertSame($intake->id, $entry->intake->id);
        $this->assertSame('waiting', $entry->status, 'Дефолт статуса заявки — waiting.');
    }
}
