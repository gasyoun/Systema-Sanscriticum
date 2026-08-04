<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Регрессия (money-core, H071 #2): чекаут тарифа type='vip'/'bundle' должен писать
 * в payments.tariff КАНОНИЧЕСКИЙ ключ доступа Tariff::accessKey() ('full'), а не
 * сырой тип. Раньше писался $tariff->type → 'vip' не совпадал ни с одним
 * Lesson::unlockingKeys() (['full','block_N','block_N_hH']), и оплативший VIP
 * студент не открывал ни одного урока.
 */
class VipBundleAccessKeyTest extends TestCase
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
                'Data' => ['paymentLink' => 'https://pay.tochka.com/redirect/abc', 'paymentLinkId' => 'tx_vip'],
            ], 200),
        ]);
    }

    /**
     * @test
     *
     * @dataProvider nonBlockTypes
     */
    public function checkout_stores_canonical_access_key_for_non_block_tariffs(string $type): void
    {
        $course = Course::factory()->create();
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);
        $tariff = Tariff::factory()->for($course)->create([
            'type' => $type,
            'price' => 12000,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $tariff->id])
            ->assertRedirect();

        $payment = Payment::where('user_id', $user->id)->latest('id')->first();

        // Ключ доступа — 'full', а НЕ сырой 'vip'/'bundle'.
        $this->assertSame('full', $payment->tariff, "type=$type");
        $this->assertSame($tariff->accessKey(), $payment->tariff, "type=$type");
    }

    public static function nonBlockTypes(): array
    {
        return [
            'vip' => ['vip'],
            'bundle' => ['bundle'],
            'full' => ['full'],
        ];
    }

    /** @test */
    public function paid_vip_student_unlocks_lessons(): void
    {
        $course = Course::factory()->create();
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'block_number' => 1,
            'block_half' => null,
        ]);
        $tariff = Tariff::factory()->for($course)->create(['type' => 'vip', 'price' => 12000]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $tariff->id])
            ->assertRedirect();

        // Симулируем успешную оплату (как вебхук Точки).
        $payment = Payment::where('user_id', $user->id)->latest('id')->first();
        $payment->update(['status' => 'paid']);

        // VIP-студент открывает урок (ключ 'full' совпал с unlockingKeys).
        $this->actingAs($user->fresh())
            ->get(route('student.lesson', [$course->slug, $lesson->id]))
            ->assertOk();
    }
}
