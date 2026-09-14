<?php

namespace App\Console\Commands;

use App\Models\Season;
use App\Models\SeasonLeaderboardCache;
use App\Models\User;
use Illuminate\Console\Command;

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

        // Установить started_at, is_active и включить decay на строке сезона
        // (H3297: DB-driven оверрайд — документированный дефолт @DECIDE §9
        // PLAN_SYSTEMA_SEASON; вариант B вместо правки .env, которой требует
        // deploy-цикл и рестарт воркеров, а cron стреляет без присмотра).
        $season->update([
            'started_at' => $season->started_at ?? now(),
            'is_active' => true,
            'decay_enabled' => true,
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

        $this->info("Season #{$season->id} opened. Leaderboard baseline set for ".$users->count().' users.');

        // Decay включён флагом seasons.decay_enabled=true на строке сезона —
        // PranaService::isDecayEnabled() читает его из БД (H3297). Гасится
        // автоматически season:close (R4-1: decay не живёт вне сезона).
        $this->info('Decay включён DB-флагом seasons.decay_enabled=true (сезон #'.$season->id.').');

        return 0;
    }
}
