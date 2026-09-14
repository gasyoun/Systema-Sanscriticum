<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Списки рассылки для анкет волны 2 (рулинг MG 25-08-2026): куратор получает
 * готовый CSV, а не ручной выгрузкой из CRM.
 *
 * - churn-block: оплатили блок N курса, но не пришли за N+1 (при том что
 *   более высокий блок курса кто-то покупал — значит, курс длиннее);
 * - post3m: ≥2 оплаченных блока и первый платёж старше 90 дней.
 */
class SurveyAudienceCommand extends Command
{
    protected $signature = 'survey:audience {slug : churn-block или post3m}';

    protected $description = 'CSV-список получателей анкеты churn-block / post3m';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');

        $rows = match ($slug) {
            'churn-block' => $this->churnBlock(),
            'post3m' => $this->postThreeMonths(),
            default => null,
        };

        if ($rows === null) {
            $this->error('slug должен быть churn-block или post3m');

            return self::FAILURE;
        }

        if ($rows === []) {
            $this->warn('Никого не найдено — CSV не создан.');

            return self::SUCCESS;
        }

        $path = storage_path('app/survey-audience/'.$slug.'-'.now()->format('Ymd-Hi').'.csv');
        @mkdir(dirname($path), 0775, true);

        $out = fopen($path, 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_keys($rows[0]), ';');
        foreach ($rows as $row) {
            fputcsv($out, $row, ';');
        }
        fclose($out);

        $this->info('Строк: '.count($rows));
        $this->info($path);

        return self::SUCCESS;
    }

    /** Оплатили блок N, но не N+1 — и N+1 в принципе существует у курса. */
    private function churnBlock(): array
    {
        $perUser = DB::table('payments as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->join('courses as c', 'c.id', '=', 'p.course_id')
            ->whereIn('p.status', ['paid', 'success'])
            ->where('p.tariff', 'like', 'block_%')
            ->groupBy('u.id', 'u.name', 'u.email', 'u.telegram_id', 'c.id', 'c.title')
            ->orderByDesc('max_block')
            ->get([
                'u.id',
                'u.name',
                'u.email',
                'u.telegram_id',
                'c.id as course_id',
                'c.title as course',
                DB::raw('MAX(CAST(SUBSTRING(p.tariff, 7) AS UNSIGNED)) as max_block'),
                DB::raw('MAX(p.created_at) as last_payment'),
            ]);

        $courseMax = DB::table('payments')
            ->whereIn('status', ['paid', 'success'])
            ->where('tariff', 'like', 'block_%')
            ->selectRaw('course_id, MAX(CAST(SUBSTRING(tariff, 7) AS UNSIGNED)) as top_block')
            ->groupBy('course_id')
            ->pluck('top_block', 'course_id');

        return $perUser
            ->filter(fn ($r) => (int) $r->max_block < (int) ($courseMax[$r->course_id] ?? 0))
            ->map(fn ($r) => [
                'Имя' => $r->name,
                'Email' => (string) $r->email,
                'Telegram ID' => (string) $r->telegram_id,
                'Курс' => $r->course,
                'Оплачен блок' => $r->max_block,
                'Последний платёж' => substr((string) $r->last_payment, 0, 10),
            ])
            ->values()->all();
    }

    /** ≥2 разных оплаченных блока, первый из них старше 90 дней. */
    private function postThreeMonths(): array
    {
        $since = now()->subDays(90)->toDateString();

        return DB::table('payments as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->whereIn('p.status', ['paid', 'success'])
            ->where('p.tariff', 'like', 'block_%')
            ->groupBy('u.id', 'u.name', 'u.email', 'u.telegram_id')
            ->havingRaw('COUNT(DISTINCT p.tariff) >= 2 AND MIN(DATE(p.created_at)) <= ?', [$since])
            ->orderBy('u.name')
            ->get([
                'u.id',
                'u.name',
                'u.email',
                'u.telegram_id',
                DB::raw('COUNT(DISTINCT p.tariff) as blocks_paid'),
                DB::raw('MIN(DATE(p.created_at)) as first_block'),
                DB::raw('MAX(DATE(p.created_at)) as last_payment'),
            ])
            ->map(fn ($r) => [
                'Имя' => $r->name,
                'Email' => (string) $r->email,
                'Telegram ID' => (string) $r->telegram_id,
                'Оплачено блоков' => $r->blocks_paid,
                'Первый блок' => $r->first_block,
                'Последний платёж' => $r->last_payment,
            ])
            ->all();
    }
}
