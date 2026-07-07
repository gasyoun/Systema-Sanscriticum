<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Guard n8n-синка расписания (ScheduleObserver): URL берётся из
 * services.n8n.schedule_sheet_webhook, а не из захардкоженного
 * /webhook-test/... (тот жил только при нажатом «Execute workflow» в
 * редакторе n8n и в проде на каждый CRUD расписания отвечал 404).
 * Пустой конфиг = интеграция выключена, HTTP-вызовов нет вообще.
 */
class ScheduleWebhookGuardTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(): Schedule
    {
        return Schedule::create([
            'title' => 'Санскрит, занятие 1',
            'start' => now()->addDay(),
            'end' => now()->addDay()->addHour(),
        ]);
    }

    public function test_empty_webhook_config_sends_nothing(): void
    {
        config(['services.n8n.schedule_sheet_webhook' => null]);
        Http::fake();

        $schedule = $this->makeSchedule();
        $schedule->update(['title' => 'Перенос']);
        $schedule->delete();

        Http::assertNothingSent();
    }

    public function test_configured_webhook_receives_crud_events(): void
    {
        config(['services.n8n.schedule_sheet_webhook' => 'https://n8n.example/webhook/schedule']);
        Http::fake(['n8n.example/*' => Http::response(['ok' => true])]);

        $this->makeSchedule();

        Http::assertSent(fn ($request) => $request->url() === 'https://n8n.example/webhook/schedule'
            && $request['action'] === 'create'
            && $request['title'] === 'Санскрит, занятие 1');
    }
}
