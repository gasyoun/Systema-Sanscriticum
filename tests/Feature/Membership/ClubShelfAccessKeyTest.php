<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Membership\ClubEntitlement;

/**
 * H2886 — объём клубного доступа на уровне курса (`courses.club_access_key`).
 *
 * Контракт из двух половин, и вторая стоит денег: клуб обязан ОТКРЫТЬ то, что
 * решили положить в полку, и обязан НЕ ОТКРЫТЬ остальное. Тест, проверяющий
 * только первую половину, зелен и на регрессе «ключ проигнорирован, выдан
 * full» — поэтому здесь каждая проверка объёма идёт парой.
 */
final class ClubShelfAccessKeyTest extends MembershipTestCase
{
    private function lessonInBlock(int $block, int $sort = 1): Lesson
    {
        return Lesson::factory()->create([
            'course_id' => $this->shelfCourse->id,
            'group_id' => $this->shelfLesson->group_id,
            'block_number' => $block,
            'sort_order' => $sort,
            'youtube_url' => 'https://youtu.be/'.uniqid(),
        ]);
    }

    public function test_absent_key_keeps_the_pre_h2886_whole_course_behaviour(): void
    {
        $user = User::factory()->create();
        $this->payClub($user);
        $second = $this->lessonInBlock(2);

        $club = app(ClubEntitlement::class);
        $keys = $club->extraTariffKeys($user->fresh(), $this->shelfCourse);

        $this->assertSame(['full'], $keys);
        $this->assertTrue($this->shelfLesson->isUnlockedBy($keys));
        $this->assertTrue($second->isUnlockedBy($keys), 'NULL в колонке обязан остаться «курс целиком»');
        $this->assertNull($club->scopeLabelFor($this->shelfCourse));
    }

    public function test_block_key_opens_that_block_and_leaves_the_rest_locked(): void
    {
        $user = User::factory()->create();
        $this->payClub($user);
        $second = $this->lessonInBlock(2);
        $third = $this->lessonInBlock(3);

        $this->shelfCourse->forceFill(['club_access_key' => 'block_1'])->save();

        $club = app(ClubEntitlement::class);
        $keys = $club->extraTariffKeys($user->fresh(), $this->shelfCourse->fresh());

        $this->assertSame(['block_1'], $keys);
        $this->assertTrue($this->shelfLesson->isUnlockedBy($keys), 'блок 1 обязан открыться');
        $this->assertFalse($second->isUnlockedBy($keys), 'блок 2 обязан остаться закрытым');
        $this->assertFalse($third->isUnlockedBy($keys), 'блок 3 обязан остаться закрытым');
    }

    public function test_partial_scope_still_lets_the_member_see_the_course(): void
    {
        // Разделение ролей: coversCourse() — гейт ВИДИМОСТИ, ключ — гейт
        // ОТКРЫТИЯ. Иначе частичный объём выкинул бы члена на 404 и он не увидел
        // бы даже того блока, за который заплатил.
        $user = User::factory()->create();
        $this->payClub($user);
        $this->shelfCourse->forceFill(['club_access_key' => 'block_1'])->save();

        $club = app(ClubEntitlement::class);

        $this->assertTrue($club->coversCourse($user->fresh(), $this->shelfCourse->fresh()));
        $this->assertTrue($club->shelfFor($user->fresh())->contains('id', $this->shelfCourse->id));
    }

    public function test_scope_label_names_the_block_for_the_shelf_card(): void
    {
        $club = app(ClubEntitlement::class);

        $this->shelfCourse->forceFill(['club_access_key' => 'block_1'])->save();
        $this->assertSame('блок 1', $club->scopeLabelFor($this->shelfCourse->fresh()));

        $this->shelfCourse->forceFill(['club_access_key' => 'block_2_h1'])->save();
        $this->assertSame('блок 2, часть 1', $club->scopeLabelFor($this->shelfCourse->fresh()));

        $this->shelfCourse->forceFill(['club_access_key' => 'full'])->save();
        $this->assertNull($club->scopeLabelFor($this->shelfCourse->fresh()));
    }

    public function test_non_member_gets_nothing_whatever_the_key_says(): void
    {
        $user = User::factory()->create();
        $this->shelfCourse->forceFill(['club_access_key' => 'block_1'])->save();

        $keys = app(ClubEntitlement::class)->extraTariffKeys($user, $this->shelfCourse->fresh());

        $this->assertSame([], $keys);
        $this->assertFalse($this->shelfLesson->isUnlockedBy($keys));
    }

    public function test_command_sets_the_key_on_an_explicit_course(): void
    {
        $this->shelfCourse->forceFill(['club_included' => false, 'club_access_key' => null])->save();

        $this->artisan('membership:club-catalogue', [
            '--course' => [(string) $this->shelfCourse->id],
            '--key' => 'block_1',
            '--apply' => true,
        ])->assertSuccessful();

        $fresh = $this->shelfCourse->fresh();
        $this->assertTrue((bool) $fresh->club_included);
        $this->assertSame('block_1', $fresh->club_access_key);
    }

    public function test_command_treats_a_key_change_on_a_shelved_course_as_a_change(): void
    {
        // Курс УЖЕ в полке — прежняя проверка «club_included != target» считала
        // бы, что менять нечего, и молча оставила бы старый объём.
        $this->shelfCourse->forceFill(['club_included' => true, 'club_access_key' => 'block_1'])->save();

        $this->artisan('membership:club-catalogue', [
            '--course' => [(string) $this->shelfCourse->id],
            '--key' => 'block_2',
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame('block_2', $this->shelfCourse->fresh()->club_access_key);
    }

    public function test_command_rejects_a_malformed_key(): void
    {
        // «block1» не совпадает ни с одним Lesson::unlockingKeys, то есть дал бы
        // курс в полке и ноль открытых уроков — молчаливый провал.
        $this->artisan('membership:club-catalogue', [
            '--course' => [(string) $this->shelfCourse->id],
            '--key' => 'block1',
            '--apply' => true,
        ])->assertFailed();

        $this->assertNotSame('block1', $this->shelfCourse->fresh()->club_access_key);
    }

    public function test_command_refuses_a_key_without_an_explicit_course(): void
    {
        $this->artisan('membership:club-catalogue', ['--key' => 'block_1', '--apply' => true])
            ->assertFailed();
    }

    public function test_removing_from_the_shelf_clears_the_scope(): void
    {
        // Иначе оставшийся block_N тихо воскреснет, когда курс вернут в полку
        // без --key, и объём окажется не тем, который решали.
        $this->shelfCourse->forceFill(['club_included' => true, 'club_access_key' => 'block_1'])->save();

        $this->artisan('membership:club-catalogue', [
            '--course' => [(string) $this->shelfCourse->id],
            '--remove' => true,
            '--apply' => true,
        ])->assertSuccessful();

        $fresh = $this->shelfCourse->fresh();
        $this->assertFalse((bool) $fresh->club_included);
        $this->assertNull($fresh->club_access_key);
    }

    public function test_empty_auto_selection_fails_instead_of_reading_as_done(): void
    {
        // FINDINGS §418: «менять нечего» и «нечего выбрать» — разные состояния,
        // и раньше оба возвращали SUCCESS. На проде это стоило пустой полки,
        // прочитанной как выполненный шаг чек-листа.
        Course::query()->update(['club_included' => false]);
        config()->set('features.course_recordings_sales', false);

        $this->artisan('membership:club-catalogue')->assertFailed();
    }
}
