<?php

declare(strict_types=1);

namespace Tests\Feature\TelegramBusiness;

use App\Models\AnonsLinkClick;
use App\Models\TelegramBusinessStoryPublication;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CollectTelegramBusinessStoryMetricsTest extends TestCase
{
    public function test_final_part_reach_and_campaign_clicks_are_stored_without_turning_missing_into_zero(): void
    {
        Schema::create('telegram_business_story_publications', function (Blueprint $table): void {
            $table->id();
            $table->string('source_chat_id');
            $table->unsignedBigInteger('source_message_id');
            $table->json('story_ids')->nullable();
            $table->string('status');
            $table->string('cta_url')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamp('metrics_collected_at')->nullable();
            $table->timestamps();
        });
        Schema::create('anons_link_clicks', function (Blueprint $table): void {
            $table->id();
            $table->string('link');
            $table->string('publication_key')->nullable();
            $table->json('utm')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamps();
        });
        $row = TelegramBusinessStoryPublication::create([
            'source_chat_id' => '-1001', 'source_message_id' => 620,
            'story_ids' => [701, 702], 'status' => 'published', 'started_at' => now(),
            'cta_url' => 'https://samskrte.ru/ga/m26-rs-st-lesson-20260927-01',
        ]);
        AnonsLinkClick::create(['link' => 'm26-rs-st-lesson-20260927-01', 'clicked_at' => now()]);
        AnonsLinkClick::create(['link' => 'm26-rs-st-lesson-20260927-01', 'clicked_at' => now()]);
        Process::fake([
            '*' => Process::result(output: json_encode(['ok' => true, 'views' => ['701' => 100, '702' => 75]])."\n"),
        ]);

        self::assertSame(0, Artisan::call('telegram-business:story-metrics'));
        self::assertSame(75, $row->fresh()->metrics['final_part_reach']['value']);
        self::assertSame(2, $row->fresh()->metrics['link_clicks']['value']);
        self::assertSame([701, 702], array_column($row->fresh()->metrics['parts'], 'story_id'));
    }
}
