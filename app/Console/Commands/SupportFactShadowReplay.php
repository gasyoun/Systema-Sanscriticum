<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportAnswerFactResolver;
use App\Services\Support\SupportAnswerSuggester;
use Illuminate\Console\Command;

/**
 * H3999 (выходные данные волны 1): теневой реплей резолверов фактов по УЖЕ
 * пришедшим сообщениям ЛС.
 *
 * Зачем отдельно от событий `dm_shadow_would_send_facts`: события копятся с
 * момента выкладки и дают неделю ожидания, а вопрос «сколько вопросов вообще
 * закрываются фактами» нужен ДО открытия любого типа. Реплей отвечает на него
 * по истории за окно — числитель И знаменатель, по категориям (рулинг I4).
 *
 * Команда ничего не отправляет и ничего не пишет: ни событий, ни черновиков,
 * ни исходящих. Резолверы читают LMS, счётчики живут в памяти процесса.
 * Замер по истории — это оценка сверху: он считает факты по СЕГОДНЯШНЕМУ
 * состоянию кабинета, а не по тому, каким оно было в день вопроса.
 */
class SupportFactShadowReplay extends Command
{
    protected $signature = 'support:fact-shadow-replay
        {--days=30 : окно истории, дней}
        {--limit=5000 : потолок разбираемых сообщений}';

    protected $description = 'H3999: сколько вопросов ЛС закрылись бы фактами кабинета (по категориям, за окно).';

    public function handle(SupportAnswerSuggester $suggester, SupportAnswerFactResolver $facts): int
    {
        $days = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));
        $since = now()->subDays($days);

        $messages = TelegramSupportMessage::query()
            ->where('direction', 'incoming')
            ->where('sent_at', '>=', $since)
            ->orderByDesc('sent_at')
            ->limit($limit)
            ->get(['id', 'text', 'telegram_support_chat_id', 'sent_at']);

        $total = $messages->count();
        $linked = 0;
        $rows = [];
        $byType = [];

        foreach ($messages as $message) {
            $user = $this->studentFor($message);

            if ($user === null) {
                // Непривязанный контакт: фактов о нём не существует в принципе —
                // это потолок волны, его меряет support:link-invite-census.
                continue;
            }

            $linked++;

            $text = trim((string) $message->text);
            $category = $text === '' ? null : $suggester->categorize($text);

            if ($category === null) {
                continue;
            }

            $rows[$category] ??= ['asked' => 0, 'resolved' => 0, 'would_send' => 0, 'draft_only' => 0, 'escalate' => 0];
            $rows[$category]['asked']++;

            $resolved = $facts->resolve($category, $user, $text);

            if ($resolved === null || trim((string) $resolved['draft']) === '') {
                continue;
            }

            $rows[$category]['resolved']++;

            $policy = (string) ($resolved['send_policy'] ?? SupportAnswerFactResolver::POLICY_DRAFT_ONLY);
            $type = (string) ($resolved['facts']['type'] ?? '—');

            $byType[$type] ??= ['resolved' => 0, 'would_send' => 0];
            $byType[$type]['resolved']++;

            match ($policy) {
                SupportAnswerFactResolver::POLICY_AUTO => $rows[$category]['would_send']++,
                SupportAnswerFactResolver::POLICY_ESCALATE => $rows[$category]['escalate']++,
                default => $rows[$category]['draft_only']++,
            };

            if ($policy === SupportAnswerFactResolver::POLICY_AUTO) {
                $byType[$type]['would_send']++;
            }
        }

        $this->info(sprintf(
            'Окно %d дн.: входящих в ЛС %d, из них с привязкой к кабинету %d.',
            $days,
            $total,
            $linked,
        ));

        if ($linked === 0) {
            $this->warn('Привязанных сообщений в окне нет — считать нечего.');

            return self::SUCCESS;
        }

        ksort($rows);

        $this->table(
            ['Категория', 'Спросили', 'Факты нашлись', 'Ушло бы само', 'Только черновик', 'Эскалация'],
            array_map(
                static fn (string $category, array $row): array => [
                    $category,
                    $row['asked'],
                    sprintf('%d / %d', $row['resolved'], $row['asked']),
                    sprintf('%d / %d', $row['would_send'], $row['asked']),
                    $row['draft_only'],
                    $row['escalate'],
                ],
                array_keys($rows),
                array_values($rows),
            ),
        );

        ksort($byType);

        $this->table(
            ['Тип факта', 'Черновиков', 'Из них ушло бы само'],
            array_map(
                static fn (string $type, array $row): array => [
                    $type,
                    $row['resolved'],
                    sprintf('%d / %d', $row['would_send'], $row['resolved']),
                ],
                array_keys($byType),
                array_values($byType),
            ),
        );

        $this->comment('Ничего не отправлено и не записано: команда только читает.');

        return self::SUCCESS;
    }

    /** Студент, которому принадлежит входящее, или null, если контакт не привязан. */
    private function studentFor(TelegramSupportMessage $message): ?User
    {
        $userId = $message->chat?->linked_user_id;

        return $userId === null ? null : User::query()->find((int) $userId);
    }
}
