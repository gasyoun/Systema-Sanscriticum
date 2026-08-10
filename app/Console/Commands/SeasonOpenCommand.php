<?php

namespace App\Console\Commands;

use App\Models\Season;
use App\Models\SeasonLeaderboardCache;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeasonOpenCommand extends Command
{
    protected $signature = 'season:open {season_id?}';

    protected $description = 'Открыть игровой сезон и включить decay';

    public function handle(): int
    {
        $seasonId = $this->argument('season_id');

        if ($seasonId) {
            $season = Season::find($seasonId);
            if (! $season) {
                $this->error("Season #{$seasonId} not found.");
                return 1;
            }
        } else {
            // Создать новый сезон, если ID не указан
            $season = Season::create([
                'title' => 'Сезон 1: Осень 2026',
                'slug' => 'autumn-2026',
                'started_at' => now(),
                'is_active' => true,
                'enabled_packs' => ['verb-roots', 'ligatures', 'genders', 'sandhi', 'compounds', 'roots-derivatives', 'homonyms'],
                'rewards_config' => [
                    ['position' => 1, 'type' => 'prana', 'amount' => 5000],
                    ['position' => 2, 'type' => 'prana', 'amount' => 3000],
                    ['position' => 3, 'type' => 'prana', 'amount' => 2000],
                ],
            ]);
        }

        // Установить started_at и is_active
        $season->update([
            'started_at' => $season->started_at ?? now(),
            'is_active' => true,
        ]);

        // Инициализировать season_leaderboard_cache для всех пользователей
        $this->info('Инициализация leaderboard cache...');
        $users = User::all(['id', 'lifetime_prana']);

        foreach ($users as $user) {
            SeasonLeaderboardCache::updateOrInsert(
                ['season_id' => $season->id, 'user_id' => $user->id],
                [
                    'baseline_lifetime_prana' => $user->lifetime_prana,
                    'prana_earned' => 0,
                    'rank_position' => 0,
                    'computed_at' => now(),
                ]
            );
        }

        $this->info("Season #{$season->id} opened. Leaderboard baseline set for " . $users->count() . ' users.');

        // Включить decay через .env (§9 PLAN — @DECIDE mechanism)
        // Временное решение: вывести инструкцию оператору
        $this->warn('MANUAL STEP: Set PRANA_DECAY_ENABLED=true in .env and restart workers.');
        $this->warn('TODO: Implement automated .env write or DB-driven config override (H2553 open question).');

        return 0;
    }
}
