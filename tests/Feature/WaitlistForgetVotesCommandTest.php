<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CourseWaitlistItem;
use App\Models\User;
use App\Models\WaitlistVote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * waitlist:forget-votes (MG 11-10-2026: «удалить все голоса конкретного
 * человека»): снос всех waitlist_votes юзера по всем курсам + сброс кэша
 * публичного фида. Счётчики вычисляемые — votesCount() после удаления
 * падает сам.
 */
class WaitlistForgetVotesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_all_votes_of_the_person_across_items(): void
    {
        $anna = User::factory()->create(['name' => 'Анна']);
        $first = CourseWaitlistItem::create([
            'slug' => 'wl-first', 'course_title' => 'Курс один', 'teacher_name' => 'Учитель',
            'min_payers' => 8, 'kind' => 'grammar',
            'status' => CourseWaitlistItem::STATUS_COLLECTING, 'is_listed' => true,
        ]);
        $second = CourseWaitlistItem::create([
            'slug' => 'wl-second', 'course_title' => 'Курс два', 'teacher_name' => 'Учитель',
            'min_payers' => 8, 'kind' => 'grammar',
            'status' => CourseWaitlistItem::STATUS_COLLECTING, 'is_listed' => true,
        ]);
        $first->castVoteBy($anna, 'evening');
        $second->castVoteBy($anna, null);
        $other = User::factory()->create();
        $first->castVoteBy($other, 'morning');

        $this->artisan('waitlist:forget-votes', ['user' => $anna->email, '--force' => true])
            ->assertExitCode(0);

        $this->assertSame(0, WaitlistVote::query()->where('user_id', $anna->getKey())->count());
        $this->assertSame(1, $first->votesCount());
        $this->assertSame(0, $second->votesCount());
    }

    public function test_unknown_user_fails_with_hint(): void
    {
        $this->artisan('waitlist:forget-votes', ['user' => 'nosuchperson@example.com'])
            ->assertExitCode(1);
    }

    public function test_resolves_user_by_exact_name(): void
    {
        $anna = User::factory()->create(['name' => 'Горбаченко Анастасия Владимировна, Витебск, Беларусь']);
        $item = CourseWaitlistItem::create([
            'slug' => 'wl-name', 'course_title' => 'Курс по имени', 'teacher_name' => 'Учитель',
            'min_payers' => 8, 'kind' => 'grammar',
            'status' => CourseWaitlistItem::STATUS_COLLECTING, 'is_listed' => true,
        ]);
        $item->castVoteBy($anna, null);

        $this->artisan('waitlist:forget-votes', ['user' => 'Горбаченко Анастасия Владимировна, Витебск, Беларусь', '--force' => true])
            ->assertExitCode(0);

        $this->assertSame(0, $item->votesCount());
    }
}
