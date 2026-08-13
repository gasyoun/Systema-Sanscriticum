<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Membership\ClubMembershipService;
use Illuminate\Console\Command;

/**
 * Снятие клубного права по истечении оплаченного периода (H2644).
 *
 * Продление РУЧНОЕ (авто-списаний на 01-09 нет), поэтому оплаченный период
 * заканчивается сам, и снимает его этот демон, а не банк.
 *
 * Сухой прогон по умолчанию: команда, отбирающая доступ у людей, обязана уметь
 * показать список ДО того, как отберёт.
 */
class ExpireClubMemberships extends Command
{
    protected $signature = 'membership:expire-club
        {--limit=0 : ограничить число периодов за прогон (0 = все)}
        {--apply : снять право (без флага — сухой прогон)}';

    protected $description = 'Клуб: снять группу и закрыть период у тех, чей оплаченный срок и грейс истекли (H2644).';

    public function handle(ClubMembershipService $service): int
    {
        $apply = (bool) $this->option('apply');

        if (! config('features.club_membership')) {
            $this->warn('Флаг features.club_membership ВЫКЛЮЧЕН — только отчёт, ничего не снимаем.');
            $apply = false;
        }

        if ($service->clubCourse() === null) {
            $this->error('Курс-членства со slug «'.config('membership.club.course_slug').'» не найден. '
                .'Его заводит человек в Filament (спецификация §7 п.5). Ничего не делаем.');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $report = $service->expireDue($apply, $limit > 0 ? $limit : null);

        if ($report['refused_groups'] !== []) {
            // Инвариант 3. Группа, разделяемая клубом и обычным курсом, — это
            // ошибка настройки, а не повод «всё-таки отцепить»: отцепив её, мы
            // отняли бы у человека оплаченный КУРС.
            $this->error('ОТКАЗ отцеплять группы, привязанные не только к клубному курсу: '
                .implode(', ', $report['refused_groups'])
                .' — разведите группы в админке, иначе клуб не снимается.');
        }

        if ($report['checked'] === 0) {
            $this->info('Истёкших периодов нет.');

            return self::SUCCESS;
        }

        $this->table(
            ['период', 'user', 'email', 'оплачен до', 'действие', 'отцеплено групп'],
            array_map(static fn (array $r): array => [
                $r['membership_id'],
                $r['user_id'] ?? '—',
                $r['email'] ?? '—',
                $r['ends_at'] ?? '—',
                $r['action'],
                isset($r['detached_groups']) ? count($r['detached_groups']) : 0,
            ], $report['rows']),
        );

        $this->info(($apply ? 'СНЯТО' : 'СУХОЙ ПРОГОН').': периодов '.$report['revoked']
            .' · из них продлившихся (право сохранено) '.$report['superseded']
            .' · людей с отцепленной группой '.$report['detached']);

        if (! $apply) {
            $this->line('Снять: повторить с --apply');
        }

        return self::SUCCESS;
    }
}
