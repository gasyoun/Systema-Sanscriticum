<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Services\Access\TelegramAdminNotifier;
use App\Support\TelegramSendGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * H8526: «мёртвая группа». Учитель/менеджер пишет в учебном чате «стоп»,
 * «серия окончена», «отмена всех занятий», «группа закрыта», «занятий
 * больше нет» — бот предлагает кнопки [Серия окончена] [Каникулы] [Не
 * останавливать]. Никакой автопомпы: архивирует только явный тап «Да»
 * (StopGroupCallbackService, префиксы srx:/srv:/srxn), чужой тап
 * отклоняется, решение подтверждается правкой сообщения с кнопкой.
 *
 * Отличие от H4253 (VacationCommandService): «стоп» архивирует ТОЛЬКО
 * группу текущего чата, а не ставит окно преподавателю на все его группы;
 * «Каникулы» кнопкой — групповой флаг без даты выхода.
 *
 * Порядок в цепочке ProcessTelegramZapisiUpdate: ПОСЛЕ явных команд
 * (DateAware/CancelClass/Vacation), ДО AnnounceReschedule/AnnounceCancel —
 * точная фраза «стоп» приоритетнее расплывчатого анонса отмены. Тексты
 * матчатся ТОЧНО (destructive intent), не стемами — ложное срабатывание
 * здесь дороже, чем в AnnounceCancel: студенческие «стоп» отсекаются ACL
 * беззвучно (студенческая поверхность — H8527, не здесь).
 *
 * my_chat_member со статусом kicked/left (бота выгнали) — автоархив той же
 * машиной: прощальный пост не отправляется (бота в чате больше нет),
 * MG получает алерт через TelegramAdminNotifier.
 */
final class StopGroupIntentService
{
    /** Точные фразы «группа умерла». Якорь ^$ на нормализованном тексте. */
    private const PHRASE_PATTERN = '/^(?:стоп[!.]?|серия окончена|отмена всех занятий|все занятия отменяются|группа закрыта|занятий больше нет|занятий больше не будет|останови бот(?:а)?|остановись)$/u';

    private const OFFER_CLAIM_TTL_SECONDS = 86400;

    private const APPLY_CLAIM_TTL_SECONDS = 604800;

    public const FAREWELL = '🛑 Бот остановлен в этой группе: серия занятий окончена. '
        .'Напоминания и опросы прекращены. Возобновить — через MG.';

    /**
     * Дёшево: только текст. Для CancelUsageHint (молчать, если сейчас будет
     * кнопка останова) и для раннего выхода в handle().
     *
     * @param  array<string, mixed>  $message
     */
    public static function matches(array $message): bool
    {
        $text = self::normalize($message);

        return $text !== '' && preg_match(self::PHRASE_PATTERN, $text) === 1;
    }

    public function handle(array $message): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $fromId = $message['from']['id'] ?? null;
        if (! is_numeric($chatId) || ! is_numeric($fromId) || ! self::matches($message)) {
            return;
        }

        $group = $this->authorizedGroup((int) $fromId, (string) $chatId);
        if ($group === null) {
            return;
        }

        if ($group->status === Group::STATUS_ARCHIVED) {
            SendZapisiBotMessageJob::dispatch((string) $chatId, 'Бот в этой группе уже остановлен. Возобновить — через MG.');

            return;
        }

        // Повтор того же сообщения — не дублируем предложение.
        $messageId = (int) ($message['message_id'] ?? 0);
        if (! TelegramSendGuard::claimKey('tg:stop-offer:'.$chatId.':'.$messageId, self::OFFER_CLAIM_TTL_SECONDS)) {
            Log::info('StopGroupIntent: offer already sent for this message, suppressed', ['chat_id' => $chatId]);

            return;
        }

        $text = "Остановить бота в этой группе?\n\n"
            .'«Серия окончена» — бот больше не будет писать в эту группу (напоминания и опросы прекращаются). '
            ."«Каникулы» — пауза без даты выхода.\n\n"
            .'Ничего не применяется без тапа по кнопке.';

        $this->sendOffer((string) $chatId, $text, [
            [['text' => '✅ Серия окончена', 'callback_data' => 'srx:'.$group->id]],
            [['text' => '⏸ Каникулы (пауза)', 'callback_data' => 'srv:'.$group->id]],
            [['text' => 'Не останавливать', 'callback_data' => 'srxn']],
        ], (int) $group->id);
    }

    /**
     * Контекстная проверка БЕЗ побочных эффектов: будет ли кнопка останова
     * для этого sender-а и чата. true — и handle() пошлёт то же самое
     * (CancelUsageHint молчит в пользу кнопки).
     */
    public function planOffer(array $message): bool
    {
        $chatId = $message['chat']['id'] ?? null;
        $fromId = $message['from']['id'] ?? null;
        if (! is_numeric($chatId) || ! is_numeric($fromId) || ! self::matches($message)) {
            return false;
        }

        return $this->authorizedGroup((int) $fromId, (string) $chatId) !== null;
    }

    /**
     * ACL + маппинг чата на группу: staff (super_admin/admin/manager) — любые
     * группы; teacher — только своя (H4253-правило); остальные — null беззвучно.
     */
    private function authorizedGroup(int $fromId, string $chatId): ?Group
    {
        $actor = app(TelegramCommandAcl::class)->resolve($fromId);
        if ($actor === null) {
            return null;
        }

        $group = Group::query()->where('telegram_chat_id', $chatId)->first();
        if ($group === null) {
            return null;
        }

        if (! TelegramCommandAcl::managesAll($actor['role'])) {
            $teacher = $actor['teacher'];
            if ($teacher === null || ! Group::ledBy($teacher->id)->whereKey($group->id)->exists()) {
                return null;
            }
        }

        return $group;
    }

    /**
     * Архив группы одной машиной для тапа «Серия окончена» и автоархива по
     * kicked/left. $farewell=false для кика — бота в чате больше нет.
     */
    public static function archiveGroup(Group $group, string $reason, string $actor, bool $farewell = true): void
    {
        $alreadyArchived = $group->status === Group::STATUS_ARCHIVED;
        if (! $alreadyArchived) {
            $group->status = Group::STATUS_ARCHIVED;
            $group->save();
        }

        if ($farewell && ! $alreadyArchived) {
            SendZapisiBotMessageJob::dispatch((string) $group->telegram_chat_id, self::FAREWELL);
        }

        app(TelegramAdminNotifier::class)->notifyAdmins(
            'Почтальон: группа «'.$group->name.'» (chat '.$group->telegram_chat_id.') '
            .($alreadyArchived ? 'уже была в архиве' : 'АРХИВИРОВАНА')
            .' — '.$reason.' ('.$actor.'). Напоминания/опросы прекращаются.'
        );

        Log::info('StopGroupIntent: group archived', [
            'group_id' => $group->id,
            'chat_id' => $group->telegram_chat_id,
            'reason' => $reason,
            'actor' => $actor,
            'already_archived' => $alreadyArchived,
        ]);
    }

    /** Кнопка «Каникулы»: групповой флаг без даты выхода (H3790-семантика). */
    public static function pauseGroup(Group $group, string $actor): void
    {
        $group->is_on_vacation = true;
        $group->vacation_resume_date = null;
        $group->save();

        Log::info('StopGroupIntent: group paused (vacation)', [
            'group_id' => $group->id,
            'chat_id' => $group->telegram_chat_id,
            'actor' => $actor,
        ]);
    }

    /**
     * my_chat_member со статусом kicked/left: бот покинул чат (или его
     * выгнали) — автоархив без прощального поста. Повторное добавление бота
     * статус не возвращает (H8526: воскресение — только явным действием MG).
     *
     * @param  array<string, mixed>  $myChatMember
     */
    public static function archiveByKick(array $myChatMember): void
    {
        $chatId = $myChatMember['chat']['id'] ?? null;
        $status = (string) ($myChatMember['new_chat_member']['status'] ?? '');
        if (! is_numeric($chatId) || ! in_array($status, ['kicked', 'left'], true)) {
            return;
        }

        $group = Group::query()->where('telegram_chat_id', (string) $chatId)->first();
        if ($group === null) {
            return;
        }

        self::archiveGroup($group, 'my_chat_member '.$status, 'telegram', farewell: false);
    }

    private static function normalize(array $message): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) ($message['text'] ?? ''))));
    }

    /** Токен @zapisi_ORSbot (MarketingSetting) с локальным фолбэком для тестов. */
    private static function botToken(): string
    {
        $zapisi = (string) (MarketingSetting::cached()?->zapisi_bot_token ?? '');
        if ($zapisi !== '') {
            return $zapisi;
        }

        return (string) (config('services.telegram.student_bot_token')
            ?: config('services.telegram.bot_token')
            ?: '');
    }

    /**
     * Синхронная отправка предложения (идемпотентность/транспорт — как в
     * SendZapisiBotMessageJob: клейм по тексту, транспортный сбой ключ не
     * отпускает, отказ Telegram — отпускает и логируется).
     *
     * @param  list<list<array{text: string, callback_data: string}>>  $keyboard
     */
    private function sendOffer(string $chatId, string $text, array $keyboard, int $groupId): void
    {
        if (! TelegramSendGuard::claim($chatId, $text)) {
            Log::info('StopGroupIntent: identical offer already sent, duplicate suppressed', ['chat_id' => $chatId]);

            return;
        }

        $token = self::botToken();
        if ($token === '') {
            return;
        }

        try {
            $response = Http::connectTimeout(5)->timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('StopGroupIntent: transport failure, retry suppressed to avoid duplicate', [
                'chat_id' => $chatId,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        if (! $response->successful() || ! ($response->json('ok') ?? false)) {
            TelegramSendGuard::release($chatId, $text);
            Log::warning('StopGroupIntent: Telegram sendMessage error', [
                'chat_id' => $chatId,
                'group_id' => $groupId,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 200),
            ]);

            return;
        }

        Log::info('StopGroupIntent: offer sent', [
            'chat_id' => $chatId,
            'group_id' => $groupId,
            'offer_message_id' => $response->json('result.message_id'),
        ]);
    }
}
