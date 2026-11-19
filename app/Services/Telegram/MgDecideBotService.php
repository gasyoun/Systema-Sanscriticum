<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Контрол-бот MG: голосование @DECIDE из Telegram поверх vote-inbox (H6452).
 *
 * Транспорт — свой long-poll (telegram:poll-mg-decide, паттерн
 * telegram:poll-student), НЕ вебхук: входной узел TG нестабилен, а зависеть
 * от него нечем. Вердикты только MG: айфейс update'ов гейтится
 * mg_decide.allowed_tg_ids, посторонние молча игнорируются (fail-closed).
 *
 * /decide рендерит ≤4 карточек за вызов (рекомендация первой — правила
 * interactive-grilling) из mg-decide/cards.json в локальном clone
 * gasyoun/vote-inbox. Тап вердикта пишет decisions/<sheet_id>/pack-NN.json
 * по schema/decisions-pack.schema.json (pack_size=1 — пакет полон сразу,
 * watcher применяет после одного голоса) и пушит в origin; коммит+пуш —
 * ОДНИМ вызовом шелла (watcher-safe-commit), при гонке — fetch+rebase+retry.
 * Студенческие таблицы и боты этот сервис не трогает.
 */
final class MgDecideBotService
{
    private const CALLBACK_PREFIX = 'mgd:';

    private const DECISIONS = ['approve', 'reject', 'defer'];

    private const DECISION_LABELS = [
        'approve' => '✅ принять',
        'reject' => '✖ отклонить',
        'defer' => '⏸ отложить',
    ];

    public function __construct() {}

    /** true — апдейт наш и обработан, поллеру дальше идти нечего. */
    public function handleUpdate(array $update): bool
    {
        $fromId = (int) ($update['callback_query']['from']['id']
            ?? $update['message']['from']['id']
            ?? 0);

        if (! $this->isAllowed($fromId)) {
            if ($fromId !== 0) {
                Log::warning('MgDecideBot: update from a non-allowed id dropped', ['from_id' => $fromId]);
            }

            return true;
        }

        $callback = $update['callback_query'] ?? null;
        if (is_array($callback)) {
            $this->handleCallback($callback);

            return true;
        }

        $message = $update['message'] ?? null;
        if (! is_array($message)) {
            return true;
        }

        $chatId = (string) ($message['chat']['id'] ?? '');
        if ($chatId === '') {
            return true;
        }

        $text = trim((string) ($message['text'] ?? ''));
        $command = strtolower(explode('@', explode(' ', $text)[0])[0]);

        if ($command === '/decide') {
            $this->sendDecideBatch($chatId);

            return true;
        }

        if ($command === '/start') {
            $this->sendWelcome($chatId);

            return true;
        }

        return true;
    }

    /** Карточки: загрузка, фильтр уже решённых, очередь первых ≤N. */
    public function pendingCards(): array
    {
        $cards = $this->loadCards();
        $decided = $this->decidedCardIds($this->cardsSheetId($cards));

        return array_values(array_filter(
            $cards['cards'] ?? [],
            fn (array $card): bool => ! in_array((string) ($card['id'] ?? ''), $decided, true)
        ));
    }

    /**
     * Чистая сборка карточки (без сети) — для тестов и --fake-update смоука.
     *
     * @return array{0: string, 1: string} [text, reply_markup json]
     */
    public function buildCardMessage(array $card): array
    {
        $id = (string) ($card['id'] ?? '');
        if ($id === '' || strlen(self::CALLBACK_PREFIX.'approve:'.$id) > 64) {
            throw new RuntimeException("card id пуст или превышает лимит callback_data: '{$id}'");
        }

        $title = $this->plain($card['title'] ?? $id);
        $question = $this->plain($card['question'] ?? '');
        $recommendation = $this->plain($card['recommendation'] ?? '');
        $deadline = $this->plain($card['deadline'] ?? '');

        $text = "<b>Решение @DECIDE: {$title}</b>\n";
        if ($recommendation !== '') {
            $text .= "Рекомендация: <b>{$recommendation}</b>\n";
        }
        if ($question !== '') {
            $text .= "\n{$question}\n";
        }
        if ($deadline !== '') {
            $text .= "\n⏳ виза до {$deadline}";
        }
        $text .= "\n<i>источник: Uprava GTD_NEXT_ACTIONS.md, реестр</i>";

        $rows = [array_map(
            fn (string $decision): array => [
                'text' => self::DECISION_LABELS[$decision],
                'callback_data' => self::CALLBACK_PREFIX.$decision.':'.$id,
            ],
            self::DECISIONS
        )];

        return [$text, $this->keyboard($rows)];
    }

    /** Запись пакета вердикта + push. Возвращает факты для лога/смоука. */
    public function writePack(array $card, string $decision): array
    {
        if (! in_array($decision, self::DECISIONS, true)) {
            throw new RuntimeException("недопустимый вердикт: '{$decision}'");
        }

        $sheetId = $this->cardsSheetId($this->loadCards());
        $inbox = $this->inboxRoot();

        $this->syncInbox($inbox);
        $this->assertInboxClean($inbox);

        if ($sheetId === '') {
            throw new RuntimeException('cards.json без sheet_id');
        }
        $sheetDir = $inbox.'/decisions/'.$sheetId;

        $next = 1;
        foreach (glob($sheetDir.'/pack-*.json') ?: [] as $packFile) {
            if (preg_match('/pack-0*(\d+)\.json$/', (string) $packFile, $m)) {
                $next = max($next, ((int) $m[1]) + 1);
            }
        }

        // note: только непустая строка — схема типа string, null нарушает её
        // (leg B смоука 11-10-2026 поймал именно это); требуется только {id, decision}.
        $item = ['id' => (string) $card['id'], 'decision' => $decision];

        $pack = [
            'sheet_id' => $sheetId,
            'pack' => $next,
            'pack_size' => 1,
            'generated' => now()->format('d-m-Y'),
            'decided' => 1,
            'items' => [$item],
        ];

        $name = sprintf('pack-%02d.json', $next);
        $target = $sheetDir.'/'.$name;
        if (! is_dir($sheetDir) && ! mkdir($sheetDir, 0755, true)) {
            throw new RuntimeException("не создать каталог пакета: {$sheetDir}");
        }
        file_put_contents($target, (string) json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");

        $result = $this->landPack($inbox, $target, $sheetId, (string) $card['id'], $decision);

        return ['pack_path' => $target, 'pack' => $pack] + $result;
    }

    // ---------------------------------------------------------------- private

    private function handleCallback(array $callback): void
    {
        $chatId = (string) ($callback['message']['chat']['id'] ?? '');
        $messageId = $callback['message']['message_id'] ?? null;
        $cbId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');

        if (! str_starts_with($data, self::CALLBACK_PREFIX)) {
            return;
        }

        $rest = substr($data, strlen(self::CALLBACK_PREFIX));
        $parts = explode(':', $rest, 2);
        $decision = $parts[0] ?? '';
        $cardId = $parts[1] ?? '';

        if (! in_array($decision, self::DECISIONS, true) || $cardId === '') {
            $this->answer($cbId, 'Не поняла кнопку — вызовите /decide заново.');

            return;
        }

        $card = null;
        foreach ($this->loadCards()['cards'] ?? [] as $candidate) {
            if ((string) ($candidate['id'] ?? '') === $cardId) {
                $card = $candidate;
                break;
            }
        }

        if ($card === null) {
            $this->answer($cbId, 'Карточка устарела — вызовите /decide заново.');

            return;
        }

        try {
            $written = $this->writePack($card, $decision);
            $this->answer($cbId, '✅ Вердикт записан (pack '.ltrim((string) $written['pack']['pack'], '0').'), watcher применит.');
            if ($chatId !== '' && $messageId !== null) {
                $this->tg('editMessageReplyMarkup', [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'reply_markup' => $this->keyboard([]),
                ]);
            }
            Log::info('MgDecideBot: verdict pack landed', [
                'sheet_id' => $written['pack']['sheet_id'],
                'card' => $cardId,
                'decision' => $decision,
                'head' => $written['head'] ?? null,
            ]);
        } catch (Throwable $e) {
            Log::error('MgDecideBot: pack write failed', ['card' => $cardId, 'error' => $e->getMessage()]);
            $this->answer($cbId, 'Ошибка записи: '.$e->getMessage());
        }
    }

    private function sendDecideBatch(string $chatId): void
    {
        try {
            $cards = array_slice($this->pendingCards(), 0, max(1, (int) config('mg_decide.cards_per_call', 4)));
        } catch (Throwable $e) {
            Log::error('MgDecideBot: cards unavailable', ['error' => $e->getMessage()]);
            $this->tg('sendMessage', [
                'chat_id' => $chatId,
                'text' => 'Нет доступа к списку @DECIDE (vote-inbox): '.$e->getMessage(),
            ]);

            return;
        }

        if ($cards === []) {
            $this->tg('sendMessage', [
                'chat_id' => $chatId,
                'text' => 'Открытых @DECIDE нет — реестр чист. 🎉',
            ]);

            return;
        }

        foreach ($cards as $card) {
            try {
                [$text, $markup] = $this->buildCardMessage($card);
            } catch (Throwable $e) {
                Log::warning('MgDecideBot: card skipped', ['id' => $card['id'] ?? null, 'error' => $e->getMessage()]);

                continue;
            }
            $this->tg('sendMessage', [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'reply_markup' => $markup,
            ]);
        }
    }

    private function sendWelcome(string $chatId): void
    {
        $this->tg('sendMessage', [
            'chat_id' => $chatId,
            'text' => "Контрол-бот MG: вердикты @DECIDE с телефона.\n\n/decide — до 4 карточек из реестра.",
        ]);
    }

    /** @return array{cards: array, sheet_id: string, generated: string} */
    private function loadCards(): array
    {
        $inbox = $this->inboxRoot();
        $this->syncInbox($inbox);

        $path = $inbox.'/mg-decide/cards.json';
        if (! is_file($path)) {
            throw new RuntimeException("нет {$path} — запустите Uprava tools/mg_decide_cards.py");
        }

        $cards = json_decode((string) file_get_contents($path), true);
        if (! is_array($cards) || ! isset($cards['cards']) || ! is_array($cards['cards'])) {
            throw new RuntimeException('cards.json не читается как {sheet_id, cards[]}');
        }

        return $cards;
    }

    private function cardsSheetId(array $cards): string
    {
        return (string) ($cards['sheet_id'] ?? '');
    }

    /**
     * Свежайший origin: fetch + reset --hard на чистом дереве. Вызывается
     * ТОЛЬКО пока в clone нет собственных несмерженных изменений (чистые
     * fetch/reset уже после локального коммита раздавили бы его).
     */
    private function syncInbox(string $inbox): void
    {
        if (! is_dir($inbox.'/.git')) {
            throw new RuntimeException("нет clone vote-inbox: {$inbox}");
        }

        $branch = (string) config('mg_decide.pack_branch', 'master');
        $run = Process::timeout(60)->run(sprintf(
            'git -C %s fetch origin %s --quiet && git -C %s reset --hard --quiet origin/%s',
            escapeshellarg($inbox), escapeshellarg($branch), escapeshellarg($inbox), escapeshellarg($branch)
        ));

        if (! $run->successful()) {
            throw new RuntimeException('vote-inbox sync failed: '.mb_substr($run->errorOutput() ?: $run->output(), 0, 200));
        }
    }

    private function assertInboxClean(string $inbox): void
    {
        $run = Process::timeout(15)->run(sprintf('git -C %s status --porcelain', escapeshellarg($inbox)));
        if (! $run->successful() || trim($run->output()) !== '') {
            throw new RuntimeException('vote-inbox clone грязный — ручная разборка нужна: '.mb_substr($run->output(), 0, 200));
        }
    }

    /**
     * Коммит + push ОДНИМ вызовом шелла, затем проверка против HEAD
     * (watcher-safe-commit). Отказ push (гонка с другим писателем пакета) —
     * fetch + rebase + повтор один раз.
     */
    private function landPack(string $inbox, string $target, string $sheetId, string $cardId, string $decision): array
    {
        $branch = (string) config('mg_decide.pack_branch', 'master');
        $name = (string) config('mg_decide.git_author_name');
        $email = (string) config('mg_decide.git_author_email');
        $message = sprintf('%s: %s -> %s (mg-decide-bot)', $sheetId, $cardId, $decision);

        $cmd = sprintf(
            'git -C %s add %s && git -C %s -c user.name=%s -c user.email=%s commit -q -m %s && git -C %s push -q origin %s',
            escapeshellarg($inbox), escapeshellarg($target),
            escapeshellarg($inbox), escapeshellarg($name), escapeshellarg($email), escapeshellarg($message),
            escapeshellarg($inbox), escapeshellarg($branch)
        );

        $run = Process::timeout(120)->run($cmd);
        if (! $run->successful()) {
            $err = $run->errorOutput().$run->output();
            if (! str_contains($err, 'non-fast-forward') && ! str_contains($err, '[rejected]')) {
                throw new RuntimeException('pack land failed: '.mb_substr($err, 0, 300));
            }

            $retry = Process::timeout(120)->run(sprintf(
                'git -C %s fetch origin %s --quiet && git -C %s rebase origin/%s && git -C %s push -q origin %s',
                escapeshellarg($inbox), escapeshellarg($branch),
                escapeshellarg($inbox), escapeshellarg($branch),
                escapeshellarg($inbox), escapeshellarg($branch)
            ));
            if (! $retry->successful()) {
                throw new RuntimeException('pack land failed after rebase: '.mb_substr($retry->errorOutput().$retry->output(), 0, 300));
            }
        }

        $verify = Process::timeout(15)->run(sprintf(
            'git -C %s rev-parse HEAD && git -C %s rev-parse origin/%s',
            escapeshellarg($inbox), escapeshellarg($inbox), escapeshellarg($branch)
        ));
        $heads = array_filter(array_map('trim', explode("\n", $verify->output())));

        return ['pushed' => $verify->successful() && count($heads) === 2 && $heads[0] === $heads[1], 'head' => $heads[0] ?? null];
    }

    /** Айфейки уже решённых карточек: id из всех непустых вердиктов пакета листа. */
    private function decidedCardIds(string $sheetId): array
    {
        if ($sheetId === '') {
            return [];
        }

        $decided = [];
        foreach (glob($this->inboxRoot().'/decisions/'.$sheetId.'/pack-*.json') ?: [] as $packFile) {
            $pack = json_decode((string) file_get_contents((string) $packFile), true);
            foreach ((array) ($pack['items'] ?? []) as $item) {
                if (($item['decision'] ?? null) !== null) {
                    $decided[] = (string) ($item['id'] ?? '');
                }
            }
        }

        return array_values(array_unique($decided));
    }

    private function isAllowed(int $fromId): bool
    {
        $allowed = array_map('intval', (array) config('mg_decide.allowed_tg_ids', []));

        return $fromId !== 0 && in_array($fromId, $allowed, true);
    }

    /** Схемное правило апстрима: в note запрещён '<' — режем его и в текстах карточек. */
    private function plain(string $text): string
    {
        return htmlspecialchars(str_replace('<', '', $text), ENT_QUOTES, 'UTF-8');
    }

    private function keyboard(array $rows): string
    {
        return (string) json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
    }

    private function answer(string $cbId, string $text = ''): void
    {
        if ($cbId === '') {
            return;
        }
        $this->tg('answerCallbackQuery', ['callback_query_id' => $cbId] + ($text !== '' ? ['text' => $text] : []));
    }

    /** Свой токен бота (не студенческий, не глобальный). Ошибки — лог, не исключение. */
    private function tg(string $method, array $payload): void
    {
        $token = (string) config('mg_decide.bot_token', '');
        if ($token === '') {
            Log::warning('MgDecideBot: MG_DECIDE_BOT_TOKEN пуст — ответ не отправлен', ['method' => $method]);

            return;
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/{$method}", $payload);
            if (! $response->json('ok')) {
                Log::warning('MgDecideBot: telegram api not ok', [
                    'method' => $method,
                    'description' => $response->json('description'),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('MgDecideBot: telegram api failed', [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function inboxRoot(): string
    {
        return rtrim((string) config('mg_decide.inbox_path', '/var/www/vote-inbox'), '/');
    }
}
