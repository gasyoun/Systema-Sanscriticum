<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SurveyEvent;
use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SurveyTabulateCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/survey-tabulation/churn-2026-10-*')) ?: [] as $dir) {
            if (is_dir($dir)) {
                array_map('unlink', glob($dir.'/*') ?: []);
                @rmdir($dir);
            }
        }

        parent::tearDown();
    }

    /** @test */
    public function churn_2026_10_wave_is_public_and_answerable(): void
    {
        config(['surveys.enabled' => true]);

        $this->get('/anketa/churn-2026-10')->assertOk()->assertSee('почему прервалось');

        $this->post('/anketa/churn-2026-10', [
            'stopped_main_reason' => 'Не хватило времени',
            'what_would_return' => 'Записи в своём темпе',
            'stopped_more' => ['Трудно найти записи занятий'],
            'wanted_features' => ['Архив всех записей по подписке', 'Разговорный клуб'],
            'subscription_interest' => '7',
            'return_condition' => 'Вернусь, когда появится больше времени и будут записи.',
        ])
            ->assertRedirect(route('survey.show', ['slug' => 'churn-2026-10', 'done' => 1]));

        $this->assertDatabaseHas('survey_responses', ['survey_slug' => 'churn-2026-10']);
    }

    /** @test */
    public function tabulate_writes_report_free_text_csv_and_console_summary(): void
    {
        $answers = [
            'stopped_main_reason' => 'Не хватило времени',
            'stopped_more' => ['Трудно найти записи занятий', 'Мало обратной связи по домашним заданиям'],
            'what_would_return' => 'Записи в своём темпе',
            'wanted_features' => ['Архив всех записей по подписке'],
            'subscription_interest' => '7',
            'next_wish' => 'Продолжение грамматики',
            'return_condition' => 'Записи и медленный темп.',
            'comment' => 'Спасибо за курс!',
        ];

        SurveyResponse::create(['survey_slug' => 'churn-2026-10', 'answers' => $answers]);
        SurveyResponse::create(['survey_slug' => 'churn-2026-10', 'answers' => array_merge($answers, [
            'stopped_main_reason' => 'Деньги: стало не по бюджету',
            'what_would_return' => 'Льготная цена для вернувшихся',
            'subscription_interest' => '3',
        ])]);

        $user = User::factory()->create();
        SurveyInvitation::create(['survey_slug' => 'churn-2026-10', 'user_id' => $user->id, 'telegram_chat_id' => 1, 'status' => 'sent']);
        SurveyEvent::create(['survey_slug' => 'churn-2026-10', 'event' => 'opened', 'session_key' => 's1']);
        SurveyEvent::create(['survey_slug' => 'churn-2026-10', 'event' => 'started', 'session_key' => 's1']);

        $this->artisan('survey:tabulate', ['slug' => 'churn-2026-10'])
            ->expectsOutputToContain('Ответов: 2')
            ->assertSuccessful();

        $dirs = glob(storage_path('app/survey-tabulation/churn-2026-10-*'));
        $this->assertNotEmpty($dirs, 'Каталог табуляции не создан');
        $dir = end($dirs);

        $report = file_get_contents($dir.'/report.md');
        $this->assertStringContainsString('Ответов: 2', $report);
        $this->assertStringContainsString('приглашено 1', $report);
        $this->assertStringContainsString('Не хватило времени | 1', $report);
        $this->assertStringContainsString('Деньги: стало не по бюджету | 1', $report);
        $this->assertStringContainsString('Трудно найти записи занятий | 2', $report);
        $this->assertStringContainsString('min / среднее / max | 3 / 5 / 7', $report);

        $csv = file_get_contents($dir.'/free_text.csv');
        $this->assertStringContainsString('Записи и медленный темп.', $csv);
        $this->assertStringContainsString('Спасибо за курс!', $csv);
        $this->assertSame("\xEF\xBB\xBF", substr($csv, 0, 3), 'CSV должен начинаться с UTF-8 BOM');
    }

    /** @test */
    public function tabulate_json_flag_writes_machine_output(): void
    {
        SurveyResponse::create([
            'survey_slug' => 'churn-2026-10',
            'answers' => ['stopped_main_reason' => 'Не хватило времени', 'what_would_return' => 'Пока не знаю'],
        ]);

        $this->artisan('survey:tabulate', ['slug' => 'churn-2026-10', '--json' => true])->assertSuccessful();

        $dirs = glob(storage_path('app/survey-tabulation/churn-2026-10-*'));
        $dir = end($dirs);
        $payload = json_decode((string) file_get_contents($dir.'/tabulation.json'), true);

        $this->assertSame('churn-2026-10', $payload['slug']);
        $this->assertSame(1, $payload['responses']);
        $this->assertSame(1, $payload['closed'][0]['distribution']['Не хватило времени']);
    }

    /** @test */
    public function tabulate_rejects_unknown_slug(): void
    {
        $this->artisan('survey:tabulate', ['slug' => 'no-such-wave'])
            ->expectsOutputToContain('Неизвестная волна опроса')
            ->assertFailed();
    }

    /** @test */
    public function tabulate_is_noop_without_responses(): void
    {
        $this->artisan('survey:tabulate', ['slug' => 'churn-2026-10'])
            ->expectsOutputToContain('Ответов ещё нет')
            ->assertSuccessful();

        $this->assertEmpty(glob(storage_path('app/survey-tabulation/churn-2026-10-*')));
    }
}
