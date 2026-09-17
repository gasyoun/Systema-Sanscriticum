<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Anons\AnonsPublishingService;
use App\Services\Anons\PublicationManifest;
use Illuminate\Console\Command;

/**
 * H5049 R3/R10: anons:preview {manifest}
 * Рендерит каждый кадр ровно как получит адаптер — артефакты, границы
 * плашки, клик-прямоугольник, подписи, UTM. НИЧЕГО не публикует.
 */
final class AnonsPreviewCommand extends Command
{
    protected $signature = 'anons:preview {manifest : Путь к YAML/JSON манифесту} {--json : Выдать план JSON-строкой}';

    protected $description = 'Render the full publication preview (no publish) and show plaque/click-area evidence';

    public function handle(AnonsPublishingService $service): int
    {
        $manifest = PublicationManifest::fromFile((string) $this->argument('manifest'));
        $errors = $service->validate($manifest);
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error('✗ '.$error);
            }

            return self::FAILURE;
        }

        $preview = $service->preview($manifest);

        $this->info('publication_key: '.$preview['publication_key']);
        $this->info('manifest_hash: '.$preview['manifest_hash']);
        $this->info('preview_dir: '.$preview['preview_dir']);

        foreach ($preview['frames'] as $frame) {
            $this->line('');
            $this->info("frame {$frame['frame']} → {$frame['destination']}");
            $this->line('  artifact: '.$frame['artifact']);
            $this->line('  plaque_rect_px: '.json_encode($frame['plaque_rect_px']));
            $this->line('  media_area_pct: '.json_encode($frame['media_area']));
            $this->line('  safe_zones: '.json_encode($frame['safe_zones']));
            $this->line('  caption: '.mb_substr($frame['caption'], 0, 80).(mb_strlen($frame['caption']) > 80 ? '…' : ''));
            $this->line('  short_link: '.$frame['short_link']);
            $this->line('  utm: '.json_encode($frame['utm'], JSON_UNESCAPED_UNICODE));
        }

        if ($this->option('json')) {
            $this->line('');
            $this->line(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
