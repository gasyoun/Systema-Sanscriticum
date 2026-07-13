<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Models\User;
use App\Services\Bot\UnblockBotCommand;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnblockBotCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_admin_and_admin_are_authorized(): void
    {
        $this->assertTrue(UnblockBotCommand::isAuthorized(
            User::factory()->create(['role' => Roles::SUPER_ADMIN])
        ));
        $this->assertTrue(UnblockBotCommand::isAuthorized(
            User::factory()->create(['role' => Roles::ADMIN])
        ));
        // Менеджер — куратор, но НЕ вправе выдавать ссылки входа.
        $this->assertFalse(UnblockBotCommand::isAuthorized(
            User::factory()->create(['role' => Roles::MANAGER])
        ));
        $this->assertFalse(UnblockBotCommand::isAuthorized(
            User::factory()->create(['role' => null])
        ));
        $this->assertFalse(UnblockBotCommand::isAuthorized(null));
    }

    public function test_command_detection(): void
    {
        $cmd = app(UnblockBotCommand::class);

        $this->assertTrue($cmd->isCommand('/unblock a@b.com'));
        $this->assertTrue($cmd->isCommand('  /unblock  '));
        $this->assertFalse($cmd->isCommand('/unblocked'));
        $this->assertFalse($cmd->isCommand('привет'));
    }

    public function test_reply_returns_login_link_for_a_known_student(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        User::factory()->create(['email' => 'stuck@example.com']);

        $reply = app(UnblockBotCommand::class)->replyForCommand($admin, '/unblock stuck@example.com');

        $this->assertStringContainsString('/login-link/', $reply);
    }

    public function test_reply_reports_unknown_student(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);

        $reply = app(UnblockBotCommand::class)->replyForCommand($admin, '/unblock ghost@example.com');

        $this->assertStringContainsString('не найден', $reply);
    }
}
