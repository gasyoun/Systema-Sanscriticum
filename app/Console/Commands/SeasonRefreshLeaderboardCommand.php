<?php

namespace App\Console\Commands;

use App\Models\Season;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeasonRefreshLeaderboardCommand extends Command
{
    protected $signature = 'season:refresh-leaderboard {season_id?}';

    protected $description = 'Пересчитать сезонный лидерборд';

    public function handle(): int
    {
        $seasonId = $this->argument('season_id');

        $season = $seasonId
            ? Season::find($seasonId)
            : Season::where('is_active', true)->first();

        if (! $season) {
            $this->error('No active season found.');

            return 1;
        }

        // Пересчёт prana_earned = current lifetime_prana − baseline_lifetime_prana.
        // P2P-immune: baseline снят при season:open, а transfer() не увеличивает
        // lifetime_prana получателя, так что перекачка праны между аккаунтами
        // в сезонный счёт не попадает.
        //
        // Без `UPDATE ... JOIN` (MySQL-only) — тесты идут на SQLite, поэтому
        // читаем и пишем чанками, как остальной портируемый код в репозитории.
        $now = now();
        $updated = 0;

        DB::table('season_leaderboard_cache AS slc')
            ->join('users AS u', 'u.id', '=', 'slc.user_id')
            ->where('slc.season_id', $season->id)
            ->orderBy('slc.id')
            ->select(['slc.id', 'slc.baseline_lifetime_prana', 'u.lifetime_prana'])
            ->chunk(500, function ($rows) use ($now, &$updated): void {
                foreach ($rows as $row) {
                    DB::table('season_leaderboard_cache')
                        ->where('id', $row->id)
                        ->update([
                            'prana_earned' => max(0, (int) $row->lifetime_prana - (int) $row->baseline_lifetime_prana),
                            'computed_at' => $now,
                        ]);
                    $updated++;
                }
            });

        // Пересчёт rank_position по убыванию prana_earned. Тай-брейк по user_id,
        // иначе порядок равных значений зависит от движка БД и ранги «дрожат»
        // между прогонами. Позиции плотные (1,2,3…), ничьи не дублируются.
        $ranked = DB::table('season_leaderboard_cache')
            ->where('season_id', $season->id)
            ->orderByDesc('prana_earned')
            ->orderBy('user_id')
            ->pluck('id');

        $position = 1;
        foreach ($ranked as $id) {
            DB::table('season_leaderboard_cache')
                ->where('id', $id)
                ->update(['rank_position' => $position]);
            $position++;
        }

        $this->info("Leaderboard refreshed for season #{$season->id}. Total entries: {$updated}.");

        return 0;
    }
}
