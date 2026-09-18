<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Jobs\SendZapisiPollJob;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\TelegramPoll;
use App\Models\TelegramPollAnswer;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Опросы @zapisi_ORSbot в чатах групп: создание (куратор в админке), приём
 * голосов (апдейт poll_answer из ProcessTelegramZapisiUpdate), закрытие.
 * Опросы НЕ анонимные — результаты нужны поимённо; участники чата тоже видят,
 * кто как голосовал (так устроен Telegram).
 */
final class ZapisiPollService
{
    /** Лимиты Bot API sendPoll. */
    public const QUESTION_MAX = 300;

    public const OPTION_MAX = 100;

    public const OPTIONS_MIN = 2;

    public const OPTIONS_MAX = 10;

    /**
     * @param  array<int, mixed>  $options
     */
    public function create(Group $group, string $question, array $options, bool $multiple, ?User $by = null): TelegramPoll
    {
        $chatId = (string) ($group->telegram_chat_id ?? '');
        if ($chatId === '') {
            throw new InvalidArgumentException('У группы не привязан Telegram-чат.');
        }

        $question = trim($question);
        if ($question === '' || mb_strlen($question) > self::QUESTION_MAX) {
            throw new InvalidArgumentException('Вопрос: от 1 до '.self::QUESTION_MAX.' символов.');
        }

        $options = array_values(array_filter(
            array_map(fn ($o): string => trim((string) (is_array($o) ? ($o['text'] ?? '') : $o)), $options),
            fn (string $o): bool => $o !== '',
        ));
        if (count($options) < self::OPTIONS_MIN || count($options) > self::OPTIONS_MAX) {
            throw new InvalidArgumentException('Вариантов ответа: от '.self::OPTIONS_MIN.' до '.self::OPTIONS_MAX.'.');
        }
        foreach ($options as $option) {
            if (mb_strlen($option) > self::OPTION_MAX) {
                throw new InvalidArgumentException('Вариант ответа длиннее '.self::OPTION_MAX." символов: «{$option}».");
            }
        }

        $poll = TelegramPoll::create([
            'group_id' => $group->id,
            'chat_id' => $chatId,
            'question' => $question,
            'options' => $options,
            'allows_multiple' => $multiple,
            'status' => TelegramPoll::STATUS_PENDING,
            'created_by' => $by?->id,
        ]);

        SendZapisiPollJob::dispatch($poll->id);

        return $poll;
    }

    /**
     * Апдейт poll_answer: {poll_id, user{id,…} | voter_chat, option_ids[]}.
     * Чата в апдейте нет — опрос ищем по tg_poll_id. Пустой option_ids — голос
     * отозван (строку оставляем, чтобы в админке было видно «передумал»).
     *
     * @param  array<string, mixed>  $pollAnswer
     */
    public function recordAnswer(array $pollAnswer): void
    {
        $tgPollId = (string) ($pollAnswer['poll_id'] ?? '');
        $tgUser = $pollAnswer['user'] ?? null;

        // Голос от имени канала/чата (voter_chat) не привязать к человеку.
        if ($tgPollId === '' || ! is_array($tgUser) || ! isset($tgUser['id'])) {
            return;
        }

        $poll = TelegramPoll::query()->where('tg_poll_id', $tgPollId)->first();
        if ($poll === null) {
            return; // чужой опрос (не нашим ботом из админки)
        }

        $tgUserId = (int) $tgUser['id'];
        $name = trim(((string) ($tgUser['first_name'] ?? '')).' '.((string) ($tgUser['last_name'] ?? '')));

        TelegramPollAnswer::updateOrCreate(
            ['telegram_poll_id' => $poll->id, 'telegram_user_id' => $tgUserId],
            [
                'user_id' => TelegramCommandAcl::findUser($tgUserId)?->id,
                'tg_username' => isset($tgUser['username']) ? (string) $tgUser['username'] : null,
                'tg_name' => $name !== '' ? $name : null,
                'option_ids' => array_values(array_map('intval', (array) ($pollAnswer['option_ids'] ?? []))),
                'answered_at' => now(),
            ],
        );

        Log::info('ZapisiPoll: answer recorded', [
            'poll_id' => $poll->id,
            'telegram_user_id' => $tgUserId,
            'options' => $pollAnswer['option_ids'] ?? [],
        ]);
    }

    /** Закрыть опрос в чате (stopPoll — бот-опрос в группе может остановить только бот). */
    public function close(TelegramPoll $poll): void
    {
        if ($poll->status !== TelegramPoll::STATUS_SENT || $poll->message_id === null) {
            throw new InvalidArgumentException('Закрыть можно только отправленный идущий опрос.');
        }

        $token = (string) (MarketingSetting::cached()?->zapisi_bot_token ?? '');
        if ($token === '') {
            throw new RuntimeException('Не задан токен бота @zapisi_ORSbot.');
        }

        $response = Http::connectTimeout(5)->timeout(15)->post("https://api.telegram.org/bot{$token}/stopPoll", [
            'chat_id' => $poll->chat_id,
            'message_id' => $poll->message_id,
        ]);

        $description = (string) ($response->json('description') ?? '');
        $alreadyClosed = str_contains(mb_strtolower($description), 'already been closed');

        if ((! $response->successful() || ! ($response->json('ok') ?? false)) && ! $alreadyClosed) {
            throw new RuntimeException('Telegram не закрыл опрос: '.($description !== '' ? $description : $response->body()));
        }

        $poll->update(['status' => TelegramPoll::STATUS_CLOSED, 'closed_at' => now()]);
    }
}
