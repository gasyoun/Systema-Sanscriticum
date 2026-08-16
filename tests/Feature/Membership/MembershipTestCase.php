<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Общая обвязка тестов членства (H2644): клубный курс с группой, курс полки
 * с платным уроком, обычный курс с собственной группой.
 *
 * Обычный курс здесь не декорация: инвариант «демон не трогает группы курсов»
 * доказуем только тогда, когда такая группа у студента есть.
 */
abstract class MembershipTestCase extends TestCase
{
    use RefreshDatabase;

    protected Course $clubCourse;

    protected Group $clubGroup;

    protected Course $shelfCourse;

    protected Lesson $shelfLesson;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        MarketingSetting::flushCached();

        config()->set('features.club_membership', true);
        config()->set('features.membership_free_tier', true);
        config()->set('features.membership_cancellation', true);
        config()->set('membership.club.course_slug', 'club');
        config()->set('membership.club.grace_days', 3);
        config()->set('membership.free_tier.grant_days', 30);
        // H2915: по умолчанию эти тесты говорят про режим АВТО-ПОДБОРА, значит
        // файла когорты нет. Тесты про файл ставят путь сами — так режим виден
        // в самом тесте, а не наследуется молча из боевого дефолта конфига.
        config()->set('membership.free_tier.cohort_file', '');

        $this->clubCourse = Course::factory()->create(['slug' => 'club', 'title' => 'Клуб']);
        $this->clubGroup = Group::create(['name' => 'Клуб — участники']);
        $this->clubCourse->groups()->attach($this->clubGroup->id);

        $this->shelfCourse = Course::factory()->create(['club_included' => true]);
        $shelfGroup = Group::create(['name' => 'Полка — поток']);
        $this->shelfCourse->groups()->attach($shelfGroup->id);
        $this->shelfLesson = Lesson::factory()->create([
            'course_id' => $this->shelfCourse->id,
            'group_id' => $shelfGroup->id,
            'block_number' => 1,
            'sort_order' => 1,
            'youtube_url' => 'https://youtu.be/'.uniqid(),
        ]);
    }

    /** Клубный тариф на N месяцев — ключ доступа станет `club_{N}m`. */
    protected function clubTariff(int $months): Tariff
    {
        return Tariff::factory()->create([
            'course_id' => $this->clubCourse->id,
            'title' => 'Клуб — '.$months.' мес',
            'price' => $months * 1500,
            'membership_months' => $months,
        ]);
    }

    /** Оплата клубного тарифа по обычному пути (через processSuccessfulPayment). */
    protected function payClub(User $user, int $months = 1): Payment
    {
        $tariff = $this->clubTariff($months);

        return Payment::create([
            'user_id' => $user->id,
            'course_id' => $this->clubCourse->id,
            'amount' => (float) $tariff->price,
            'tariff' => $tariff->accessKey(),
            'status' => 'paid',
        ]);
    }

    /** Обычный купленный курс со своей группой — то, что демон обязан не тронуть. */
    protected function enrollInOrdinaryCourse(User $user): Group
    {
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'Обычный курс — поток '.$course->id]);
        $course->groups()->attach($group->id);
        $user->groups()->syncWithoutDetaching([$group->id]);

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 6000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        return $group;
    }
}
