<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\ChatMessage;
use App\Models\SupportDailyRollup;
use App\Services\TelegramSupport\SupportDailyRollupAggregator;
use App\Services\TelegramSupport\SupportTopicClassifier;
use App\Support\SupportRollupMetrics;
use App\Support\UnifiedMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Дневные rollup'ы ВЕБ-стороны поддержки — H1837 (S10).
 *
 * Зеркало {@see SupportDailyRollupAggregator} для
 * `chat_messages` (веб-виджет, VK-бот, TG-student-бот). Пишет в ту же таблицу
 * `support_daily_rollups` с `channel` != telegram, считает метрики тем же
 * {@see SupportRollupMetrics} и классифицирует топики тем же
 * {@see SupportTopicClassifier} — иначе сводный deflection-отчёт по двум каналам
 * пришлось бы сверять руками.
 *
 * Хранилища сообщений при этом НЕ сливаются (запрет из
 * docs/support-subsystem-map.md): читаем `chat_messages` через общий read-слой
 * {@see UnifiedMessage}, ничего не переносим и не переписываем.
 *
 * **Ключ субъекта.** Не всякое веб-сообщение принадлежит операционному треду:
 * VK-бот, TG-student-бот и ручные ответы из UserResource\Pages\Dialogs пишут
 * `chat_messages` без `support_conversation_id`. Поэтому субъект — тред, если он
 * есть, иначе `user_id`; сообщения без обоих (не должно случаться, но данные
 * старше H536 бывают неполны) сводятся в одну строку «без атрибуции» за день,
 * чтобы не выпасть из «100 % входящих» вовсе.
 */
class WebSupportRollupAggregator
{
    public function __construct(
        private readonly SupportTopicClassifier $topicClassifier,
    ) {}

    /** @return int число созданных/обновлённых строк */
    public function aggregateDate(string|\DateTimeInterface $date): int
    {
        $day = CarbonImmutable::parse($date, config('app.timezone'));
        $start = $day->startOfDay();
        $end = $day->endOfDay();
        $unresolvedAfterHours = (int) config('support.rollup.unresolved_after_hours', 24);
        $now = CarbonImmutable::now(config('app.timezone'));

        $messages = ChatMessage::query()
            ->with('answeredBy:id,name')
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()) {
            return 0;
        }

        $written = 0;

        $messages
            ->groupBy(fn (ChatMessage $message): string => $this->subjectKey($message))
            ->each(function (Collection $group) use ($day, $unresolvedAfterHours, $now, &$written): void {
                /** @var ChatMessage $sample */
                $sample = $group->first();

                $unified = $group->map(fn (ChatMessage $message) => UnifiedMessage::fromChatMessage($message));

                $rollup = SupportDailyRollup::updateOrCreate(
                    [
                        'channel' => $unified->first()->channel,
                        'support_conversation_id' => $sample->support_conversation_id,
                        'web_user_id' => $sample->support_conversation_id === null ? $sample->user_id : null,
                        'conversation_date' => $day->startOfDay(),
                    ],
                    SupportRollupMetrics::fromMessages($unified, $unresolvedAfterHours, $now) + [
                        'telegram_support_chat_id' => null,
                        'has_new_contact' => $this->isFirstEverDay($sample, $day),
                    ],
                );

                $this->topicClassifier->classify($rollup);
                $written++;
            });

        return $written;
    }

    /**
     * Ключ группировки: канал + субъект. Канал входит в ключ сознательно — один и
     * тот же пользователь может в один день написать и в веб-виджет, и VK-боту, и
     * складывать это в одну строку значило бы потерять канальную разбивку, ради
     * которой S10 и делается.
     */
    private function subjectKey(ChatMessage $message): string
    {
        $channel = UnifiedMessage::fromChatMessage($message)->channel;

        $subject = match (true) {
            $message->support_conversation_id !== null => 'thread:'.$message->support_conversation_id,
            $message->user_id !== null => 'user:'.$message->user_id,
            default => 'unattributed',
        };

        return $channel.'|'.$subject;
    }

    /**
     * «Новый контакт» на веб-стороне = первое в истории сообщение этого субъекта
     * пришлось на этот день (симметрично contact.first_inbound_at на TG-стороне).
     */
    private function isFirstEverDay(ChatMessage $sample, CarbonImmutable $day): bool
    {
        $query = ChatMessage::query();

        if ($sample->support_conversation_id !== null) {
            $query->where('support_conversation_id', $sample->support_conversation_id);
        } elseif ($sample->user_id !== null) {
            $query->where('user_id', $sample->user_id)->whereNull('support_conversation_id');
        } else {
            return false;
        }

        $firstEver = $query->min('created_at');

        if ($firstEver === null) {
            return false;
        }

        return CarbonImmutable::parse($firstEver)
            ->timezone(config('app.timezone'))
            ->toDateString() === $day->toDateString();
    }
}
