<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Mail\StudentLoginLinkMail;
use App\Models\Course;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\MessageTemplate;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Уведомления обращаются к студенту только по имени (21-09-2026): до этого
 * уходило «Намасте, Мухасанова Хадижа Абдурахмановна!» и «Намасте, Иванов
 * Иван, Москва!» — чекаут склеивает в name «Фамилия Имя, Город».
 */
class GreetingNameDeliveryTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function template_name_placeholder_greets_by_first_name_only(): void
    {
        $user = User::factory()->create(['name' => 'Мухасанова Хадижа Абдурахмановна']);
        $tpl = MessageTemplate::factory()->create(['body' => 'Намасте, {name}!']);

        $this->assertSame('Намасте, Хадижа!', $tpl->render($user));
    }

    /** @test */
    public function manual_greeting_name_wins_over_the_parser(): void
    {
        $user = User::factory()->create(['name' => 'Иванов Сергей', 'greeting_name' => 'Серёжа']);
        $tpl = MessageTemplate::factory()->create(['body' => 'Намасте, {name}!']);

        $this->assertSame('Намасте, Серёжа!', $tpl->render($user));
    }

    /** @test */
    public function guest_checkout_stores_the_form_first_name_as_greeting_name(): void
    {
        Queue::fake();
        Mail::fake();
        MarketingSetting::flushCached();
        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => [
                    'paymentLink' => 'https://pay.tochka.com/redirect/abc',
                    'paymentLinkId' => 'tochka_tx_greet',
                ],
            ], 200),
        ]);

        $course = Course::factory()->create();
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);
        $tariff = Tariff::factory()->for($course)->create(['price' => 5000]);

        $this->post(route('payment.create'), [
            'tariff_id' => $tariff->id,
            'name' => 'Хадижа',
            'surname' => 'Мухасанова',
            'city' => 'Махачкала',
            'email' => 'hadizha@example.test',
        ])->assertRedirect();

        $user = User::where('email', 'hadizha@example.test')->firstOrFail();
        $this->assertSame('Мухасанова Хадижа, Махачкала', $user->name, 'склейка name не меняется');
        $this->assertSame('Хадижа', $user->greeting_name);
        $this->assertSame('Хадижа', $user->greetingName());
    }

    /** @test */
    public function login_link_mail_greets_by_first_name(): void
    {
        $user = User::factory()->create(['name' => 'Иванов Иван, Москва']);
        $html = (new StudentLoginLinkMail($user, 'https://samskrte.ru/login/x'))->render();

        $this->assertStringContainsString('Намасте, Иван!', $html);
        $this->assertStringNotContainsString('Москва', $html);
    }
}
