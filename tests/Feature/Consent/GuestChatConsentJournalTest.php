<?php

declare(strict_types=1);

namespace Tests\Feature\Consent;

use App\Models\Consent;
use App\Models\User;
use App\Services\Consent\ConsentRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * H6487 / F1 аудита 152-ФЗ (docs/AUDIT_152FZ_SAMSKRTE_2026-10-11.md).
 *
 * Согласие гостя в чат-виджете обязано лежать в серверном журнале `consents`
 * (ст. 9 ч. 3: доказывать получение согласия обязан оператор), а не только в
 * localStorage браузера — очистка хранилища не должна стирать доказательство.
 *
 * Пин держит контракт журнала: строка пишется на отправленную галочку, вторая
 * галочка ДОБАВЛЯЕТ строку (первая не переписывается, updated_at в схеме нет),
 * текст обращения и контакты в журнал не утекают, а клиент получает
 * подтверждение записи (`consent_recorded`), по которому виджет прячет галочку.
 */
class GuestChatConsentJournalTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $payload */
    private function postGuestMessage(array $payload = []): TestResponse
    {
        return $this->postJson('/chat/message', $payload + ['text' => 'Вопрос по курсу']);
    }

    public function test_guest_consent_is_written_to_the_journal_with_the_consent_version(): void
    {
        $response = $this->postGuestMessage([
            'pd_consent' => true,
            'email' => 'Guest@Example.COM',
        ]);

        $response->assertOk()->assertJson(['ok' => true, 'consent_recorded' => true]);

        $row = Consent::query()->sole();

        $this->assertSame(Consent::TYPE_PD, $row->type);
        $this->assertSame(Consent::ACTION_GIVEN, $row->action);
        $this->assertSame('support-chat', $row->source);
        $this->assertNull($row->user_id, 'гость не резолвится в строку users');
        $this->assertSame('guest@example.com', $row->email);
        $this->assertSame(config('consent.documents.pd.version'), $row->doc_version);
        $this->assertNotNull($row->ip_address, 'IP — доказательство контекста получения согласия');
        $this->assertNotNull($row->created_at);
    }

    public function test_guest_consent_without_email_still_writes_a_row(): void
    {
        // user_id и email nullable: анонимный гость обязан попасть в журнал.
        $response = $this->postGuestMessage(['pd_consent' => true]);

        $response->assertOk()->assertJson(['consent_recorded' => true]);

        $row = Consent::query()->sole();
        $this->assertNull($row->user_id);
        $this->assertNull($row->email);
        $this->assertSame('support-chat', $row->source);
    }

    public function test_consent_row_is_written_once_per_given_consent(): void
    {
        $this->postGuestMessage(['pd_consent' => true, 'email' => 'once@example.com']);
        // виджет шлёт галочку только с первым сообщением — дальше её нет
        $this->postGuestMessage(['email' => 'once@example.com']);

        $this->assertSame(1, Consent::query()->count());
    }

    public function test_journal_is_append_only_a_repeated_consent_appends_and_never_rewrites(): void
    {
        // Схема: обновлять строку согласия нечем — колонки updated_at нет,
        // модель её не пишет (Consent::UPDATED_AT = null).
        $this->assertFalse(Schema::hasColumn('consents', 'updated_at'));
        $this->assertNull(Consent::UPDATED_AT);

        $this->postGuestMessage(['pd_consent' => true, 'email' => 'append@example.com']);
        $first = Consent::query()->sole();
        $snapshot = $first->getAttributes();

        // Повторная галочка (например, гость очистил браузер) — новая строка,
        // а не правка прежней: текущее состояние = последняя строка по субъекту.
        $this->postGuestMessage(['pd_consent' => true, 'email' => 'append@example.com']);

        $rows = Consent::query()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertGreaterThan($first->id, $rows[1]->id);
        $this->assertSame($snapshot, $rows[0]->fresh()->getAttributes(), 'журнал append-only: первая строка не переписана');
    }

    public function test_consent_row_does_not_become_a_pii_dump(): void
    {
        $this->postGuestMessage([
            'pd_consent' => true,
            'name' => 'Мария',
            'email' => 'dump@example.com',
            'phone' => '+79995554433',
            'text' => 'Секретный текст обращения про оплату',
        ]);

        $row = Consent::query()->sole();
        $flat = collect($row->getAttributes())
            ->filter(fn ($value) => is_string($value))
            ->implode(' | ');

        foreach (['Мария', '+79995554433', 'Секретный текст обращения'] as $personal) {
            $this->assertStringNotContainsString($personal, $flat, 'в журнал согласия не утекают имя/телефон/текст');
        }

        $this->assertSame('dump@example.com', $row->email, 'email — идентификатор субъекта, он допустим');
    }

    public function test_message_without_the_consent_flag_writes_no_pd_row(): void
    {
        $this->postGuestMessage(['email' => 'noflag@example.com']);

        $this->assertSame(0, Consent::query()->count());
    }

    public function test_logged_in_student_message_writes_no_guest_consent_row(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/chat/message', ['text' => 'Я студент', 'pd_consent' => true])
            ->assertOk()
            ->assertJsonMissingPath('consent_recorded');

        $this->assertSame(0, Consent::query()->count(), 'согласие студента фиксируется при регистрации, не в чате');
    }

    public function test_journal_failure_is_reported_so_the_guest_is_asked_again(): void
    {
        // Сбой журнала не роняет сообщение (контракт ConsentRecorder), но и не
        // выдаётся за успех: виджет не спрячет галочку и пришлёт её снова.
        $this->mock(ConsentRecorder::class)
            ->shouldReceive('fromForm')
            ->once()
            ->andReturnNull();

        $response = $this->postGuestMessage(['pd_consent' => true]);

        $response->assertOk()->assertJson(['ok' => true, 'consent_recorded' => false]);
        $this->assertSame(0, Consent::query()->count());
    }

    public function test_widget_remembers_consent_only_under_the_journal_backed_key(): void
    {
        $html = view('partials.support-chat-widget')->render();

        $this->assertStringContainsString('scw_pd_consent_v2', $html);
        $this->assertStringNotContainsString('scw_pd_consent_v1', $html);
        $this->assertStringContainsString('res.body.consent_recorded === true', $html);
    }
}
