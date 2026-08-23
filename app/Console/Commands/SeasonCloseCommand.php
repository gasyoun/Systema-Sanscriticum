<?php

namespace App\Console\Commands;

use App\Models\Season;
use App\Models\SeasonLeaderboardCache;
use App\Models\SeasonReward;
use App\Models\User;
use App\Services\Prana\PranaService;
use App\Support\Roles;
use Illuminate\Console\Command;

class SeasonCloseCommand extends Command
{
    protected $signature = 'season:close {season_id?}';

    protected $description = 'Закрыть игровой сезон, раздать награды и выключить decay';

    public function __construct(private PranaService $pranaService)
    {
        parent::__construct();
    }

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

        // Финальный пересчёт лидерборда
        $this->info('Computing final leaderboard...');
        $this->call('season:refresh-leaderboard', ['season_id' => $season->id]);

        // Раздать награды по rewards_config
        $this->info('Awarding season rewards...');
        $rewardsConfig = $season->rewards_config ?? [];

        foreach ($rewardsConfig as $rewardSpec) {
            $position = $rewardSpec['position'] ?? null;
            $type = $rewardSpec['type'] ?? null;
            $amount = $rewardSpec['amount'] ?? 0;

            if (! $position || ! $type) {
                continue;
            }

            // Найти пользователя на позиции $position
            $entry = SeasonLeaderboardCache::where('season_id', $season->id)
                ->where('rank_position', $position)
                ->first();

            if (! $entry) {
                continue;
            }

            // Создать запись награды
            SeasonReward::create([
                'season_id' => $season->id,
                'user_id' => $entry->user_id,
                'position' => $position,
                'reward_type' => $type,
                'reward_value' => $amount,
                'awarded_at' => now(),
            ]);

            // Для типа 'prana' — начислить через PranaService::adminAdjust.
            // adminAdjust требует User $admin для аудита; берём super_admin,
            // иначе любого admin-like (роль, а не legacy-флаг is_admin).
            if ($type === 'prana') {
                $user = $entry->user;
                $admin = User::whereIn('role', Roles::adminLike())
                    ->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [Roles::SUPER_ADMIN])
                    ->first();

                if (! $admin) {
                    $this->warn("Награда позиции {$position} не начислена: в системе нет admin-пользователя для аудита adminAdjust.");
                }

                if ($admin && $user) {
                    $this->pranaService->adminAdjust(
                        $user,
                        $amount,
                        $admin,
                        "Season {$season->id} reward (position {$position})"
                    );
                    $this->info("Awarded {$amount} prana to user #{$user->id} (position {$position}).");
                }
            }
        }

        // Закрыть сезон и погасить decay-флаг (H3297 / R4-1: decay не живёт
        // вне сезона — гасим явно, хотя is_active=false уже исключает его из
        // PranaService::isDecayEnabled()).
        $season->update([
            'ended_at' => now(),
            'is_active' => false,
            'decay_enabled' => false,
        ]);

        $this->info("Season #{$season->id} closed. Decay выключен (seasons.decay_enabled=false).");

        return 0;
    }
}
