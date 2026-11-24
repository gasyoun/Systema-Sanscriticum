<?php

declare(strict_types=1);

namespace Tests\Feature\Knowledge;

use App\Filament\Resources\UserResource;
use App\Mail\LessonAiInviteMail;
use App\Models\Consent;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Приглашение в тест «Спроси Сократа» и согласие на него.
 *
 * Контракт, который здесь запинен:
 *  - письмо называет срок теста и цену после релиза, несет подписанные ссылки;
 *  - ОТКРЫТИЕ страницы согласия ничего не включает (антивирус и предпросмотр
 *    ссылок ходят по GET, согласие не должно выдаваться без человека);
 *  - только POST с decision=yes и отмеченным чекбоксом ставит
 *    users.lesson_ai_tester и пишет строку в журнал consents (152-ФЗ);
 *  - отказ тоже пишется в журнал и не снимает уже выданный доступ;
 *  - массовое действие админки только рассылает письма, флаг не трогает.
 */
class LessonAiConsentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-24 12:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_invite_mail_names_test_window_price_and_consent_links(): void
    {
        $user = User::factory()->create(['name' => 'Анна Тестова', 'email' => 'anna@example.com']);

        [$consentUrl, $declineUrl] = $this->signedLinks($user);

        $html = (new LessonAiInviteMail($user, $consentUrl, $declineUrl))->render();

        $this->assertStringContainsString('Спроси Сократа', $html);
        $this->assertStringContainsString('две недели', $html);
        $this->assertStringContainsString('2000 ₽ в месяц', $html);
        $this->assertStringContainsString('Да, согласен(на)', $html);
        $this->assertStringContainsString('Нет, спасибо', $html);
        $this->assertStringContainsString('Молчание мы согласием не считаем', $html);
        $this->assertStringContainsString(e($consentUrl), $html);
        $this->assertStringContainsString(e($declineUrl), $html);
        // В письме нет формы: согласие дается на странице, а не в почте.
        $this->assertStringNotContainsString('<form', $html);
    }

    public function test_consent_page_opens_by_signed_link_and_shows_the_text(): void
    {
        $user = User::factory()->create(['name' => 'Анна Тестова']);

        [$consentUrl] = $this->signedLinks($user);

        $this->get($consentUrl)
            ->assertOk()
            ->assertSee('Согласие на участие в тесте', false)
            ->assertSee('Что обрабатывается', false)
            ->assertSee('Отозвать согласие можно в любой момент', false);

        // Ключевое: открытие ссылки НЕ включает доступ.
        $this->assertFalse((bool) $user->fresh()->lesson_ai_tester);
        $this->assertSame(0, Consent::query()->count());
    }

    public function test_unsigned_link_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->get(route('lesson-ai.consent', ['user' => $user->id]))->assertForbidden();
    }

    public function test_yes_with_checkbox_enables_tester_and_records_consent(): void
    {
        $user = User::factory()->create();

        [$consentUrl] = $this->signedLinks($user);

        $this->post($consentUrl, ['decision' => 'yes', 'confirm' => '1'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) $user->fresh()->lesson_ai_tester);

        $consent = Consent::query()->sole();
        $this->assertSame(Consent::TYPE_AI_TEST, $consent->type);
        $this->assertSame(Consent::ACTION_GIVEN, $consent->action);
        $this->assertSame($user->id, $consent->user_id);
        $this->assertSame('2026-11-24', $consent->doc_version);
        $this->assertSame('lesson-ai-invite:email', $consent->source);
    }

    public function test_yes_without_checkbox_does_not_enable_tester(): void
    {
        $user = User::factory()->create();

        [$consentUrl] = $this->signedLinks($user);

        $this->post($consentUrl, ['decision' => 'yes'])
            ->assertSessionHasErrors('confirm');

        $this->assertFalse((bool) $user->fresh()->lesson_ai_tester);
        $this->assertSame(0, Consent::query()->count());
    }

    public function test_no_records_withdrawal_and_keeps_access_as_is(): void
    {
        $user = User::factory()->create(['lesson_ai_tester' => true]);

        [$consentUrl] = $this->signedLinks($user);

        $this->post($consentUrl, ['decision' => 'no'])->assertRedirect();

        // Отказ не отбирает уже выданный доступ — он только про разбор вопросов.
        $this->assertTrue((bool) $user->fresh()->lesson_ai_tester);

        $consent = Consent::query()->sole();
        $this->assertSame(Consent::TYPE_AI_TEST, $consent->type);
        $this->assertSame(Consent::ACTION_WITHDRAWN, $consent->action);
    }

    public function test_done_page_reports_the_decision(): void
    {
        $user = User::factory()->create();

        [$consentUrl] = $this->signedLinks($user);

        $response = $this->post($consentUrl, ['decision' => 'yes', 'confirm' => '1']);

        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('доступ к «Спроси Сократа» включен', false);
    }

    public function test_bulk_action_only_sends_mail_and_never_sets_the_flag(): void
    {
        Mail::fake();

        $admin = User::factory()->create();
        $admin->forceFill(['role' => Roles::SUPER_ADMIN])->save();

        $withEmail = User::factory()->create(['email' => 'student@example.com']);
        $withoutEmail = User::factory()->create();
        $withoutEmail->forceFill(['email' => ''])->save();

        $this->actingAs($admin->fresh());

        UserResource::sendLessonAiInvites(User::query()->whereIn('id', [$withEmail->id, $withoutEmail->id])->get());

        Mail::assertQueued(LessonAiInviteMail::class, 1);
        Mail::assertQueued(LessonAiInviteMail::class, fn (LessonAiInviteMail $mail) => $mail->hasTo('student@example.com'));

        $this->assertFalse((bool) $withEmail->fresh()->lesson_ai_tester);
        $this->assertSame(0, Consent::query()->count());
    }

    public function test_bulk_action_is_super_admin_only(): void
    {
        Mail::fake();

        $student = User::factory()->create();
        $this->actingAs($student);

        $this->expectException(HttpException::class);

        UserResource::sendLessonAiInvites(User::query()->whereKey($student->id)->get());
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function signedLinks(User $user): array
    {
        $ttl = now()->addDays(30);

        return [
            URL::temporarySignedRoute('lesson-ai.consent', $ttl, ['user' => $user->id]),
            URL::temporarySignedRoute('lesson-ai.consent', $ttl, ['user' => $user->id, 'intent' => 'no']),
        ];
    }
}
