<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class VerifyServerGuardsCommandTest extends TestCase
{
    public function test_it_stays_silent_where_the_check_is_meaningless(): void
    {
        // Ни dev-бокс, ни CI не имеют systemd и crontab www-data: «пропажа» там
        // ничего не означает, и падать деплой-гейт на этом не должен.
        config(['server_guards.verify_enabled' => false]);

        $this->artisan('guards:verify')
            ->expectsOutputToContain('выключен для этого окружения')
            ->assertExitCode(0);
    }

    public function test_an_unreadable_spec_fails_loudly_instead_of_passing(): void
    {
        // Проверять стало нечем — это тоже пропажа предохранителя, а не «ok».
        config([
            'server_guards.verify_enabled' => true,
            'server_guards.spec_path' => base_path('scripts/no-such-server_guards.conf'),
        ]);

        $this->artisan('guards:verify')
            ->expectsOutputToContain('не смог проверить')
            ->assertExitCode(1);
    }
}
