<?php

declare(strict_types=1);

namespace Tests\Feature\Support\QuestionsWeekly;

use App\Models\SupportQuestionClassification;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportContact;
use App\Models\TelegramSupportMessage;
use App\Services\SupportQuestions\QuestionMessageClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * H5709 — стратифицированное ревью: лист пишется в защищённый storage,
 * stdout наружу отдаёт только агрегаты; импорт gold-меток считает precision
 * и покрытие; gate inconclusive при нехватке сообщений — наблюдения не
 * фабрикуются.
 */
class QuestionsReviewCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $sheet;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-02 12:00:00', 'Europe/Moscow'));
        $this->sheet = storage_path('app/support-questions/test-review-sheet.tsv');
        if (! is_dir(dirname($this->sheet))) {
            mkdir(dirname($this->sheet), 0775, true);
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->sheet);
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function seedQuestion(string $text, int $messageId): void
    {
        $account = TelegramSupportAccount::firstOrCreate(['name' => 'support'], ['is_enabled' => true]);
        $chat = TelegramSupportChat::create(['telegram_chat_id' => random_int(10000, 99999), 'type' => 'private']);
        $contact = TelegramSupportContact::create([
            'telegram_user_id' => random_int(100000, 999999),
            'telegram_support_chat_id' => $chat->id,
        ]);
        $message = TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_support_contact_id' => $contact->id,
            'telegram_chat_id' => $chat->telegram_chat_id,
            'telegram_message_id' => $messageId,
            'direction' => 'incoming',
            'text' => $text,
            'sent_at' => CarbonImmutable::parse('2026-09-29 12:00:00', 'Europe/Moscow'),
        ]);

        $verdict = app(QuestionMessageClassifier::class)->classifyMessage($text);
        SupportQuestionClassification::create([
            'telegram_support_message_id' => $message->id,
            'classifier_version' => QuestionMessageClassifier::VERSION,
            'population' => 'enquiry',
            'is_question' => true,
            'primary_category' => $verdict['primary_category'],
            'secondary_categories' => $verdict['secondary_categories'],
        ]);
    }

    public function test_sheet_written_and_stdout_is_aggregate_only(): void
    {
        foreach (range(1, 5) as $i) {
            $this->seedQuestion('сколько стоит курс №'.$i.'?', 1000 + $i);
        }

        $this->artisan('support:questions-review', [
            '--week' => '2026-09-28',
            '--sample' => 5,
            '--sheet' => $this->sheet,
        ])->assertSuccessful();

        $this->assertFileExists($this->sheet);
        $lines = file($this->sheet, FILE_IGNORE_NEW_LINES);
        $this->assertCount(6, $lines); // header + 5
        $this->assertStringContainsString("id\tpopulation\tpredicted_primary\tgold_label\ttext", $lines[0]);
    }

    public function test_inconclusive_when_sample_larger_than_eligible(): void
    {
        $this->seedQuestion('один вопрос про zoom', 2001);

        // Artisan::call: у PendingCommand ожидания вывода конфликтуют с
        // недетерминированным порядком проверок, а здесь важны код + текст.
        $exit = Artisan::call('support:questions-review', [
            '--week' => '2026-09-28',
            '--sample' => 100,
            '--sheet' => $this->sheet,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('inconclusive', $output);
        $this->assertStringContainsString('not manufactured', $output);

        // Лист не создаём: наблюдений меньше выборки, фабриковать нельзя.
        $this->assertFileDoesNotExist($this->sheet);
    }

    public function test_import_computes_precision_and_coverage(): void
    {
        // 4 корректных D + 1 ошибочный (предсказан C).
        foreach (range(1, 4) as $i) {
            $this->seedQuestion('сколько стоит курс №'.$i.'?', 3000 + $i);
        }
        $this->seedQuestion('расписание перенесли на вечер?', 3005);

        $this->artisan('support:questions-review', [
            '--week' => '2026-09-28',
            '--sample' => 5,
            '--sheet' => $this->sheet,
        ])->assertSuccessful();

        // Ревьюер внутри доверенного окружения заполняет gold_label.
        $rows = file($this->sheet, FILE_IGNORE_NEW_LINES);
        $out = [trim((string) $rows[0])];
        // Четвёртой D-строке ревьюер не соглашается (gold C) — единственный промах.
        foreach (array_slice($rows, 1) as $index => $line) {
            [$id, $population, $predicted] = explode('	', $line);
            $gold = $predicted;
            if ($index === 3) {
                $gold = 'C';
            }
            $out[] = implode("\t", [$id, $population, $predicted, $gold, 'текст скрыт']);
        }
        file_put_contents($this->sheet, implode("\n", $out)."\n");

        $exit = Artisan::call('support:questions-review', ['--import' => $this->sheet]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        // 4/5 = 0.8 < 0.93 → гейт честно провален (один промах).
        $this->assertStringContainsString('"gate": "fail"', $output);
        $this->assertStringContainsString('"precision": 0.8', $output);
        // json round-trip: round(x,4) печатается int-ом без дробной части.
        $this->assertStringContainsString('"coverage": 1,', $output);
    }

    public function test_import_rejects_unknown_gold_labels(): void
    {
        file_put_contents($this->sheet, "id\tpopulation\tpredicted_primary\tgold_label\ttext\n1\tenquiry\tD\tфигня\tx\n");

        $exit = Artisan::call('support:questions-review', ['--import' => $this->sheet]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('outside', Artisan::output());
    }
}
