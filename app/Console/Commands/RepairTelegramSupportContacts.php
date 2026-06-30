<?php

namespace App\Console\Commands;

use App\Models\TelegramSupportContact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairTelegramSupportContacts extends Command
{
    protected $signature = 'telegram-support:repair-contacts
        {--apply : Реально исправить пустые contacts (без флага — сухой прогон)}';

    protected $description = 'Repair Telegram support contacts created without telegram_user_id from private incoming dialogs';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $candidates = TelegramSupportContact::query()
            ->whereNull('telegram_user_id')
            ->whereNull('name')
            ->whereNull('username')
            ->whereHas('chat', fn ($query) => $query->where('type', 'private')->whereNotNull('telegram_chat_id'))
            ->with('chat')
            ->orderBy('id')
            ->get();

        $repaired = 0;
        $merged = 0;

        foreach ($candidates as $contact) {
            $telegramUserId = (int) $contact->chat->telegram_chat_id;
            $existing = TelegramSupportContact::where('telegram_user_id', $telegramUserId)
                ->whereKeyNot($contact->id)
                ->first();

            $this->line("  contact #{$contact->id} -> telegram_user_id={$telegramUserId}");

            if (! $apply) {
                $repaired++;

                continue;
            }

            if ($existing) {
                DB::table('telegram_support_messages')
                    ->where('telegram_support_contact_id', $contact->id)
                    ->update(['telegram_support_contact_id' => $existing->id]);
                $contact->delete();
                $merged++;

                continue;
            }

            $contact->forceFill(['telegram_user_id' => $telegramUserId])->save();
            $repaired++;
        }

        $mode = $apply ? 'Repaired' : 'Would repair';
        $this->info("{$mode}: {$repaired}. Merged: {$merged}.");

        if (! $apply) {
            $this->comment('Dry run. Add --apply to write changes.');
        }

        return self::SUCCESS;
    }
}
