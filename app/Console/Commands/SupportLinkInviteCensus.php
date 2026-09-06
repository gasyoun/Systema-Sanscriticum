<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Access\TelegramAdminNotifier;
use App\Services\Support\SupportDmLinkInvite;
use Illuminate\Console\Command;

/**
 * H3999 (шаг I3): недельный список контактов для РУЧНОЙ привязки — кто написал
 * в ЛС не меньше двух раз и всё ещё не связан с кабинетом.
 *
 * Зачем отдельно от {@see SupportSendLinkInvites}: та команда ОТПРАВЛЯЕТ
 * приглашения и живёт под cooldown'ом; эта ничего не отправляет студентам и
 * измеряет потолок волны 1 — сколько вопросов резолверы фактов не могут
 * решить в принципе, потому что не знают, о ком речь (риск 1 регистра рисков).
 *
 * Сопоставления по телефону или @username здесь нет: неверное сопоставление
 * ответило бы одному студенту остатком другого. Привязка остаётся ручной.
 */
class SupportLinkInviteCensus extends Command
{
    protected $signature = 'support:link-invite-census
        {--days=7 : окно, дней}
        {--min=2 : минимум входящих сообщений от контакта}
        {--dry : не отправлять сводку в Telegram}';

    protected $description = 'H3999: недельная сводка незалинкованных контактов с 2+ сообщениями (для ручной привязки).';

    public function handle(SupportDmLinkInvite $invites, TelegramAdminNotifier $notifier): int
    {
        $days = max(1, (int) $this->option('days'));
        $min = max(1, (int) $this->option('min'));
        $dry = (bool) $this->option('dry');

        $census = $invites->census($days);
        $rows = $invites->unlinkedWithMessages($days, $min);

        // Знаменатель печатается рядом с числителем всегда: «12 контактов» без
        // «из скольких» — это не метрика, а впечатление (рулинг I4).
        $lines = [
            '🔗 <b>Незалинкованные контакты</b> (окно '.$days.' дн.)',
            'Всего без связи с кабинетом: '.$census['unlinked_recent']
                .' · на аккаунтах с автоответом: '.$census['unlinked_recent_gated'],
            'Из них написали ≥'.$min.' раз: '.count($rows),
        ];

        if ($rows === []) {
            $lines[] = '';
            $lines[] = 'Привязывать вручную некого.';
        } else {
            $lines[] = '';
            foreach (array_slice($rows, 0, 20) as $row) {
                $lines[] = sprintf(
                    '· чат %d — %d сообщ., последнее %s%s',
                    $row['chat_id'],
                    $row['messages'],
                    $row['last_seen'] ?? '—',
                    $row['invited_at'] === null ? '' : ' (приглашение уже отправлялось)',
                );
            }

            if (count($rows) > 20) {
                $lines[] = '… и ещё '.(count($rows) - 20).'.';
            }
        }

        // Ни имени, ни @username, ни телефона: сводку читает человек в Telegram,
        // а идентификатор чата достаточен, чтобы открыть диалог (приватность —
        // тот же контракт, что у SupportDmLinkInvite).
        $text = implode("\n", $lines);
        $this->line(strip_tags($text));

        if ($dry) {
            $this->comment('--dry: Telegram не отправлен.');

            return self::SUCCESS;
        }

        if ($notifier->notifyAdmins($text) === []) {
            $this->error('Сводка не доставлена: нет TELEGRAM_BOT_TOKEN / ADMIN_TELEGRAM_ID или API отказал.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
