<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H266 (тикет M1, #190) — продажа записей завершённых курсов.
 *
 * Ключевой инвариант: «запись» — это НЕ новая система доступа, а маркер витрины
 * поверх обычного key-based чекаута. Тариф-запись пишет тот же accessKey()
 * ('full'/'block_N') и открывает те же уроки через тот же
 * PaymentObserver::grantAccess(). У завершённого курса живого расписания/Zoom-цикла
 * уже нет, поэтому «запись открывает уроки, но не расписание» выполняется само.
 */
class RecordingSalesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        MarketingSetting::flushCached();
        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => ['paymentLink' => 'https://pay.tochka.com/redirect/abc', 'paymentLinkId' => 'tx_rec'],
            ], 200),
        ]);
    }

    private function completedCourse(): Course
    {
        $course = Course::factory()->create(['is_completed' => true]);
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);

        return $course;
    }

    /** @test */
    public function recording_tariff_reuses_canonical_access_keys(): void
    {
        // is_recording НЕ трогает accessKey(): целый курс → 'full', блок → 'block_N'.
        $fullRec = Tariff::factory()->create(['is_recording' => true]);
        $blockRec = Tariff::factory()->block(2)->create(['is_recording' => true]);

        $this->assertSame('full', $fullRec->accessKey());
        $this->assertSame('block_2', $blockRec->accessKey());
        $this->assertTrue($fullRec->isRecording());
        $this->assertFalse(Tariff::factory()->create()->isRecording());
    }

    /** @test */
    public function buying_full_recording_unlocks_all_lessons_on_completed_course(): void
    {
        $course = $this->completedCourse();
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'block_number' => 1,
            'block_half' => null,
        ]);
        $tariff = Tariff::factory()->for($course)->create([
            'type' => 'full',
            'is_recording' => true,
            'price' => 6000,
        ]);

        $user = User::factory()->create();

        // Полноценный чекаут: тариф-запись должен записать канонический ключ 'full'.
        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $tariff->id])
            ->assertRedirect();

        $payment = Payment::where('user_id', $user->id)->latest('id')->first();
        $this->assertSame('full', $payment->tariff);

        // Симулируем успешную оплату (как вебхук Точки).
        $payment->update(['status' => 'paid']);

        // Купивший запись открывает уроки завершённого курса.
        $this->actingAs($user->fresh())
            ->get(route('student.lesson', [$course->slug, $lesson->id]))
            ->assertOk();
    }

    /** @test */
    public function recording_price_respects_loyalty_discount(): void
    {
        // Цена записи идёт через тот же calculateFinalPriceForUser (лояльность и пр.).
        MarketingSetting::create([
            'is_loyalty_active' => true,
            'wholesale_small_threshold' => 2,
            'wholesale_small_discount' => 10,
            'wholesale_large_threshold' => 99,
            'wholesale_large_discount' => 20,
        ]);

        $course = $this->completedCourse();
        $user = User::factory()->create();

        // 2 других оплаченных курса → лояльность 10%.
        foreach (range(1, 2) as $i) {
            Payment::create([
                'user_id' => $user->id,
                'course_id' => Course::factory()->create()->id,
                'amount' => 4800,
                'tariff' => 'full',
                'status' => 'paid',
            ]);
        }

        $recording = Tariff::factory()->for($course)->create([
            'type' => 'full',
            'is_recording' => true,
            'price' => 6000,
        ]);

        // 6000 − 10% = 5400.
        $this->assertSame(5400.0, $recording->calculateFinalPriceForUser($user->fresh()));
    }

    /** @test */
    public function sells_recordings_requires_flag_completed_and_active_recording_tariff(): void
    {
        $course = $this->completedCourse();
        Tariff::factory()->for($course)->create(['is_recording' => true, 'is_active' => true]);

        // Флаг ВЫКЛ (дефолт) → режим записи не включается, даже когда всё готово.
        config(['features.course_recordings_sales' => false]);
        $this->assertFalse($course->fresh()->sellsRecordings());

        // Флаг ВКЛ + курс завершён + активный тариф-запись → включается.
        config(['features.course_recordings_sales' => true]);
        $this->assertTrue($course->fresh()->sellsRecordings());

        // Курс НЕ завершён → нет.
        $notFinished = Course::factory()->create(['is_completed' => false]);
        Tariff::factory()->for($notFinished)->create(['is_recording' => true, 'is_active' => true]);
        $this->assertFalse($notFinished->fresh()->sellsRecordings());

        // Завершён, но нет тарифа-записи (только обычный тариф) → нет.
        $noRecTariff = $this->completedCourse();
        Tariff::factory()->for($noRecTariff)->create(['is_recording' => false, 'is_active' => true]);
        $this->assertFalse($noRecTariff->fresh()->sellsRecordings());

        // Тариф-запись есть, но неактивен → нет.
        $inactiveRec = $this->completedCourse();
        Tariff::factory()->for($inactiveRec)->create(['is_recording' => true, 'is_active' => false]);
        $this->assertFalse($inactiveRec->fresh()->sellsRecordings());
    }

    /** @test */
    public function landing_shows_buy_recording_cta_only_when_selling_recordings(): void
    {
        $course = $this->completedCourse();
        Tariff::factory()->for($course)->create([
            'type' => 'full',
            'is_recording' => true,
            'price' => 6000,
        ]);

        // Флаг ВЫКЛ → обычная CTA «Записаться на курс», без «Купить запись».
        config(['features.course_recordings_sales' => false]);
        $this->get(route('shop.course.show', $course->slug))
            ->assertOk()
            ->assertSee('Записаться на курс')
            ->assertDontSee('Купить запись курса');

        // Флаг ВКЛ → CTA переключается на «Купить запись курса».
        config(['features.course_recordings_sales' => true]);
        $this->get(route('shop.course.show', $course->slug))
            ->assertOk()
            ->assertSee('Купить запись курса')
            ->assertDontSee('Записаться на курс');
    }

    /** @test */
    public function recording_purchase_grants_lessons_but_no_live_schedule(): void
    {
        // «Запись открывает уроки, но не расписание/Zoom-цикл»: у завершённого курса
        // будущих занятий нет вовсе, поэтому покупатель записи получает доступ к
        // урокам, но никакого живого расписания к нему не подтягивается.
        $course = $this->completedCourse();
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'block_number' => 1,
            'block_half' => null,
        ]);

        // Прошедшее занятие (курс завершён) — в будущее расписание не попадает.
        Schedule::create([
            'title' => 'Финальное занятие',
            'course_id' => $course->id,
            'start' => now()->subMonth(),
            'end' => now()->subMonth()->addHours(2),
        ]);

        $user = User::factory()->create();
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 6000,
            'tariff' => 'full', // accessKey() тарифа-записи
            'status' => 'paid',
        ]);

        // Уроки открыты…
        $this->actingAs($user)
            ->get(route('student.lesson', [$course->slug, $lesson->id]))
            ->assertOk();

        // …а живого расписания у завершённого курса нет.
        $this->assertTrue($course->fresh()->upcomingSchedules()->isEmpty());
    }
}
