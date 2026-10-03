<?php

declare(strict_types=1);

namespace Tests\Feature\Support\QuestionsWeekly;

use App\Filament\Pages\SupportQuestionsGoldReview;
use App\Models\SupportQuestionClassification;
use App\Models\SupportQuestionReviewItem;
use App\Models\SupportQuestionReviewSample;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportContact;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\SupportQuestions\GoldReviewException;
use App\Services\SupportQuestions\GoldReviewService;
use App\Services\SupportQuestions\QuestionMessageClassifier;
use App\Services\SupportQuestions\StaleGoldSampleException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H5773 — защищённое gold-ревью: доступ (гость/студент/преподаватель против
 * admin/manager), слепая разметка (предсказание скрыто до записи метки),
 * экранирование текста, save/resume, анти-дубликаты, устаревшая версия
 * классификатора, экспорт без текстов и интеграция с импортёром
 * support:questions-review (гейт — только его вердикт).
 */
class SupportQuestionsGoldReviewTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = '/admin/telegram-support/support-questions-gold-review';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-02 12:00:00', 'Europe/Moscow'));
        if (! is_dir(storage_path('app/support-questions'))) {
            mkdir(storage_path('app/support-questions'), 0775, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/support-questions/gold-freeze-*.tsv')) ?: [] as $leftover) {
            @unlink($leftover);
        }
        foreach (glob(storage_path('app/support-questions/gold-*.tsv')) ?: [] as $leftover) {
            @unlink($leftover);
        }
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function seedQuestion(string $text, int $messageId): SupportQuestionClassification
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

        return SupportQuestionClassification::create([
            'telegram_support_message_id' => $message->id,
            'classifier_version' => QuestionMessageClassifier::VERSION,
            'population' => 'enquiry',
            'is_question' => true,
            'primary_category' => $verdict['primary_category'],
            'secondary_categories' => $verdict['secondary_categories'],
        ]);
    }

    /**
     * Заморозить выборку из $n синтетических сообщений (уникальные маркеры
     * TEXT-i, первый — при желании враждебный HTML).
     */
    private function freezeSample(int $n): SupportQuestionReviewSample
    {
        foreach (range(1, $n) as $i) {
            $this->seedQuestion('TEXT-'.$i.' сколько стоит курс?', 2000 + $i);
        }

        $agg = app(GoldReviewService::class)->freezeFromCommand(
            ['from' => '2026-09-28', 'to' => '2026-10-05'],
            $n,
            null,
        );
        $this->assertSame(true, $agg['frozen'], 'freeze must succeed on a sufficient corpus');

        return SupportQuestionReviewSample::query()->findOrFail($agg['sample_id']);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(self::PAGE)->assertRedirect('/admin/login');
    }

    public function test_ordinary_student_is_forbidden(): void
    {
        $student = User::factory()->create();

        $this->actingAs($student)->get(self::PAGE)->assertForbidden();
    }

    /** @dataProvider deniedRoles */
    public function test_staff_without_review_authority_is_forbidden(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get(self::PAGE)->assertForbidden();
    }

    /** @return list<list<string>> */
    public static function deniedRoles(): array
    {
        return [['teacher'], ['accountant']];
    }

    /** @dataProvider allowedRoles */
    public function test_review_authority_sees_page(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $this->freezeSample(3);

        $response = $this->actingAs($user)->get(self::PAGE);

        $response->assertOk();
        $this->assertStringContainsString('Gold-ревью вопросов', (string) $response->getContent());
    }

    /** @return list<list<string>> */
    public static function allowedRoles(): array
    {
        return [['admin'], ['manager'], ['super_admin']];
    }

    public function test_freeze_creates_frozen_sample_and_deletes_temp_sheet(): void
    {
        $sample = $this->freezeSample(5);

        $this->assertSame(5, $sample->sample_size);
        $this->assertSame(SupportQuestionReviewSample::STATUS_OPEN, $sample->status);
        $this->assertSame(QuestionMessageClassifier::VERSION, $sample->classifier_version);
        $this->assertSame(64, strlen($sample->fingerprint));
        $this->assertSame(5, $sample->items()->count());
        $this->assertSame(5, SupportQuestionReviewItem::query()->whereBetween('position', [1, 5])->count());
        $this->assertSame([], glob(storage_path('app/support-questions/gold-freeze-*.tsv')));
    }

    public function test_freeze_is_idempotent_on_same_membership(): void
    {
        $first = $this->freezeSample(4);

        $again = app(GoldReviewService::class)->freezeFromCommand(
            ['from' => '2026-09-28', 'to' => '2026-10-05'],
            4,
            null,
        );

        $this->assertSame($first->id, $again['sample_id']);
        $this->assertSame(1, SupportQuestionReviewSample::query()->count());
    }

    public function test_freeze_refuses_prefilled_gold_sheet(): void
    {
        $classification = $this->seedQuestion('TEXT-prefilled сколько стоит курс?', 3001);
        $path = storage_path('app/support-questions/gold-freeze-prefilled.tsv');
        file_put_contents($path, "id\tpopulation\tpredicted_primary\tgold_label\ttext\n"
            .$classification->id."\tenquiry\tD\tD\tpredictions are never gold\n");

        try {
            app(GoldReviewService::class)->freezeFromSheet($path, '2026-09-28..2026-10-05', null);
            $this->fail('prefilled gold sheet must be refused');
        } catch (GoldReviewException $e) {
            $this->assertStringContainsString('pre-filled gold', $e->getMessage());
        } finally {
            @unlink($path);
        }

        $this->assertSame(0, SupportQuestionReviewSample::query()->count());
    }

    public function test_prediction_hidden_until_gold_saved_and_revealed_after(): void
    {
        $sample = $this->freezeSample(3);
        $admin = User::factory()->create(['role' => 'admin']);

        $html = (string) $this->actingAs($admin)->get(self::PAGE)->getContent();
        $this->assertStringContainsString('TEXT-', $html);
        $this->assertStringNotContainsString('Модель:', $html);
        $this->assertStringNotContainsString('data-prediction', $html);
        // Слепая разметка и на уровне сериализации: атрибута нет даже в
        // Livewire-снапшоте, пока метка не записана (makeHidden).
        $this->assertStringNotContainsString('predicted_primary', $html);

        $item = $sample->items()->orderBy('position')->first();
        Livewire::actingAs($admin)
            ->test(SupportQuestionsGoldReview::class)
            ->call('saveLabel', 'D')
            ->call('goTo', 1)
            ->assertSee('Модель:');

        $this->assertDatabaseHas('support_question_review_labels', [
            'item_id' => $item->id,
            'gold_label' => 'D',
        ]);
    }

    public function test_freeze_rejects_sheet_with_drifted_prediction(): void
    {
        $classification = $this->seedQuestion('TEXT-drift сколько стоит курс?', 8001);
        $predicted = $classification->primary_category ?? 'unclassified';
        $wrong = $predicted === 'D' ? 'A' : 'D';

        $path = storage_path('app/support-questions/gold-freeze-drift.tsv');
        file_put_contents($path, "id\tpopulation\tpredicted_primary\tgold_label\ttext\n"
            .$classification->id."\tenquiry\t".$wrong."\t\ttext\n");

        try {
            app(GoldReviewService::class)->freezeFromSheet($path, '2026-09-28..2026-10-05', null);
            $this->fail('drifted predicted label must be refused');
        } catch (GoldReviewException $e) {
            $this->assertStringContainsString('drifted', $e->getMessage());
        } finally {
            @unlink($path);
        }

        $this->assertSame(0, SupportQuestionReviewSample::query()->count());
    }

    public function test_only_current_item_text_is_rendered(): void
    {
        $this->freezeSample(4);
        $manager = User::factory()->create(['role' => 'manager']);

        $html = (string) $this->actingAs($manager)->get(self::PAGE)->getContent();

        // На экране — текст только текущего сообщения, не всей выборки.
        $this->assertSame(1, substr_count($html, 'TEXT-'));
    }

    public function test_hostile_message_text_is_escaped(): void
    {
        $classification = $this->seedQuestion('<script>alert("xss")</script> сколько стоит курс?', 4001);
        foreach (range(2, 4) as $i) {
            $this->seedQuestion('TEXT-'.$i.' сколько стоит курс?', 4000 + $i);
        }

        $agg = app(GoldReviewService::class)->freezeFromCommand(
            ['from' => '2026-09-28', 'to' => '2026-10-05'], 4, null);
        $this->assertSame(true, $agg['frozen']);

        $admin = User::factory()->create(['role' => 'admin']);
        $html = (string) $this->actingAs($admin)->get(self::PAGE)->getContent();

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
    }

    public function test_save_and_resume_cursor_moves_to_first_unlabeled(): void
    {
        $sample = $this->freezeSample(3);
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(SupportQuestionsGoldReview::class)
            ->call('saveLabel', 'D')
            ->call('saveLabel', 'not_question');

        $this->assertSame(2, $sample->labels()->count());

        // Новая сессия: курсор обязан встать на первую неразмеченную (3).
        Livewire::actingAs($admin)
            ->test(SupportQuestionsGoldReview::class)
            ->assertSet('at', 3);
    }

    public function test_duplicate_submitted_ids_do_not_double_count(): void
    {
        $sample = $this->freezeSample(2);
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(SupportQuestionsGoldReview::class)
            ->call('saveLabel', 'D')
            ->call('goTo', 1)
            ->call('saveLabel', 'D');

        $this->assertSame(1, $sample->labels()->count());
        $this->assertSame(1, $sample->items()->first()->label()->count());
    }

    public function test_invalid_gold_label_rejected(): void
    {
        $sample = $this->freezeSample(2);
        $admin = User::factory()->create(['role' => 'admin']);
        $item = $sample->items()->orderBy('position')->first();

        try {
            app(GoldReviewService::class)->setGoldLabel($sample, $item->id, $admin, 'Z');
            $this->fail('invalid gold label must be refused');
        } catch (GoldReviewException $e) {
            $this->assertStringContainsString('Invalid gold label', $e->getMessage());
        }

        $this->assertSame(0, $sample->labels()->count());
    }

    public function test_item_from_another_sample_is_rejected(): void
    {
        $sampleA = $this->freezeSample(2);

        // Второй сэмпл — над ДРУГИМ составом (те 2 + ещё 2): fingerprint иной.
        foreach (range(1, 2) as $i) {
            $this->seedQuestion('TEXT-other-'.$i.' сколько стоит курс?', 7000 + $i);
        }
        $aggB = app(GoldReviewService::class)->freezeFromCommand(
            ['from' => '2026-09-28', 'to' => '2026-10-05'], 4, null);
        $this->assertSame(true, $aggB['frozen']);
        $sampleB = SupportQuestionReviewSample::query()->findOrFail($aggB['sample_id']);
        $this->assertNotSame($sampleA->id, $sampleB->id);

        $admin = User::factory()->create(['role' => 'admin']);
        $foreignItem = $sampleB->items()->first();

        try {
            app(GoldReviewService::class)->setGoldLabel($sampleA, $foreignItem->id, $admin, 'D');
            $this->fail('foreign item must be refused');
        } catch (GoldReviewException $e) {
            $this->assertStringContainsString('does not belong', $e->getMessage());
        }

        try {
            app(GoldReviewService::class)->setGoldLabel($sampleA, 999999, $admin, 'D');
            $this->fail('missing item must be refused');
        } catch (GoldReviewException) {
            // ок
        }

        $this->assertSame(0, $sampleA->labels()->count());
    }

    public function test_stale_classifier_version_blocks_labeling(): void
    {
        $classification = $this->seedQuestion('TEXT-stale сколько стоит курс?', 5001);
        $sample = SupportQuestionReviewSample::create([
            'window_from' => '2026-09-28',
            'window_to' => '2026-10-05',
            'classifier_version' => 'qw-2026-09-v0',
            'sample_size' => 1,
            'fingerprint' => str_repeat('a', 64),
            'status' => SupportQuestionReviewSample::STATUS_OPEN,
        ]);
        SupportQuestionReviewItem::create([
            'sample_id' => $sample->id,
            'classification_id' => $classification->id,
            'population' => 'enquiry',
            'predicted_primary' => $classification->primary_category ?? 'unclassified',
            'position' => 1,
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        try {
            app(GoldReviewService::class)->setGoldLabel($sample, $sample->items()->first()->id, $admin, 'D');
            $this->fail('stale sample must refuse new labels');
        } catch (StaleGoldSampleException $e) {
            $this->assertStringContainsString('freeze a new sample', $e->getMessage());
        }

        $html = (string) $this->actingAs($admin)->get(self::PAGE)->getContent();
        $this->assertStringContainsString('устарела', $html);
    }

    public function test_export_refused_until_every_row_is_labeled(): void
    {
        $sample = $this->freezeSample(3);
        $admin = User::factory()->create(['role' => 'admin']);
        $items = $sample->items()->orderBy('position')->get();
        $service = app(GoldReviewService::class);

        $service->setGoldLabel($sample, $items[0]->id, $admin, 'D');
        $service->setGoldLabel($sample, $items[1]->id, $admin, 'A');

        try {
            $service->exportGoldSheet($sample);
            $this->fail('incomplete sample must not export');
        } catch (GoldReviewException $e) {
            $this->assertStringContainsString('not complete', $e->getMessage());
        }
    }

    public function test_completion_export_has_no_texts_and_verdict_comes_from_importer(): void
    {
        $sample = $this->freezeSample(3);
        $admin = User::factory()->create(['role' => 'admin']);
        $service = app(GoldReviewService::class);

        foreach ($sample->items()->orderBy('position')->get() as $item) {
            $service->setGoldLabel($sample, $item->id, $admin, 'D');
        }

        $sample->refresh();
        $this->assertSame(SupportQuestionReviewSample::STATUS_COMPLETED, $sample->status);
        $this->assertNotNull($sample->completed_at);

        $path = $service->exportGoldSheet($sample);
        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);
        $this->assertSame("id\tpopulation\tpredicted_primary\tgold_label", explode("\n", $content)[0]);
        $this->assertStringNotContainsString('text', explode("\n", $content)[0]);
        $this->assertStringNotContainsString('TEXT-', $content); // тексты не покидают БД

        $result = $service->verdictViaImporter($sample);
        $this->assertIsArray($result['verdict']);
        $this->assertSame(3, $result['verdict']['n_labeled']);
        $this->assertArrayHasKey('precision', $result['verdict']);
        $this->assertArrayHasKey('gate', $result['verdict']);
        // Лист с метками не остаётся на диске после вердикта (пересоздаётся
        // экспортом по требованию) — минимальный след в защищённой зоне.
        $this->assertFileDoesNotExist($path);
    }

    public function test_page_freeze_action_wires_service(): void
    {
        foreach (range(1, 2) as $i) {
            $this->seedQuestion('TEXT-freeze-'.$i.' сколько стоит курс?', 6000 + $i);
        }
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(SupportQuestionsGoldReview::class)
            ->set('freezeFrom', '2026-09-28')
            ->set('freezeTo', '2026-10-05')
            ->set('freezeSize', 2)
            ->call('runFreeze')
            ->assertNotSet('sampleId', null);

        $this->assertSame(1, SupportQuestionReviewSample::query()->count());
        $this->assertSame(2, SupportQuestionReviewSample::query()->first()->items()->count());
    }
}
