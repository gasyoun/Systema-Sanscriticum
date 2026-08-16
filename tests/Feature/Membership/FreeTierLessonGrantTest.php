<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\Payment;
use App\Models\User;
use App\Services\Membership\FreeTierLessonGranter;

/**
 * Бесплатный уровень «Свободный» (H2644): один урок в месяц из ранее
 * оплаченного курса, 30 дней, без накопления.
 */
class FreeTierLessonGrantTest extends MembershipTestCase
{
    private function granter(): FreeTierLessonGranter
    {
        return app(FreeTierLessonGranter::class);
    }

    /**
     * Студент, оплативший ТОЛЬКО первый блок курса из трёх занятий (по одному
     * на блок). Уроки блоков 2 и 3 ему закрыты — они и есть кандидаты.
     *
     * @return array{0: User, 1: Course, 2: Lesson, 3: Lesson}
     */
    private function dormantPayer(): array
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'Поток '.uniqid()]);
        $course->groups()->attach($group->id);
        $user->groups()->syncWithoutDetaching([$group->id]);

        $lessons = [];
        foreach ([1, 2, 3] as $block) {
            $lessons[$block] = Lesson::factory()->create([
                'course_id' => $course->id,
                'block_number' => $block,
                'sort_order' => $block,
                'youtube_url' => 'https://youtu.be/'.uniqid(),
            ]);
        }

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'block_1',
            'status' => 'paid',
        ]);

        return [$user, $course, $lessons[2], $lessons[3]];
    }

    public function test_grants_the_first_locked_lesson_in_course_order_for_thirty_days(): void
    {
        [$user, $course, $block2] = $this->dormantPayer();

        $row = $this->granter()->grantFor($user, true);

        $this->assertSame('granted', $row['status']);
        $this->assertSame($block2->id, $row['lesson_id'], 'выдаём следующий по программе, а не случайный');

        $grant = LessonAccessGrant::where('user_id', $user->id)->firstOrFail();
        $this->assertSame($course->id, (int) $grant->course_id);
        $this->assertSame('free_tier_monthly', $grant->reason);
        $this->assertTrue($grant->isActive());
        $this->assertEqualsWithDelta(30, now()->diffInDays($grant->expires_at), 1);
    }

    public function test_never_grants_a_lesson_the_student_already_paid_for(): void
    {
        [$user, , $block2] = $this->dormantPayer();

        $row = $this->granter()->grantFor($user, true);

        $granted = Lesson::find($row['lesson_id']);
        $this->assertSame(2, $granted->block_number, 'блок 1 уже оплачен — подарок из него пустой');
        $this->assertSame($block2->id, $granted->id);
    }

    public function test_does_not_accrue_while_a_grant_is_still_live(): void
    {
        [$user] = $this->dormantPayer();

        $this->granter()->grantFor($user, true);
        $second = $this->granter()->grantFor($user, true);

        $this->assertSame('has_active_grant', $second['status']);
        $this->assertSame(1, LessonAccessGrant::where('user_id', $user->id)->count(),
            'неиспользованный месяц сгорает — иначе возврат превращается в бесплатный архив');
    }

    public function test_grants_again_once_the_previous_one_expired(): void
    {
        [$user] = $this->dormantPayer();

        $this->granter()->grantFor($user, true);
        LessonAccessGrant::where('user_id', $user->id)->update(['expires_at' => now()->subDay()]);

        $row = $this->granter()->grantFor($user, true);

        $this->assertSame('granted', $row['status']);
        $this->assertSame(2, LessonAccessGrant::where('user_id', $user->id)->count());
    }

    public function test_reports_exhausted_when_every_paid_course_lesson_is_already_open(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        Lesson::factory()->create([
            'course_id' => $course->id,
            'block_number' => 1,
            'youtube_url' => 'https://youtu.be/'.uniqid(),
        ]);
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 12000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $row = $this->granter()->grantFor($user, true);

        $this->assertSame('exhausted', $row['status']);
        $this->assertSame(0, LessonAccessGrant::where('user_id', $user->id)->count(),
            'подставлять курс, за который не платили, нельзя — это раздача чужого товара');
    }

    public function test_skips_lessons_without_a_recording(): void
    {
        [$user, $course, $block2, $block3] = $this->dormantPayer();
        $block2->forceFill(['youtube_url' => null, 'rutube_url' => null, 'video_url' => null])->save();

        $row = $this->granter()->grantFor($user, true);

        $this->assertSame($block3->id, $row['lesson_id'], 'грант на урок без записи — право смотреть пустую страницу');
    }

    public function test_club_member_is_skipped(): void
    {
        [$user] = $this->dormantPayer();
        $this->payClub($user, 1);

        $row = $this->granter()->grantFor($user->fresh(), true);

        $this->assertSame('club_member', $row['status']);
    }

    public function test_never_paid_user_is_skipped(): void
    {
        $user = User::factory()->create();

        $this->assertSame('never_paid', $this->granter()->grantFor($user, true)['status']);
    }

    public function test_dry_run_writes_nothing(): void
    {
        [$user] = $this->dormantPayer();

        $row = $this->granter()->grantFor($user, false);

        $this->assertSame('would_grant', $row['status']);
        $this->assertSame(0, LessonAccessGrant::where('user_id', $user->id)->count());
    }

    public function test_campaign_mode_targets_exactly_the_named_users(): void
    {
        [$targeted] = $this->dormantPayer();
        [$untouched] = $this->dormantPayer();

        $this->artisan('membership:grant-free-lesson --apply --user='.$targeted->id.' --reason=free_tier_h2566')
            ->assertSuccessful();

        $this->assertSame(1, LessonAccessGrant::where('user_id', $targeted->id)->count());
        $this->assertSame('free_tier_h2566',
            LessonAccessGrant::where('user_id', $targeted->id)->first()->reason);
        $this->assertSame(0, LessonAccessGrant::where('user_id', $untouched->id)->count(),
            'режим кампании не должен задевать никого, кроме списка');
    }

    public function test_campaign_mode_accepts_email(): void
    {
        [$targeted] = $this->dormantPayer();

        $this->artisan('membership:grant-free-lesson --apply --user='.$targeted->email)->assertSuccessful();

        $this->assertSame(1, LessonAccessGrant::where('user_id', $targeted->id)->count());
    }

    public function test_campaign_grant_still_blocks_the_daemon_from_double_granting(): void
    {
        [$user] = $this->dormantPayer();

        $this->artisan('membership:grant-free-lesson --apply --user='.$user->id.' --reason=free_tier_h2566')
            ->assertSuccessful();
        $row = $this->granter()->grantFor($user->fresh(), true);

        $this->assertSame('has_active_grant', $row['status'],
            'кампания и демон обязаны видеть гранты друг друга — иначе человек получит два урока');
    }

    public function test_daemon_does_not_auto_grant_by_default_even_with_the_flag_on(): void
    {
        // H2899. Флаг ВКЛЮЧЁН — и демон всё равно ничего не пишет. Это и есть
        // предмет правки: `MEMBERSHIP_FREE_TIER` во всех документах назван
        // кампанией на 350 молчащих >12 мес, а правило демона давности не знает
        // и на проде видит 923 — раздача в 2,5 раза шире написанного, молча.
        config()->set('features.membership_free_tier', true);
        config()->set('membership.free_tier.daemon_apply', false);
        [$user] = $this->dormantPayer();

        $this->artisan('membership:grant-free-lesson --apply')->assertSuccessful();

        $this->assertSame(0, LessonAccessGrant::where('user_id', $user->id)->count(),
            'демон обязан молчать, пока авто-выдача не разрешена явно');
    }

    public function test_daemon_auto_grants_once_the_knob_is_explicitly_on(): void
    {
        // Обратная половина: запрет должен сниматься, иначе это не рубильник,
        // а удаление функции.
        config()->set('features.membership_free_tier', true);
        config()->set('membership.free_tier.daemon_apply', true);
        [$user] = $this->dormantPayer();

        $this->artisan('membership:grant-free-lesson --apply')->assertSuccessful();

        $this->assertSame(1, LessonAccessGrant::where('user_id', $user->id)->count());
    }

    public function test_campaign_still_grants_while_the_daemon_is_muted(): void
    {
        // Кампания — единственный разрешённый путь доставки, и запрет демона
        // не должен её задеть: иначе «сузить до явного списка» превратилось бы
        // в «выключить бесплатный уровень совсем».
        config()->set('features.membership_free_tier', true);
        config()->set('membership.free_tier.daemon_apply', false);
        [$user] = $this->dormantPayer();

        $this->artisan('membership:grant-free-lesson --apply --user='.$user->id.' --reason=free_tier_h2566')
            ->assertSuccessful();

        $this->assertSame(1, LessonAccessGrant::where('user_id', $user->id)->count());
    }

    public function test_daemon_mode_writes_nothing_while_the_flag_is_off(): void
    {
        config()->set('features.membership_free_tier', false);
        [$user] = $this->dormantPayer();

        $this->artisan('membership:grant-free-lesson --apply --user='.$user->id)->assertSuccessful();

        $this->assertSame(0, LessonAccessGrant::where('user_id', $user->id)->count());
    }

    public function test_eligible_ids_exclude_club_members_and_already_granted(): void
    {
        [$plain] = $this->dormantPayer();
        [$clubber] = $this->dormantPayer();
        $this->payClub($clubber, 1);
        [$granted] = $this->dormantPayer();
        $this->granter()->grantFor($granted, true);

        $ids = $this->granter()->eligibleUserIds(50);

        $this->assertContains($plain->id, $ids);
        $this->assertNotContains($clubber->id, $ids);
        $this->assertNotContains($granted->id, $ids);
    }
}
