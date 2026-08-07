<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\SupportConversation;
use App\Models\SupportConversationTopic;
use App\Models\SupportTopicRule;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Conversation-level topic assign + history for required close (H2381).
 * Taxonomy = SupportTopicRule.category plus explicit other/uncategorized.
 */
class SupportConversationTopicService
{
    public const CATEGORY_OTHER = SupportConversationTopic::CATEGORY_OTHER;

    public const CATEGORY_UNCATEGORIZED = SupportConversationTopic::CATEGORY_UNCATEGORIZED;

    /**
     * Select options for Helpdesk close form: rule categories + other + uncategorized.
     *
     * @return array<string, string> category => label
     */
    public function categoryOptions(): array
    {
        $fromRules = SupportTopicRule::query()
            ->where('is_enabled', true)
            ->orderBy('priority')
            ->orderBy('category')
            ->pluck('category')
            ->filter()
            ->unique()
            ->mapWithKeys(fn (string $c) => [$c => $c]);

        return $fromRules
            ->put(self::CATEGORY_OTHER, 'Другое')
            ->put(self::CATEGORY_UNCATEGORIZED, 'Без категории')
            ->all();
    }

    public function currentTopic(SupportConversation $thread): ?SupportConversationTopic
    {
        return SupportConversationTopic::query()
            ->where('support_conversation_id', $thread->id)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return Collection<int, SupportConversationTopic>
     */
    public function history(SupportConversation $thread): Collection
    {
        return SupportConversationTopic::query()
            ->where('support_conversation_id', $thread->id)
            ->orderBy('id')
            ->get();
    }

    public function assign(
        SupportConversation $thread,
        string $category,
        ?User $by = null,
        string $source = SupportConversationTopic::SOURCE_MANUAL,
        ?string $reason = null,
    ): SupportConversationTopic {
        $category = trim($category);
        if ($category === '') {
            throw new InvalidArgumentException('Topic category is required.');
        }

        $allowed = array_keys($this->categoryOptions());
        if (! in_array($category, $allowed, true)
            && $category !== self::CATEGORY_OTHER
            && $category !== self::CATEGORY_UNCATEGORIZED) {
            // Still accept free-form if a rule was disabled after assign UI load —
            // operators must not be blocked by a concurrent rule toggle.
            // Only empty is forbidden.
        }

        return SupportConversationTopic::create([
            'support_conversation_id' => $thread->id,
            'category' => $category,
            'source' => $source,
            'assigned_by' => $by?->id,
            'reason' => $reason,
        ]);
    }

    public function hasTopic(SupportConversation $thread): bool
    {
        return $this->currentTopic($thread) !== null;
    }
}
