<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Filament\Pages\Helpdesk;
use App\Models\ChatMessage;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H223 D5 — быстрые ответы в Helpdesk: dropdown шаблонов поддержки (библиотека
 * H221) подставляет текст в поле ответа для правки перед отправкой, за флагом
 * crm_cockpit. Ничего не отправляет.
 */
class CannedReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function studentWithChat(): User
    {
        $student = User::factory()->create(['role' => null, 'name' => 'Мару']);
        ChatMessage::create([
            'user_id' => $student->id,
            'role' => 'user',
            'text' => 'вопрос',
            'is_read' => false,
        ]);

        return $student;
    }

    /** @test */
    public function inserting_canned_reply_fills_message_with_substituted_text(): void
    {
        config(['features.crm_cockpit' => true]);
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));
        $student = $this->studentWithChat();

        $tpl = MessageTemplate::factory()->category(MessageTemplate::CATEGORY_SUPPORT)->create([
            'body' => 'Намасте, {name}! Разбираемся.',
        ]);

        Livewire::test(Helpdesk::class)
            ->set('activeUserId', $student->id)
            ->call('insertCannedReply', $tpl->id)
            ->assertSet('newMessage', 'Намасте, Мару! Разбираемся.');
    }

    /** @test */
    public function support_templates_list_is_empty_when_flag_off(): void
    {
        config(['features.crm_cockpit' => false]);
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));
        MessageTemplate::factory()->category(MessageTemplate::CATEGORY_SUPPORT)->create();

        $page = new Helpdesk;
        $this->assertSame([], $page->getSupportTemplatesProperty());
    }

    /** @test */
    public function support_templates_list_returns_only_active_support_category(): void
    {
        config(['features.crm_cockpit' => true]);
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));

        $support = MessageTemplate::factory()->category(MessageTemplate::CATEGORY_SUPPORT)->create();
        MessageTemplate::factory()->category(MessageTemplate::CATEGORY_SUPPORT)->inactive()->create();
        MessageTemplate::factory()->category(MessageTemplate::CATEGORY_LEAD)->create();

        $list = (new Helpdesk)->getSupportTemplatesProperty();

        $this->assertSame([$support->id => $support->title], $list);
    }

    /** @test */
    public function insert_is_noop_when_flag_off(): void
    {
        config(['features.crm_cockpit' => false]);
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));
        $student = $this->studentWithChat();
        $tpl = MessageTemplate::factory()->category(MessageTemplate::CATEGORY_SUPPORT)->create();

        Livewire::test(Helpdesk::class)
            ->set('activeUserId', $student->id)
            ->call('insertCannedReply', $tpl->id)
            ->assertSet('newMessage', '');
    }
}
