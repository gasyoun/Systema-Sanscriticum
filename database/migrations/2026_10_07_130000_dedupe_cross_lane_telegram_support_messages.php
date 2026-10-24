<?php

use App\Models\SupportAiReplyEvent;
use App\Models\SupportConversation;
use App\Models\SupportQuestionClassification;
use App\Models\TelegramSupportMessage;
use App\Services\TelegramSupport\SupportDailyRollupAggregator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

return new class extends Migration
{
    /**
     * Клиннинг кросс-канальных дублей поддержки.
     *
     * С 27-09-2026 (подключение @rusamskrtam к ORS через Telegram Business,
     * H5065) одно и то же сообщение в личных чатах приходит ДВУМЯ полосами:
     * business-вебхук пишет строку под аккаунтом telegram-business сразу, а
     * минутный Madeline-синк — вторую под аккаунтом support. Дедуп ключёвался
     * по (аккаунт, чат, message_id) и кросс-полосных дублей не видел: в ленте
     * хелпдеска каждое входящее рисовалось дважды, а исходящие ответы
     * куратора (conv=NULL у полосы business) не рисовались вовсе.
     *
     * Живой дедуп теперь глобальный (по чату+message_id); эта миграция чинит
     * накопленное: сливает дубли-строки, до-привязывает осиротевшие исходящие
     * к тредам и пересчитывает дневные роллапы задвоенных дат.
     */
    public function up(): void
    {
        $affectedDates = collect();

        $this->mergeDuplicateRows($affectedDates);
        $this->attachOrphanOutgoing($affectedDates);

        $aggregator = app(SupportDailyRollupAggregator::class);
        $affectedDates
            ->unique()
            ->sort()
            ->each(fn (string $date) => $aggregator->aggregateDate($date));
    }

    /**
     * Дубли одного сообщения Telegram (одинаковые chat+message_id, настоящий
     * положительный id): выживит старшая строка, младшие сливаем в неё и
     * удаляем. Книжение доставки (delivered_at/pending_delivery) переносится
     * в выжившую, если у той его нет — placeholder-строки (отрицательные id)
     * не трогаем: это разные ответы, а не дубли.
     *
     * @param  Collection<int, string>  $affectedDates
     */
    private function mergeDuplicateRows(Collection $affectedDates): void
    {
        $groups = TelegramSupportMessage::query()
            ->select('telegram_chat_id', 'telegram_message_id')
            ->where('telegram_message_id', '>', 0)
            ->groupBy('telegram_chat_id', 'telegram_message_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $rows = TelegramSupportMessage::query()
                ->where('telegram_chat_id', (int) $group->telegram_chat_id)
                ->where('telegram_message_id', (int) $group->telegram_message_id)
                ->orderBy('id')
                ->get();

            $survivor = $rows->shift();

            foreach ($rows as $duplicate) {
                $this->mergeDeliveryBookkeeping($survivor, $duplicate);
                $this->fillAttributionGaps($survivor, $duplicate);

                $this->repointAiReplyEvents($survivor, $duplicate);
                $this->repointQuestionClassifications($survivor, $duplicate);

                $affectedDates->push($this->dateOf($duplicate));
                $duplicate->delete();
            }

            $affectedDates->push($this->dateOf($survivor));
            $survivor->save();
        }
    }

    /**
     * Исходящие без треда в личных чатах (ответы куратора из Telegram до
     * фикса привязки): цепляем к последнему треду этого чата. Треды создаёт
     * входящее — у исходящего их не было по определению, посему чат без треда
     * молча пропускаем.
     *
     * @param  Collection<int, string>  $affectedDates
     */
    private function attachOrphanOutgoing(Collection $affectedDates): void
    {
        TelegramSupportMessage::query()
            ->whereNull('support_conversation_id')
            ->where('direction', 'outgoing')
            ->where('telegram_message_id', '>', 0)
            ->whereHas('chat', fn ($query) => $query->where('type', 'private'))
            ->orderBy('id')
            ->chunkById(500, function ($messages) use ($affectedDates): void {
                foreach ($messages as $message) {
                    $thread = SupportConversation::query()
                        ->where('source_telegram_chat_id', $message->telegram_chat_id)
                        ->orderByDesc('id')
                        ->first();

                    if (! $thread) {
                        continue;
                    }

                    $message->forceFill(['support_conversation_id' => $thread->id])->save();
                    $affectedDates->push($this->dateOf($message));
                }
            });
    }

    /**
     * Книжение доставки ценнее полноты payload: если выжившая строка не знает
     * о доставке, а дубль знает — переносим факт доставки (и только его).
     */
    private function mergeDeliveryBookkeeping(TelegramSupportMessage $survivor, TelegramSupportMessage $duplicate): void
    {
        $survivorPayload = is_array($survivor->raw_payload) ? $survivor->raw_payload : [];
        $duplicatePayload = is_array($duplicate->raw_payload) ? $duplicate->raw_payload : [];

        if (! isset($duplicatePayload['delivered_at']) || isset($survivorPayload['delivered_at'])) {
            return;
        }

        foreach (['delivered_at', 'pending_delivery'] as $key) {
            if (array_key_exists($key, $duplicatePayload)) {
                $survivorPayload[$key] = $duplicatePayload[$key];
            }
        }

        $survivor->raw_payload = $survivorPayload;
    }

    /** Выжившая строка не должна потерять атрибуцию, известную дублю. */
    private function fillAttributionGaps(TelegramSupportMessage $survivor, TelegramSupportMessage $duplicate): void
    {
        $survivor->support_conversation_id ??= $duplicate->support_conversation_id;
        $survivor->role = $survivor->role === 'unknown' ? $duplicate->role : $survivor->role;
        $survivor->responder_type ??= $duplicate->responder_type;
        $survivor->responder_user_id ??= $duplicate->responder_user_id;
        $survivor->responder_marker ??= $duplicate->responder_marker;
        $survivor->ai_state ??= $duplicate->ai_state;
    }

    private function repointAiReplyEvents(TelegramSupportMessage $survivor, TelegramSupportMessage $duplicate): void
    {
        SupportAiReplyEvent::query()
            ->where('telegram_support_message_id', $duplicate->id)
            ->get()
            ->each(function (SupportAiReplyEvent $event) use ($survivor): void {
                $exists = SupportAiReplyEvent::query()
                    ->where('telegram_support_message_id', $survivor->id)
                    ->where('event_type', $event->event_type)
                    ->exists();

                if ($exists) {
                    $event->delete();

                    return;
                }

                $event->telegram_support_message_id = $survivor->id;
                $event->save();
            });
    }

    private function repointQuestionClassifications(TelegramSupportMessage $survivor, TelegramSupportMessage $duplicate): void
    {
        SupportQuestionClassification::query()
            ->where('telegram_support_message_id', $duplicate->id)
            ->get()
            ->each(function (SupportQuestionClassification $classification) use ($survivor): void {
                $exists = SupportQuestionClassification::query()
                    ->where('telegram_support_message_id', $survivor->id)
                    ->where('classifier_version', $classification->classifier_version)
                    ->exists();

                if ($exists) {
                    $classification->delete();

                    return;
                }

                $classification->telegram_support_message_id = $survivor->id;
                $classification->save();
            });
    }

    private function dateOf(TelegramSupportMessage $message): string
    {
        return Carbon::instance($message->sent_at)
            ->timezone(config('app.timezone'))
            ->toDateString();
    }

    public function down(): void
    {
        // Слияние дублей необратимо по определению: удалённые строки — точные
        // копии выживших по содержимому Telegram.
    }
};
