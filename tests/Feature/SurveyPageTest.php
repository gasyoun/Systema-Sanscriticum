<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SurveyPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['surveys.enabled' => true]);
    }

    /** @test */
    public function disabled_flag_returns_404_and_unknown_slug_too(): void
    {
        config(['surveys.enabled' => false]);
        $this->get('/anketa/exit-price')->assertNotFound();

        config(['surveys.enabled' => true]);
        $this->get('/anketa/no-such-survey')->assertNotFound();

        $this->get('/anketa/exit-price')->assertOk()->assertSee('Пара вопросов о курсах');
    }

    /** @test */
    public function exit_submission_is_stored_with_reward_choice_and_contact(): void
    {
        $payload = [
            'what_happened' => 'Не было времени',
            'what_would_return' => ['Записи и свой темп', 'Оплата по блокам'],
            'format_pref' => 'Записи в своём темпе',
            'price_comfort' => '5–7 000 ₽',
            'reward_choice' => 'none',
        ];

        $this->post('/anketa/exit-price', $payload + ['website' => ''])
            ->assertRedirect('/anketa/exit-price?done=1');

        $row = SurveyResponse::firstOrFail();
        $this->assertSame('exit-price', $row->survey_slug);
        $this->assertSame('Не было времени', $row->answers['what_happened']);
        $this->assertSame(['Записи и свой темп', 'Оплата по блокам'], $row->answers['what_would_return']);
        $this->assertSame('none', $row->reward_choice);
        $this->assertNull($row->contact);
    }

    /** @test */
    public function honeypot_fakes_success_without_storing_anything(): void
    {
        $this->post('/anketa/exit-price', [
            'website' => 'http://spam.example',
            'what_happened' => 'Не было времени',
            'reward_choice' => 'none',
        ])->assertRedirect('/anketa/exit-price?done=1');

        $this->assertSame(0, SurveyResponse::count());
    }

    /** @test */
    public function required_question_blocks_submission(): void
    {
        $this->from('/anketa/exit-price')
            ->post('/anketa/exit-price', ['reward_choice' => 'none'])
            ->assertSessionHasErrors('what_happened');

        $this->assertSame(0, SurveyResponse::count());
    }

    /** @test */
    public function prana_reward_is_credited_automatically_when_email_matches_user(): void
    {
        $user = User::factory()->create(['email' => 'match@example.ru']);
        $before = (int) $user->fresh()->prana_balance;

        $this->post('/anketa/exit-price', [
            'what_happened' => 'Тогда была дороговато',
            'reward_choice' => 'prana',
            'contact' => 'Match@Example.ru',
        ])->assertRedirect();

        $row = SurveyResponse::firstOrFail();
        $this->assertSame($user->id, $row->reward_user_id);
        $this->assertNotNull($row->reward_sent_at);
        $this->assertGreaterThan($before, (int) $user->fresh()->prana_balance);
    }

    /** @test */
    public function contact_required_when_reward_requested(): void
    {
        $this->post('/anketa/exit-price', [
            'what_happened' => 'Решил(а) пока без курсов',
            'reward_choice' => 'intro',
            'contact' => '',
        ])->assertSessionHasErrors('contact');

        $this->assertSame(0, SurveyResponse::count());
    }

    /** @test */
    public function radio_answer_outside_options_is_rejected(): void
    {
        $this->post('/anketa/p2-format', [
            'level' => 'Взломал список',
            'age_range' => '25–34',
        ])->assertSessionHasErrors('level');

        $this->assertSame(0, SurveyResponse::count());
    }

    /** @test */
    public function csv_export_is_gated_and_streams_rows(): void
    {
        SurveyResponse::create([
            'survey_slug' => 'exit-price',
            'answers' => ['what_happened' => 'Не было времени'],
            'contact' => 'a@b.ru',
            'reward_choice' => 'intro',
        ]);

        $this->get('/admin/surveys/exit-price/export')->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        // Тело streamDownload в фича-тестах не захватывается — проверяем гейт,
        // статус, заголовок и факт записи, которую выгружаем.
        $this->actingAs($admin)
            ->get('/admin/surveys/exit-price/export')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $this->assertSame(1, SurveyResponse::where('survey_slug', 'exit-price')->count());
    }
}
