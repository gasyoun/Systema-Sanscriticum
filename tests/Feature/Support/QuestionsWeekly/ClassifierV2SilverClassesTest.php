<?php

declare(strict_types=1);

namespace Tests\Feature\Support\QuestionsWeekly;

use App\Models\SupportQuestionClassification;
use App\Models\SupportQuestionReviewItem;
use App\Models\SupportQuestionReviewLabel;
use App\Models\SupportQuestionReviewSample;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportContact;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\SupportQuestions\QuestionMessageClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H5781 — регрессии v2 по классам ошибок silver-предеривки замороженного
 * листа (H5768): сервисный контент в корпусе, D→G статусы оплаты, логины
 * решавшиеся в A, покрытие C/H/D/E/F. ТОЛЬКО синтетические тексты.
 */
class ClassifierV2SilverClassesTest extends TestCase
{
    use RefreshDatabase;

    private QuestionMessageClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = app(QuestionMessageClassifier::class);
    }

    public function test_version_is_v2(): void
    {
        $this->assertSame('qw-2026-10-v2', QuestionMessageClassifier::VERSION);
    }

    /** Опс-дайджест мониторинга (форма «дайджест за дату + artisan-строки») — не вопрос. */
    public function test_ops_digest_is_not_question(): void
    {
        $text = 'Дайджест пропусков за 20-08: course 334, group 72, no-lesson. '
            .'n8n skip-soft: ключи не заданы. Запуска: recordings:gap-watch. Не перезапускать воркер с вебхука.';
        $verdict = $this->classifier->classifyMessage($text);
        $this->assertFalse($verdict['is_question']);
        $this->assertNull($verdict['primary_category']);
        $this->assertNotEmpty(array_filter(
            $verdict['signals'],
            fn (string $s): bool => str_starts_with($s, 'service_content:'),
        ));
    }

    /** Опс-дайджест с «запис… не в кабинете» и zoom-упоминанием больше не A. */
    public function test_recordings_digest_with_zoom_marker_is_not_question(): void
    {
        $text = 'Записи не в кабинете / ТГ за 23-08: course 433, group 130, no-lesson. '
            .'Конференция zoom 1.4, повтор с вебхука не запускать, resume с агента.';
        $verdict = $this->classifier->classifyMessage($text);
        // 'запис…' и 'zoom' бьют, но сервисный стем без сигналов гасит вопрос.
        $this->assertFalse($verdict['is_question']);
        $this->assertNull($verdict['primary_category']);
    }

    /** Сервисное сообщение Telegram с кодом входа — не вопрос. */
    public function test_telegram_login_code_is_not_question(): void
    {
        $text = 'Код для входа в ваш аккаунт мессенджера: 81562. '
            .'Не сообщайте его никому, даже если его требуют от имени сервиса.';
        $verdict = $this->classifier->classifyMessage($text);
        $this->assertFalse($verdict['is_question']);
        $this->assertNull($verdict['primary_category']);
    }

    /** Сервисное уведомление о попытке входа — не вопрос. */
    public function test_telegram_login_attempt_notice_is_not_question(): void
    {
        $text = 'Обнаружена попытка входа в ваш аккаунт с нового устройства 30/07 в 05:45. '
            .'Никто не получил доступ к переписке, вход не произошёл.';
        $verdict = $this->classifier->classifyMessage($text);
        $this->assertFalse($verdict['is_question']);
    }

    /** Бот-подтверждение оплаты и выдачи доступа — не вопрос. */
    public function test_bot_payment_confirmation_is_not_question(): void
    {
        $text = 'Оплата успешно получена! Ваш доступ к курсу открыт. Приступайте к занятиям.';
        $verdict = $this->classifier->classifyMessage($text);
        $this->assertFalse($verdict['is_question']);
    }

    /** Объявление персонала с реквизитами — не вопрос. */
    public function test_staff_announcement_is_not_question(): void
    {
        $text = 'Здравствуйте, уважаемые участники. Курс открыт для записи, оплату проводите '
            .'по ссылке на сайте. Чеки присылаем в канал общества.';
        $verdict = $this->classifier->classifyMessage($text);
        $this->assertFalse($verdict['is_question']);
    }

    /** Рассылка зум-приглашения (ссылка + идентификатор + код) — не вопрос. */
    public function test_zoom_invite_broadcast_is_not_question(): void
    {
        $text = 'Занятие по ссылке: подключиться к конференции zoom, идентификатор конференции: '
            .'819 8601 9545, код доступа: 822297.';
        $verdict = $this->classifier->classifyMessage($text);
        $this->assertFalse($verdict['is_question']);
    }

    /** `?pwd=` внутри URL — не вопросительный знак; голая зум-ссылка — не вопрос. */
    public function test_url_query_params_are_not_question_signals(): void
    {
        $verdict = $this->classifier->classifyMessage(
            'https://zoom.example/j/83231970050?pwd=4sEc9fRDI37ZhA8Aq7fl2w2H7Y2UbO.1'
        );
        $this->assertFalse($verdict['is_question']);
        $this->assertNull($verdict['primary_category']);
    }

    /** Приглашение зума (текст + ссылка с ?pwd=) гасится сервисной полосой. */
    public function test_zoom_invite_with_pwd_url_is_not_question(): void
    {
        $text = 'Занятие по этой ссылке, около 1 часа: подключиться к конференции zoom '
            .'https://zoom.example/j/81986019545?pwd=xCWBV6uGHhrx1han4wtWwNwe068yJ6.1 '
            .'код доступа: 822297';
        $verdict = $this->classifier->classifyMessage($text);
        $this->assertFalse($verdict['is_question']);
        $this->assertNull($verdict['primary_category']);
    }

    /** Ссылка-артефакт не мешает настоящему вопросу рядом с ней. */
    public function test_real_question_next_to_url_still_classifies(): void
    {
        $verdict = $this->classifier->classifyMessage(
            'Не могу зайти в личный кабинет, ссылка https://example.com/login?next=/cabinet не работает?'
        );
        $this->assertTrue($verdict['is_question']);
        $this->assertSame('E', $verdict['primary_category']);
    }

    /** Студент с вопросом, цитирующий служебную фразу, классифицируется как раньше. */
    public function test_student_question_quoting_service_phrase_stays_question(): void
    {
        $verdict = $this->classifier->classifyMessage('Подскажите, какой код доступа к конференции? Не могу подключиться.');
        $this->assertTrue($verdict['is_question']);
        $this->assertNotNull($verdict['primary_category']);

        $verdict2 = $this->classifier->classifyMessage('Код для входа не приходит на почту, что делать?');
        $this->assertTrue($verdict2['is_question']);
    }

    /** D→G: короткий отчёт об оплате — статус (G), а не инструкция (D). */
    public function test_payment_status_reports_resolve_to_g(): void
    {
        $cases = [
            'Я оплатила',
            'За второй блок оплатила, спасибо',
            'Я оплатил вчера вечером',
            'Оплата за курс грамматики прошла',
            'У меня оплачена предоплата, но доступ закрыт',
            'Сколько оплачено и до какого времени?',
        ];
        foreach ($cases as $text) {
            $verdict = $this->classifier->classifyMessage($text);
            $this->assertSame('G', $verdict['primary_category'], "expected G for: {$text}");
        }
    }

    /** «Как оплатить» остаётся D. */
    public function test_payment_instructions_stay_d(): void
    {
        $verdict = $this->classifier->classifyMessage('Сколько стоит и как оплатить второй блок?');
        $this->assertSame('D', $verdict['primary_category']);
    }

    /** Логин в кабинет больше не решается в A. */
    public function test_cabinet_login_is_e_not_a(): void
    {
        $verdict = $this->classifier->classifyMessage('Не могу зайти в личный кабинет');
        $this->assertSame('E', $verdict['primary_category']);
        $verdict2 = $this->classifier->classifyMessage('Не могу зайти в зум, ссылка не открывается');
        $this->assertSame('A', $verdict2['primary_category']);
    }

    /** Новое покрытие C/H/D/E/F. */
    public function test_v2_coverage_additions(): void
    {
        $cases = [
            'C' => [
                'Будет ли курс хинди в ближайшее время?',
                'А уже начался курс?',
                'Когда набор на курс Бюллера?',
                'Сколько занятий прошло на текущий момент?',
            ],
            'H' => [
                'Группа набралась?',
                'Много участников на продвинутом уровне?',
                'Можно мне в группу среды?',
                'Хочу сделать перерыв в этом месяце',
            ],
            'D' => [
                'Какая сумма, если я попадаю только на два занятия?',
                'Сколько нужно перевести за блок?',
            ],
            'E' => [
                'Добавьте мой адрес на сайт, хочу зарегистрироваться',
                'Сайт не открывается ни под одним соединением',
            ],
            'F' => [
                'Книги пришли, пришло уведомление',
            ],
        ];
        foreach ($cases as $letter => $texts) {
            foreach ($texts as $text) {
                $verdict = $this->classifier->classifyMessage($text);
                $this->assertSame($letter, $verdict['primary_category'], "expected {$letter} for: {$text}");
            }
        }
    }

    /**
     * H5781 carry-over: human gold замороженного сэпма переносится на
     * предсказания ТЕКУЩЕЙ версии; вердикт считается по новым предсказаниям.
     */
    public function test_carryover_gold_measures_current_version_predictions(): void
    {
        // Три сообщения с классификацией «старой» версии (сэмпл заморожен под неё).
        $frozen = [];
        foreach (['Демо-текст оплаты номер один', 'Демо-текст доступа номер два', 'Демо-текст расписания номер три'] as $i => $text) {
            $message = $this->makeMessage(7001 + $i);
            $frozen[] = SupportQuestionClassification::create([
                'telegram_support_message_id' => $message->id,
                'classifier_version' => 'qw-2026-10-v1',
                'population' => 'enquiry',
                'is_question' => true,
                'primary_category' => 'D',
            ]);
        }

        $sample = SupportQuestionReviewSample::create([
            'window_from' => '2026-09-28',
            'window_to' => '2026-10-05',
            'classifier_version' => 'qw-2026-10-v1',
            'sample_size' => 3,
            'fingerprint' => str_repeat('c', 64),
            'status' => SupportQuestionReviewSample::STATUS_OPEN,
        ]);
        foreach ($frozen as $i => $classification) {
            $item = SupportQuestionReviewItem::create([
                'sample_id' => $sample->id,
                'classification_id' => $classification->id,
                'population' => 'enquiry',
                'predicted_primary' => 'D',
                'position' => $i + 1,
            ]);
            SupportQuestionReviewLabel::create([
                'sample_id' => $sample->id,
                'item_id' => $item->id,
                'user_id' => User::factory()->create(['role' => 'admin'])->id,
                'gold_label' => 'D',
            ]);
        }

        // Текущая версия: у первого предсказание изменилось (D→G), у второго
        // совпало (D), у третьего строки v2 нет → unclassified.
        SupportQuestionClassification::create([
            'telegram_support_message_id' => $frozen[0]->message->id,
            'classifier_version' => QuestionMessageClassifier::VERSION,
            'population' => 'enquiry',
            'is_question' => true,
            'primary_category' => 'G',
        ]);
        SupportQuestionClassification::create([
            'telegram_support_message_id' => $frozen[1]->message->id,
            'classifier_version' => QuestionMessageClassifier::VERSION,
            'population' => 'enquiry',
            'is_question' => true,
            'primary_category' => 'D',
        ]);

        $exit = \Artisan::call('support:questions-review', ['--carryover-gold' => (string) $sample->id, '--sample' => '3']);
        $output = \Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('"carried_labels":3', $output);
        $this->assertStringContainsString('"measured_version":"'.QuestionMessageClassifier::VERSION.'"', $output);
        $this->assertStringContainsString('"n_predictions": 2', $output);
        $this->assertStringContainsString('"matches": 1', $output);
        $this->assertStringContainsString('"precision": 0.5', $output);
    }

    /** Незавершённый сэмпл carry-over отвергает. */
    public function test_carryover_refuses_incomplete_sample(): void
    {
        $message = $this->makeMessage(8001);
        $classification = SupportQuestionClassification::create([
            'telegram_support_message_id' => $message->id,
            'classifier_version' => 'qw-2026-10-v1',
            'population' => 'enquiry',
            'is_question' => true,
            'primary_category' => 'D',
        ]);
        $sample = SupportQuestionReviewSample::create([
            'window_from' => '2026-09-28',
            'window_to' => '2026-10-05',
            'classifier_version' => 'qw-2026-10-v1',
            'sample_size' => 3,
            'fingerprint' => str_repeat('d', 64),
            'status' => SupportQuestionReviewSample::STATUS_OPEN,
        ]);
        SupportQuestionReviewItem::create([
            'sample_id' => $sample->id,
            'classification_id' => $classification->id,
            'population' => 'enquiry',
            'predicted_primary' => 'D',
            'position' => 1,
        ]);

        $this->artisan('support:questions-review', ['--carryover-gold' => (string) $sample->id, '--sample' => '3'])
            ->expectsOutputToContain('incomplete')
            ->assertExitCode(1);
    }

    /**
     * Сид входящего сообщения поддержки (как в GoldReviewTest, но без
     * привязки к текущей версии классификатора).
     */
    private function makeMessage(int $telegramMessageId): TelegramSupportMessage
    {
        $account = TelegramSupportAccount::firstOrCreate(['name' => 'support'], ['is_enabled' => true]);
        $chat = TelegramSupportChat::create(['telegram_chat_id' => random_int(100000, 999999), 'type' => 'private']);
        $contact = TelegramSupportContact::create([
            'telegram_user_id' => random_int(1000000, 9999999),
            'telegram_support_chat_id' => $chat->id,
        ]);

        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_support_contact_id' => $contact->id,
            'telegram_chat_id' => $chat->telegram_chat_id,
            'telegram_message_id' => $telegramMessageId,
            'direction' => 'incoming',
            'text' => 'Синтетический демо-текст '.$telegramMessageId,
            'sent_at' => CarbonImmutable::parse('2026-09-29 12:00:00', 'Europe/Moscow'),
        ]);
    }
}
