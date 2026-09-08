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

    /** @test */
    public function wave_two_slugs_render_and_store(): void
    {
        foreach (['onboarding', 'churn-block', 'post3m', 'yoga-sutras-revive'] as $slug) {
            $this->get('/anketa/'.$slug)->assertOk();
        }

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/anketa/onboarding', [
                'what_brought' => 'Йога, практика и мантры',
                'goal_year' => 'Читать простые тексты',
                'level' => 'Начинаю с нуля',
                'registration_trigger' => 'Начался курс',
                'discovery_open' => 'Рассказал знакомый',
                'format_pref' => 'Живые уроки по расписанию',
            ])->assertRedirect();

        $row = SurveyResponse::where('survey_slug', 'onboarding')->firstOrFail();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('Йога, практика и мантры', $row->answers['what_brought']);
        $this->assertSame('Читать простые тексты', $row->answers['goal_year']);

        $this->post('/anketa/churn-block', [
            'stopped_because' => 'Не хватило времени',
            'return_intent' => 'Вернусь к этому же курсу',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->post('/anketa/post3m', [
            'nps' => 9,
            'want_next' => 'Продолжение грамматики (синтаксис, Бюлер)',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->post('/anketa/yoga-sutras-revive', [
            'remember' => 'Помню хорошо',
            'would_want' => 'Курс в записи, в своём темпе',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, SurveyResponse::where('survey_slug', 'churn-block')->count());
        $this->assertSame(1, SurveyResponse::where('survey_slug', 'post3m')->count());
        $this->assertSame(1, SurveyResponse::where('survey_slug', 'yoga-sutras-revive')->count());
    }

    /** @test */
    public function scale_out_of_range_is_rejected(): void
    {
        $this->post('/anketa/post3m', [
            'nps' => 11,
            'want_next' => 'Хинди',
        ])->assertSessionHasErrors('nps');

        $this->assertSame(0, SurveyResponse::count());
    }

    /** @test */
    public function repeat_submission_with_same_email_awards_prana_once(): void
    {
        $user = User::factory()->create(['email' => 'farm@example.ru']);
        $payload = [
            'what_happened' => 'Не было времени',
            'reward_choice' => 'prana',
            'contact' => 'Farm@Example.ru',
        ];

        $this->post('/anketa/exit-price', $payload)->assertRedirect();
        $first = SurveyResponse::firstOrFail();
        $balanceAfterFirst = (int) $user->fresh()->prana_balance;
        $this->assertNotNull($first->reward_sent_at);
        $this->assertGreaterThan(0, $balanceAfterFirst);

        // Повторная отправка той же анкеты тем же человеком (фарм награды):
        // ответ сохраняется, прана НЕ начисляется второй раз.
        $this->post('/anketa/exit-price', [
            'what_happened' => 'Не помню деталей',
            'reward_choice' => 'prana',
            'contact' => 'farm@example.ru',
        ])->assertRedirect('/anketa/exit-price?done=1');

        $this->assertSame(2, SurveyResponse::count());
        $second = SurveyResponse::orderBy('id')->skip(1)->first();
        $this->assertNull($second->reward_user_id);
        $this->assertNull($second->reward_sent_at);
        $this->assertSame($balanceAfterFirst, (int) $user->fresh()->prana_balance);
        $this->assertSame(
            1,
            \DB::table('prana_transactions')->where('user_id', $user->id)->where('reason', 'survey_reward')->count(),
        );
    }

    /** @test */
    public function same_email_is_rewarded_independently_per_survey(): void
    {
        config(['surveys.definitions.test-reward-2' => [
            'title' => 'Второй опрос',
            'intro' => '',
            'reward_enabled' => true,
            'questions' => [
                ['id' => 'note', 'type' => 'text', 'required' => false, 'label' => 'Заметка'],
            ],
        ]]);

        $user = User::factory()->create(['email' => 'once@example.ru']);

        $this->post('/anketa/exit-price', [
            'what_happened' => 'Тогда была дороговато',
            'reward_choice' => 'prana',
            'contact' => 'once@example.ru',
        ])->assertRedirect('/anketa/exit-price?done=1');

        // Другой опрос — отдельная награда: дедуп только внутри одного slug.
        $this->post('/anketa/test-reward-2', [
            'note' => 'Второй опрос пройден',
            'reward_choice' => 'prana',
            'contact' => 'once@example.ru',
        ])->assertRedirect();

        $this->assertSame(
            2,
            SurveyResponse::whereNotNull('reward_sent_at')->where('reward_user_id', $user->id)->count(),
        );
    }

    /** @test */
    public function student_purchase_wave_is_six_pages_and_stores_depth_answers(): void
    {
        $response = $this->get('/anketa/student-purchase-2026-09')
            ->assertOk()
            ->assertSee('Ваш путь в ОРС: подробная анкета для постоянных учеников')
            ->assertSee('data-survey-pages="6"', false)
            ->assertSee('Страница 1 из 6')
            ->assertSee('Как всё началось')
            ->assertSee('Что должно быть дальше')
            ->assertDontSee('Благодарность за ответы');

        $html = (string) $response->getContent();
        $this->assertLessThan(
            (int) strpos($html, 'name="purchase_trigger"'),
            (int) strpos($html, 'name="first_motive_open"'),
        );

        $this->post('/anketa/student-purchase-2026-09', [
            'first_course' => 'Грамматика с нуля, 2023',
            'first_motive_open' => 'Хотел(а) читать Гиту в оригинале',
            'purchase_trigger' => 'Пробное занятие или бот',
            'before_after' => 'Теперь разбираю простые строфы',
            'stay_reason_open' => 'Понятная система и преподаватель',
            'repeat_purchase' => 'Курс чтения',
            'value_for_money_open' => 'За разбор моих ошибок',
            'next_purchase_condition' => 'Разобрать конкретный текст',
            'first_discovery_open' => 'Нашёл через поиск',
            'main_goal' => 'Читать и понимать оригинальные тексты',
            'next_skill' => 'Самостоятельно разобрать главу Гиты',
            'followup_permission' => 'Да',
            'story_publish_consent' => 'Да, только с именем',
            'tried_before' => ['Бесплатный бот', 'Пробный урок'],
        ])->assertRedirect('/anketa/student-purchase-2026-09?done=1')->assertSessionHasNoErrors();

        $row = SurveyResponse::where('survey_slug', 'student-purchase-2026-09')->firstOrFail();
        $this->assertSame('Курс чтения', $row->answers['repeat_purchase']);
        // H4337: story-publish consent is a required page-6 radio, distinct from followup_permission.
        $this->assertSame('Да, только с именем', $row->answers['story_publish_consent']);
        $this->assertSame('За разбор моих ошибок', $row->answers['value_for_money_open']);
        $this->assertSame(['Бесплатный бот', 'Пробный урок'], $row->answers['tried_before']);
        $this->assertNull($row->reward_choice);
    }

    /** @test */
    public function student_purchase_wave_requires_required_fields_and_rejects_off_list_radio(): void
    {
        $this->from('/anketa/student-purchase-2026-09')
            ->post('/anketa/student-purchase-2026-09', ['website' => ''])
            ->assertSessionHasErrors(['first_course', 'first_motive_open', 'purchase_trigger', 'before_after', 'stay_reason_open', 'repeat_purchase', 'value_for_money_open', 'next_purchase_condition', 'first_discovery_open', 'main_goal', 'next_skill', 'followup_permission']);

        $this->post('/anketa/student-purchase-2026-09', [
            'first_course' => 'Хинди',
            'first_motive_open' => 'Понять кино без субтитров',
            'purchase_trigger' => 'Взломал список',
            'before_after' => 'Стал понимать отдельные фразы',
            'stay_reason_open' => 'Преподаватель',
            'repeat_purchase' => 'Не было',
            'value_for_money_open' => 'За практику',
            'next_purchase_condition' => 'Разговорный курс',
            'first_discovery_open' => 'Поиск',
            'main_goal' => 'Другое',
            'next_skill' => 'Понимать диалоги',
            'followup_permission' => 'Нет',
        ])->assertSessionHasErrors('purchase_trigger');

        $this->assertSame(0, SurveyResponse::where('survey_slug', 'student-purchase-2026-09')->count());
    }

    /** @test */
    public function onboarding_is_a_short_eight_question_survey(): void
    {
        $definition = config('surveys.definitions.onboarding');

        $this->assertCount(8, $definition['questions']);
        $this->assertArrayNotHasKey('pages', $definition);
        $this->get('/anketa/onboarding')
            ->assertOk()
            ->assertSee('Восемь коротких вопросов')
            ->assertDontSee('<button type="button" data-page-next', false);
    }
}
