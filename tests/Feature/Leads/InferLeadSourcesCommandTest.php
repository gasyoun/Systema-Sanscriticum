<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\User;
use App\Support\LeadSourceInference;
use App\Support\Roles;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H5021 — leads:infer-source: правила вывода, dry-run не пишет, ручной source
 * не перетирается, повторный прогон идемпотентен, report:channel-roi видит
 * выведенный источник и шлёт понедельничный дайджест, обе задачи в расписании.
 */
class InferLeadSourcesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function lead(array $attrs): Lead
    {
        return Lead::factory()->create(array_merge(['landing_page_id' => null], $attrs));
    }

    public function test_rules_in_priority_order(): void
    {
        $cases = [
            [['utm_source' => 'VK.com', 'referrer' => 'https://google.com/'], 'vk', LeadSourceInference::RULE_UTM_SOURCE],
            [['utm_source' => 'My News Letter'], 'my_news_letter', LeadSourceInference::RULE_UTM_SOURCE],
            [['source_article_slug' => 'sandhi-intro', 'referrer' => 'https://vk.com/x'], 'article', LeadSourceInference::RULE_SOURCE_ARTICLE],
            [['referrer' => 'https://away.vk.com/away.php?to=x'], 'vk', LeadSourceInference::RULE_REFERRER_HOST],
            [['referrer' => 'https://www.samskrtam.ru/blog/x'], 'site', LeadSourceInference::RULE_REFERRER_HOST],
            [['referrer' => 'https://t.me/samskrte'], 'telegram', LeadSourceInference::RULE_REFERRER_HOST],
            [['referrer' => 'https://dzen.ru/a/abc'], 'dzen', LeadSourceInference::RULE_REFERRER_HOST],
            [['referrer' => 'https://some-blog.example.org/post'], 'some-blog.example.org', LeadSourceInference::RULE_REFERRER_HOST],
            [['magnet_channel' => 'telegram'], 'magnet_telegram', LeadSourceInference::RULE_MAGNET_CHANNEL],
        ];

        foreach ($cases as [$attrs, $source, $rule]) {
            $hit = LeadSourceInference::infer($this->lead($attrs));
            $this->assertNotNull($hit, json_encode($attrs));
            $this->assertSame($source, $hit['source'], json_encode($attrs));
            $this->assertSame($rule, $hit['rule'], json_encode($attrs));
        }

        $this->assertNull(LeadSourceInference::infer($this->lead([])));
    }

    public function test_normalize_collapses_aliases_and_rejects_non_latin(): void
    {
        $this->assertNull(LeadSourceInference::normalize('Яндекс Директ'));
        $this->assertNull(LeadSourceInference::normalize('   '));
        $this->assertSame('yandex', LeadSourceInference::normalize('YANDEX'));
        $this->assertSame('telegram', LeadSourceInference::normalize('https://t.me'));
        $this->assertSame('my_news_letter', LeadSourceInference::normalize(' My News Letter '));
    }

    public function test_logged_in_user_yields_cabinet(): void
    {
        $user = User::factory()->create();
        $hit = LeadSourceInference::infer($this->lead(['user_id' => $user->id]));
        $this->assertSame(['source' => 'cabinet', 'rule' => LeadSourceInference::RULE_LOGGED_IN_USER], $hit);
    }

    public function test_dry_run_reports_but_writes_nothing(): void
    {
        $this->lead(['utm_source' => 'vk']);
        $this->lead(['referrer' => 'https://t.me/x']);
        $this->lead([]);

        $this->artisan('leads:infer-source', ['--dry-run' => true])
            ->expectsOutputToContain('DRY-RUN')
            ->expectsOutputToContain('до 0,0% (0/3) → после 66,7% (2/3); не выведено: 1')
            ->assertSuccessful();

        $this->assertSame(0, Lead::query()->whereNotNull('inferred_source')->count());
    }

    public function test_apply_writes_and_never_touches_human_source(): void
    {
        $human = $this->lead(['source' => 'сарафан', 'utm_source' => 'vk']);
        $auto = $this->lead(['utm_source' => 'vk']);
        $blank = $this->lead([]);
        $touched = $auto->updated_at;
        $auditsBefore = $auto->audits()->count(); // «created» от LeadAuditObserver

        $this->travel(1)->hours();
        $this->artisan('leads:infer-source')
            ->expectsOutputToContain('1 строк записано')
            ->assertSuccessful();

        $human->refresh();
        $this->assertSame('сарафан', $human->source);
        $this->assertNull($human->inferred_source, 'ручной source — машина не заполняет даже inferred_source');

        $auto->refresh();
        $this->assertSame('vk', $auto->inferred_source);
        $this->assertSame(LeadSourceInference::RULE_UTM_SOURCE, $auto->inference_rule);
        $this->assertNotNull($auto->source_inferred_at);
        $this->assertTrue($touched->equalTo($auto->updated_at), 'машинная разметка не бампает updated_at');
        $this->assertSame($auditsBefore, $auto->audits()->count(), 'без новых строк в lead_audits');

        $this->assertNull($blank->refresh()->inferred_source);
        $this->assertSame('сарафан', $human->effectiveSource());
        $this->assertSame('vk', $auto->effectiveSource());
    }

    public function test_second_run_is_idempotent_unless_recompute(): void
    {
        $lead = $this->lead(['utm_source' => 'vk']);
        $this->artisan('leads:infer-source')->assertSuccessful();
        $first = $lead->refresh()->source_inferred_at;

        $this->travel(1)->days();
        $this->artisan('leads:infer-source')->expectsOutputToContain('0 строк записано')->assertSuccessful();
        $this->assertTrue($first->equalTo($lead->refresh()->source_inferred_at));

        $this->artisan('leads:infer-source', ['--recompute' => true])->expectsOutputToContain('1 строк записано')->assertSuccessful();
        $this->assertTrue($first->lt($lead->refresh()->source_inferred_at));
    }

    public function test_channel_roi_sees_inferred_source_and_sends_digest(): void
    {
        $admin = User::factory()->create(['role' => Roles::SUPER_ADMIN]);
        $this->lead(['referrer' => 'https://vk.com/x']);
        $this->lead(['referrer' => 'https://vk.com/y']);
        $this->lead(['utm_source' => 'telegram', 'utm_campaign' => 'sept']);

        $this->artisan('leads:infer-source')->assertSuccessful();

        $this->artisan('report:channel-roi', ['--by-source' => true, '--digest' => true, '--days' => 90])
            ->expectsOutputToContain('Дайджест каналов отправлен получателям: 1')
            ->assertSuccessful();

        $notification = $admin->notifications()->first();
        $this->assertNotNull($notification);
        $data = (string) json_encode($notification->data, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('vk: лидов 2', $data);
        $this->assertStringContainsString('telegram: лидов 1', $data);
    }

    public function test_nightly_and_weekly_jobs_are_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events());
        $names = $events->map(fn ($e) => $e->description)->all();
        $this->assertContains('leads-infer-source', $names);
        $this->assertContains('report-channel-roi-digest', $names);

        $weekly = $events->first(fn ($e) => $e->description === 'report-channel-roi-digest');
        $this->assertSame('20 9 * * 1', $weekly->expression);
        $this->assertStringContainsString('--days=90 --by-source --digest', $weekly->command);
    }
}
