<?php

declare(strict_types=1);

namespace Tests\Feature\Cabinet;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\PaymentPromise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «Оплата и доступ» → «Внести платеж» по договорённости. Маршрут
 * student.debt.promise.pay принимает только POST; кнопка была голой ссылкой и
 * давала студенту «405 Method Not Allowed» (прод, 30-09-2026).
 */
class AccessPromisePayButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_promise_pay_is_a_post_form_that_reaches_the_bank(): void
    {
        config(['features.cabinet_hybrid' => true]);
        Http::fake([
            '*/payments_with_receipt' => Http::response([
                'Data' => ['paymentLink' => 'https://pay.tochka.test/p', 'paymentLinkId' => 'pl-1'],
            ], 200),
        ]);

        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true, 'title' => 'Медленное чтение']);
        CourseBlock::factory()->for($course)->create(['number' => 1]);
        CourseBlock::factory()->for($course)->current()->create(['number' => 2]);
        $promise = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->subDays(3)->toDateString(), 'amount' => 4800,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);
        $url = route('student.debt.promise.pay', $promise);

        $html = $this->actingAs($user)->get(route('student.access'))->assertOk()->getContent();

        $this->assertStringContainsString('<form method="POST" action="'.$url.'"', $html);
        $this->assertStringNotContainsString('<a href="'.$url.'"', $html);

        $this->actingAs($user)->post($url)->assertRedirect('https://pay.tochka.test/p');
    }
}
