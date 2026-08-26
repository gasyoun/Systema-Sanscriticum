<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Bot\StudentSelfService;
use App\Services\ClassRoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3576 §2 — открытые эфиры ОРС в боте: расписание по интенту, подписка через
 * группу потока (ростер classes:remind-upcoming подхватывает активных
 * участников группы курса), отписка гасит участие (left_at).
 */
class StreamsSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private Course $streamsCourse;

    private Group $streamsGroup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->streamsCourse = Course::factory()->create([
            'slug' => StudentSelfService::STREAMS_COURSE_SLUG,
        ]);
        $this->streamsGroup = Group::create([
            'name' => 'Открытые эфиры ОРС',
            'slug' => StudentSelfService::STREAMS_GROUP_SLUG,
            'status' => 'forming',
        ]);
        $this->streamsCourse->groups()->attach($this->streamsGroup->id);
    }

    private function streamSchedule(string $start = '+3 days'): Schedule
    {
        return Schedule::create([
            'course_id' => $this->streamsCourse->id,
            'title' => 'Онлайн-курсы по индологии — стрим-анонс',
            'description' => "Бесплатный эфир 19:00 МСК.\nhttps://us02web.zoom.us/j/85418878877?pwd=test",
            'start' => now()->modify($start)->setTime(19, 0),
        ]);
    }

    /** @test */
    public function streams_summary_lists_upcoming_dates_and_subscription_state(): void
    {
        $this->streamSchedule();
        $this->streamSchedule('+2 weeks');

        $summary = app(StudentSelfService::class)->streamsSummary(User::factory()->create());

        $this->assertStringContainsString('Открытые эфиры ОРС', $summary);
        $this->assertSame(2, substr_count($summary, '— стрим-анонс'));
        $this->assertStringContainsString('подписаться на эфиры', $summary);
    }

    /** @test */
    public function subscribe_attaches_to_stream_group_and_roster_picks_user_up(): void
    {
        $service = app(StudentSelfService::class);
        $user = User::factory()->create(['telegram_id' => 777]);
        $schedule = $this->streamSchedule('+90 minutes');

        $this->assertFalse($service->isSubscribedToStreams($user));

        $reply = $service->subscribeToStreams($user);

        $this->assertStringContainsString('вы подписаны', $reply);
        $this->assertTrue($service->isSubscribedToStreams($user));

        // Главный инвариант: подписчик попадает в ростер занятия → получит
        // classes:remind-upcoming (персональное ЛС с трекинг-ссылкой).
        $roster = ClassRoster::query($schedule)?->pluck('id');
        $this->assertNotNull($roster);
        $this->assertContains($user->id, $roster);

        // Повторная подписка идемпотентна — без дублей в пивоте.
        $service->subscribeToStreams($user);
        $this->assertSame(1, $user->groups()->where('groups.slug', StudentSelfService::STREAMS_GROUP_SLUG)->count());
    }

    /** @test */
    public function unsubscribe_removes_user_from_roster_but_keeps_group_row(): void
    {
        $service = app(StudentSelfService::class);
        $user = User::factory()->create();
        $schedule = $this->streamSchedule('+90 minutes');

        $service->subscribeToStreams($user);
        $reply = $service->unsubscribeFromStreams($user);

        $this->assertStringContainsString('напоминания', mb_strtolower($reply));
        $this->assertFalse($service->isSubscribedToStreams($user));
        $this->assertNotContains($user->id, ClassRoster::query($schedule)?->pluck('id') ?? []);

        // Ряд в пивоте сохранён (история), но с left_at.
        $pivot = $user->groups()->where('groups.slug', StudentSelfService::STREAMS_GROUP_SLUG)->first();
        $this->assertNotNull($pivot);
        $this->assertNotNull($pivot->pivot->left_at);

        // Повторная отписка без активности — мягкий текст, без ошибок.
        $again = $service->unsubscribeFromStreams($user);
        $this->assertStringContainsString('Передумаете', $again);
    }

    /** @test */
    public function subscribe_intent_wins_over_generic_streams_intent(): void
    {
        $service = app(StudentSelfService::class);

        $text = 'Хочу подписаться на эфиры';

        $this->assertTrue($service->matchesStreamsSubscribeIntent($text));
        $this->assertTrue($service->matchesStreamsIntent($text));

        $unsub = 'Отписаться от эфиров, пожалуйста';

        $this->assertTrue($service->matchesStreamsUnsubscribeIntent($unsub));
        $this->assertTrue($service->matchesStreamsIntent($unsub));

        // Обычный вопрос о группах не должен задевать эфирные интенты.
        $plain = 'мои группы';

        $this->assertFalse($service->matchesStreamsIntent($plain));
        $this->assertFalse($service->matchesStreamsSubscribeIntent($plain));
    }
}
