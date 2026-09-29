<?php

declare(strict_types=1);

namespace Tests\Feature\Sandbox;

use App\Models\Group;
use App\Models\Schedule;
use App\Models\ScheduleJoinClick;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H5081 regression · class-join-signed-url-no-expiry-skips-access-recheck.
 *
 * Same setup as the NV-07 proof, assertions flipped to the fixed invariant:
 * the issued join URL carries an expiry, an entitled member still gets the
 * 302 to the class URL, and after revocation (group detach) the STORED link
 * is refused — no redirect, no click row. An expired link no longer
 * authorizes anything.
 */
class H5081JoinLinkExpiryAndAccessRecheckTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function issued_join_link_carries_expiry_and_dies_with_revoked_membership(): void
    {
        config(['app.url' => 'http://localhost']);

        $group = Group::create(['name' => 'H5081 группа']);
        $student = User::factory()->create();
        $student->groups()->attach($group->id);

        $schedule = Schedule::create([
            'title' => 'Занятие H5081',
            'start' => now()->addDay(),
            'group_id' => $group->id,
            'link' => 'https://zoom.example/j/1234567890?pwd=xyz',
        ]);

        $url = $schedule->trackedJoinUrlFor($student, 'reminder');

        // 1) Expiry is now part of the issued link (was: permanent signature).
        $this->assertStringContainsString('expires=', $url, 'signed join URL must carry an expiration');

        // 2) Control: while entitled, the logged-out reminder link still works.
        $this->get($url)->assertRedirect('https://zoom.example/j/1234567890?pwd=xyz');
        $this->assertSame(
            1,
            ScheduleJoinClick::where('schedule_id', $schedule->id)->where('user_id', $student->id)->count(),
            'click recorded for the entitled member'
        );

        // 3) Revoke the entitlement (leave the group) and replay the stored link.
        $student->groups()->detach($group->id);
        $this->assertFalse($student->fresh()->groups->contains($group->id), 'membership revoked');

        $this->get($url)->assertForbidden();

        // No NEW click row was written for the revoked member.
        $this->assertSame(
            1,
            ScheduleJoinClick::where('schedule_id', $schedule->id)->where('user_id', $student->id)->count(),
            'revoked member replay must not record a click'
        );
    }

    /** @test */
    public function expired_join_link_no_longer_redirects(): void
    {
        Carbon::setTestNow('2026-09-17 12:00:00');
        config(['app.url' => 'http://localhost']);

        try {
            $group = Group::create(['name' => 'H5081 истёкшая']);
            $student = User::factory()->create();
            $student->groups()->attach($group->id);

            $schedule = Schedule::create([
                'title' => 'Занятие истёкшее',
                'start' => now()->addHour(),
                'group_id' => $group->id,
                'link' => 'https://zoom.example/j/555?pwd=exp',
            ]);

            $url = $schedule->trackedJoinUrlFor($student, 'reminder');
            $this->assertStringContainsString('expires=', $url);

            // Jump past the expiry (class window + grace): the signature dies
            // with the class window even though the member is still entitled.
            Carbon::setTestNow('2026-09-17 18:00:00');

            $this->get($url)->assertRedirect(route('login'));

            $this->assertSame(
                0,
                ScheduleJoinClick::where('schedule_id', $schedule->id)->count(),
                'expired link must not record clicks'
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    /** @test */
    public function control_unsigned_anonymous_hit_is_sent_to_login(): void
    {
        $group = Group::create(['name' => 'H5081 контроль']);
        $schedule = Schedule::create([
            'title' => 'Занятие контроль',
            'start' => now()->addDay(),
            'group_id' => $group->id,
            'link' => 'https://zoom.example/j/999',
        ]);

        $this->get('/class/'.$schedule->id.'/join')->assertRedirect(route('login'));
    }
}
