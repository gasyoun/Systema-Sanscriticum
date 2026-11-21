<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TelegramStudentQuestion;
use Illuminate\Console\Command;

/**
 * H8527: машиночитаемый список «вопрос-стопочки» для проактивной агентной
 * обработки MG. Только чтение; статусы меняются отдельно (вручную/агентом).
 */
class StudentQuestionsExport extends Command
{
    protected $signature = 'zapisi:student-questions
        {--status=new : Фильтр по статусу (new|answered|dismissed)}
        {--kind= : Фильтр по типу (question|proposal)}
        {--limit=200 : Максимум строк}
        {--json : JSON на stdout (иначе — таблица)}';

    protected $description = 'Экспорт стопочки студенческих вопросов zapisi-бота (H8527)';

    public function handle(): int
    {
        $query = TelegramStudentQuestion::query()
            ->orderByDesc('id')
            ->limit(max(1, (int) $this->option('limit')));

        $status = (string) $this->option('status');
        if ($status !== '') {
            $query->where('status', $status);
        }

        $kind = (string) $this->option('kind');
        if ($kind !== '') {
            $query->where('kind', $kind);
        }

        $rows = $query->get()->map(fn (TelegramStudentQuestion $question): array => [
            'id' => $question->id,
            'chat_id' => $question->telegram_chat_id,
            'message_id' => $question->telegram_message_id,
            'group_id' => $question->group_id,
            'kind' => $question->kind,
            'post_kind' => $question->post_kind,
            'status' => $question->status,
            'author' => [
                'telegram_id' => $question->author_telegram_id,
                'name' => $question->author_name,
                'username' => $question->author_username,
            ],
            'question' => $question->question_text,
            'reply_to_message_id' => $question->reply_to_message_id,
            'replied_post_excerpt' => $question->replied_post_excerpt,
            'draft' => [
                'state' => $question->curator_draft_state,
                'model' => $question->curator_draft_model,
                'text' => $question->curator_draft,
            ],
            'sent_at' => $question->sent_at?->toIso8601String(),
            'created_at' => $question->created_at?->toIso8601String(),
        ])->all();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->info('Стопочка пуста.');

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $this->line(sprintf(
                '#%d [%s/%s/%s] chat %s msg %d: %s',
                $row['id'],
                $row['kind'],
                $row['post_kind'] ?? '-',
                $row['status'],
                $row['chat_id'],
                $row['message_id'],
                mb_substr((string) $row['question'], 0, 120),
            ));
        }

        return self::SUCCESS;
    }
}
