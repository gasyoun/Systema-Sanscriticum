<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Group;
use App\Models\VacationQuorumPoll;
use App\Services\VacationQuorumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Каникульный кворум шлёт админам алерт через TelegramAdminNotifier — тот же
 * класс, который закрыл PR #2860 (прод 25-09-2026). Этот набор фиксирует, что
 * путь действительно безопасен и не сломается при следующем рефакторинге:
 *
 *  - scheduled-прогон resolveDue() не обрывается на середине чанка, когда
 *    Telegram недоступен: иначе просроченные опросы просто не обрабатываются;
 *  - approveDissolution() доводит мутацию до конца (группа в архив, будущие
 *    занятия сняты) — 500 после изменения состояния недопустим.
 *
 * RED-доказательства у этого файла нет и быть не может: на базе (после #2860)
 * нотификатор уже не бросает. Тест — регрессионный замок.
 */
class VacationQuorumTelegramOutageTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456:SECRET';

    protected function setUp(): void
    {
        parent::setUp();
        // Окно опроса — 25–31 августа (VacationQuorumService::ASK_WINDOW_*):
        // абсолютные фикстуры против now() дают бомбу замедленного действия.
        Carbon::setTestNow(Carbon::parse('2026-08-27 12:00:00'));

        config()->set('services.telegram.bot_token', self::TOKEN);
        config()->set('services.telegram.admin_id', '555,777');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function telegramDown(): void
    {
        Http::fake([
            'api.telegram.org/*' => fn () => throw new ConnectionException(
                'cURL error 28: Operation timed out after 10000 milliseconds for '
                .'https://api.telegram.org/bot'.self::TOKEN.'/sendMessage'
            ),
            '*' => Http::response(['ok' => true], 200),
        ]);
    }

    /** @return array{0: Group, 1: VacationQuorumPoll} */
    private function duePoll(string $name, string $chatId = '-100123'): array
    {
        $group = Group::factory()->create([
            'name' => $name,
            'status' => 'active',
            'is_on_vacation' => true,
            'vacation_resume_date' => null,
            'telegram_chat_id' => $chatId,
            'min_size' => 4,
        ]);

        $poll = app(VacationQuorumService::class)->ask($group);
        $poll->update([
            'deadline_at' => now()->subHour(),
            'outcome' => VacationQuorumPoll::OUTCOME_PENDING,
            'paid_voters' => ['111', '222'],
        ]);

        return [$group, $poll];
    }

    public function test_resolve_due_processes_every_poll_when_telegram_is_down(): void
    {
        $this->telegramDown();

        [, $first] = $this->duePoll('Первая группа', '-100123');
        [, $second] = $this->duePoll('Вторая группа', '-100456');

        app(VacationQuorumService::class)->resolveDue();

        $this->assertEquals(
            VacationQuorumPoll::OUTCOME_DISSOLVE_PENDING,
            $first->fresh()->outcome,
            'первый просроченный опрос обработан'
        );
        $this->assertEquals(
            VacationQuorumPoll::OUTCOME_DISSOLVE_PENDING,
            $second->fresh()->outcome,
            'и второй тоже: падение на алерте не имеет права рвать chunkById'
        );
    }

    public function test_approve_dissolution_completes_mutation_when_telegram_is_down(): void
    {
        $this->telegramDown();

        [$group, $poll] = $this->duePoll('Распускаемая группа');
        $poll->update(['outcome' => VacationQuorumPoll::OUTCOME_DISSOLVE_PENDING]);

        app(VacationQuorumService::class)->approveDissolution($poll);

        $this->assertEquals(VacationQuorumPoll::OUTCOME_DISSOLVED, $poll->fresh()->outcome);
        $this->assertEquals('archived', $group->fresh()->status);
        $this->assertEquals(0, $group->schedules()->where('start', '>', now())->count());
    }
}
