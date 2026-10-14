<?php

declare(strict_types=1);

namespace App\Services\OpenLessonCards;

use App\Models\BotSubscriber;
use App\Models\Course;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\SubscriberMagnet;
use App\Services\LeadNotifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * «Воронка» @samskrte_bot по образцу ботов конференций (@AlpinaAIconfbot):
 *
 *  /start → карточка ближайшего открытого занятия + «🎁 Подарки» с кнопкой
 *  «✍️ Участвовать» + «Остальные разделы — в меню внизу» (reply-меню);
 *  каждый раздел меню заканчивается одним и тем же призывом «Участвовать»;
 *  «Участвовать» → подписка на @samskrte (проверяем getChatMember — бот
 *  админ канала) → согласие → анкета из трёх вопросов → лид в CRM →
 *  подарки с полки подписчика (SubscriberMagnet).
 */
class OpenLessonFunnel
{
    public const CB_JOIN = 'ol:join';

    public const CB_SUBSCRIBED = 'ol:sub';

    public const CB_NAME_KEEP = 'ol:q:name';

    public const CB_INTEREST = 'ol:q:int:';

    public const CB_TIME = 'ol:q:time:';

    public function __construct(
        private readonly OpenLessonOccasions $occasions,
        private readonly OpenLessonCardBuilder $builder,
        private readonly OpenLessonCardSender $sender,
        private readonly OpenLessonTelegram $telegram,
    ) {}

    /** Ответ на /start без токена: три сообщения, как у ботов конференций. */
    public function welcome(string $chatId): void
    {
        // Меню уйдёт третьим сообщением — карточка не должна слать своё раньше.
        BotSubscriber::query()->updateOrCreate(['chat_id' => $chatId], ['menu_sent_at' => now()]);

        $next = $this->occasions->between(now(), now()->addDays((int) config('open_lesson_cards.list_horizon_days', 14))->endOfDay())->first();
        if ($next !== null) {
            $this->sender->sendCard($next, $chatId, OpenLessonCardBuilder::STAGE_MANUAL);
        }

        $this->send($chatId, "🎁 <b>Подарки для начинающих</b>\n\n"
            .$this->giftsList()
            ."\n\nЗаполните короткую анкету — сразу пришлю всё в этот чат."
            ."\n\n".$this->consent('«✍️ Участвовать»'), [[$this->joinButton()]]);

        $this->sendMenu($chatId, 'Остальные разделы — в меню внизу 👇');
    }

    public function sendMenu(string $chatId, string $text): void
    {
        BotSubscriber::query()->updateOrCreate(['chat_id' => $chatId], ['menu_sent_at' => now()]);
        $this->telegram->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode($this->builder->replyMenu(), JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function program(string $chatId): void
    {
        $courses = Course::query()->where('is_visible', true)->orderBy('id')->get()
            ->map(fn (Course $course): array => [$course, $course->upcomingSchedules(1)->first()])
            ->filter(fn (array $pair): bool => $pair[1] !== null)
            ->sortBy(fn (array $pair) => $pair[1]->start)
            ->take(10);

        $lines = $courses->map(function (array $pair): string {
            [$course, $schedule] = $pair;
            $date = $schedule->start->copy()->timezone(config('app.timezone'))->locale('ru')->isoFormat('D MMMM');

            return '• <a href="'.e($this->builder->courseUrl($course)).'">'.e((string) $course->title).'</a> — ближайшее занятие '.$date;
        })->implode("\n");

        $text = "📚 <b>Чему учим сейчас</b>\n\n"
            .($lines !== '' ? $lines : 'Расписание групп — на сайте.')
            ."\n\nКурсы, которые только собираем, — в «Ждуне»: проголосуйте, и группа откроется, когда наберётся."
            .$this->cta();

        $this->send($chatId, $text, [
            [['text' => '📅 Расписание', 'url' => (string) config('open_lesson_cards.schedule_url')]],
            [config('features.waitlist_voting', false)
                ? ['text' => '⏳ Ждун — курсы, которые собираем', 'callback_data' => 'wl:page:0']
                : ['text' => '⏳ Ждун — курсы, которые собираем', 'url' => rtrim((string) config('open_lesson_cards.site_url'), '/').'/online/zhdun']],
            [$this->joinButton()],
        ]);
    }

    public function gifts(string $chatId): void
    {
        $this->send($chatId, "🎁 <b>Подарки для начинающих</b>\n\nЗабираете после анкеты:\n".$this->giftsList().$this->cta(), [[$this->joinButton()]]);
    }

    public function site(string $chatId): void
    {
        $site = (string) config('open_lesson_cards.site_url');

        $this->send($chatId, '🌐 На сайте — все курсы, расписание групп и личный кабинет ученика. '
            .'Анонсы и разборы — в канале <a href="'.e((string) config('open_lesson_cards.channel_url')).'">'.e((string) config('open_lesson_cards.channel')).'</a>.'
            .$this->cta(), [
                [['text' => '🌐 samskrte.ru', 'url' => $site]],
                [['text' => '🤝 Стать добровольцем', 'url' => (string) config('open_lesson_cards.volunteer_url')]],
                [$this->joinButton()],
            ]);
    }

    /** «✍️ Участвовать»: уже прошёл — сразу подарки; иначе шаг подписки. */
    public function join(string $chatId): void
    {
        $subscriber = $this->subscriber($chatId);
        if ($subscriber->joined_at !== null) {
            $this->deliverGifts($chatId, 'Анкета уже заполнена — вот ваши подарки:');

            return;
        }

        $subscriber->update(['join_step' => BotSubscriber::STEP_SUBSCRIBE]);

        $this->send($chatId, "Остался шаг 🙌\n\nЧтобы получить подарки, подпишитесь на наш канал "
            .e((string) config('open_lesson_cards.channel')).' — там анонсы открытых занятий, разборы текстов и расписание групп.', [
                [['text' => '✅ Подписаться', 'url' => (string) config('open_lesson_cards.channel_url')]],
            ]);

        $this->send($chatId, 'Подписались? Нажмите «✅ Я подписался» ниже — или просто напишите «подписался» 👇'
            ."\n\n".$this->consent('«✅ Я подписался»'), [
                [['text' => '✅ Я подписался', 'callback_data' => self::CB_SUBSCRIBED]],
            ]);
    }

    /** @return bool true — callback наш */
    public function handleCallback(string $chatId, string $data, string $callbackId, array $from): bool
    {
        if ($data === self::CB_JOIN) {
            $this->answer($callbackId);
            $this->join($chatId);

            return true;
        }

        if ($data === self::CB_SUBSCRIBED) {
            $this->confirmSubscription($chatId, $callbackId, $from);

            return true;
        }

        $subscriber = $this->subscriber($chatId);

        if ($data === self::CB_NAME_KEEP && $subscriber->join_step === BotSubscriber::STEP_NAME) {
            $this->answer($callbackId);
            $this->saveName($subscriber, (string) ($subscriber->first_name ?? ''));

            return true;
        }

        if (str_starts_with($data, self::CB_INTEREST) && $subscriber->join_step === BotSubscriber::STEP_INTEREST) {
            $key = substr($data, strlen(self::CB_INTEREST));
            $this->answer($callbackId);
            if (array_key_exists($key, (array) config('open_lesson_cards.interests'))) {
                $subscriber->update(['join_interest' => $key, 'join_step' => BotSubscriber::STEP_TIME]);
                $this->askTime($chatId);
            }

            return true;
        }

        if (str_starts_with($data, self::CB_TIME) && $subscriber->join_step === BotSubscriber::STEP_TIME) {
            $key = substr($data, strlen(self::CB_TIME));
            $this->answer($callbackId);
            if (array_key_exists($key, (array) config('open_lesson_cards.times'))) {
                $subscriber->update(['join_time' => $key]);
                $this->finish($subscriber->fresh() ?? $subscriber);
            }

            return true;
        }

        // Устаревшая кнопка анкеты (шаг уже пройден) — просто гасим часики.
        if (str_starts_with($data, 'ol:q:')) {
            $this->answer($callbackId);

            return true;
        }

        return false;
    }

    /** Свободный текст на шагах анкеты: «подписался» и имя. true — текст наш. */
    public function handleText(string $chatId, string $text, array $from): bool
    {
        $subscriber = BotSubscriber::query()->where('chat_id', $chatId)->first();
        if ($subscriber === null || $text === '' || str_starts_with($text, '/')) {
            return false;
        }

        if ($subscriber->join_step === BotSubscriber::STEP_SUBSCRIBE && preg_match('/подписал/iu', $text) === 1) {
            $this->confirmSubscription($chatId, '', $from);

            return true;
        }

        if ($subscriber->join_step === BotSubscriber::STEP_NAME) {
            $this->saveName($subscriber, $text);

            return true;
        }

        return false;
    }

    private function confirmSubscription(string $chatId, string $callbackId, array $from): void
    {
        $subscriber = $this->subscriber($chatId);
        if ($subscriber->joined_at !== null) {
            $this->answer($callbackId);
            $this->deliverGifts($chatId, 'Анкета уже заполнена — вот ваши подарки:');

            return;
        }

        if (! $this->isChannelMember((string) ($from['id'] ?? $chatId))) {
            $text = 'Не вижу подписки на '.config('open_lesson_cards.channel').' — подпишитесь и нажмите ещё раз.';
            $callbackId !== ''
                ? $this->answer($callbackId, $text, true)
                : $this->send($chatId, e($text));

            return;
        }

        $this->answer($callbackId, 'Спасибо за подписку!');
        $subscriber->update([
            'consent_at' => now(),
            'join_step' => BotSubscriber::STEP_NAME,
            'username' => isset($from['username']) ? mb_substr((string) $from['username'], 0, 64) : $subscriber->username,
        ]);

        $name = trim((string) $subscriber->first_name);
        $this->send($chatId, "Спасибо! Три коротких вопроса — и пришлю подарки.\n\n1/3. Как к вам обращаться? Напишите имя.",
            $name !== '' ? [[['text' => 'Оставить «'.mb_strimwidth($name, 0, 30, '…').'»', 'callback_data' => self::CB_NAME_KEEP]]] : []);
    }

    private function saveName(BotSubscriber $subscriber, string $name): void
    {
        $name = trim(mb_substr(strip_tags($name), 0, 128));
        $subscriber->update(['join_name' => $name !== '' ? $name : null, 'join_step' => BotSubscriber::STEP_INTEREST]);

        $rows = collect((array) config('open_lesson_cards.interests'))
            ->map(fn (string $label, string $key): array => [['text' => $label, 'callback_data' => self::CB_INTEREST.$key]])
            ->values()->all();

        $this->send($subscriber->chat_id, '2/3. Что вам интереснее всего?', $rows);
    }

    private function askTime(string $chatId): void
    {
        $rows = [collect((array) config('open_lesson_cards.times'))
            ->map(fn (string $label, string $key): array => ['text' => $label, 'callback_data' => self::CB_TIME.$key])
            ->values()->all()];

        $this->send($chatId, '3/3. Когда вам удобнее заниматься (по Москве)?', $rows);
    }

    /** Анкета заполнена: лид в CRM (один на чат), заметка с ответами, подарки. */
    private function finish(BotSubscriber $subscriber): void
    {
        $interest = (string) (config('open_lesson_cards.interests.'.$subscriber->join_interest) ?? '—');
        $time = (string) (config('open_lesson_cards.times.'.$subscriber->join_time) ?? '—');
        $username = $subscriber->username !== null ? '@'.$subscriber->username : null;

        $lead = Lead::query()->where('telegram_chat_id', $subscriber->chat_id)->latest('id')->first();
        $isNew = $lead === null;

        if ($isNew) {
            $lead = Lead::create([
                'name' => $subscriber->join_name ?? $subscriber->first_name,
                'contact' => $username ?? 'tg:'.$subscriber->chat_id,
                'social' => $username,
                'telegram_chat_id' => $subscriber->chat_id,
                'is_promo_agreed' => true,
                'status' => Lead::firstStageKey() ?? 'new',
                'utm_source' => 'samskrte_bot',
                'utm_medium' => 'telegram_bot',
                'utm_campaign' => 'bot_join',
            ]);
        } else {
            $lead->update(array_filter([
                'is_promo_agreed' => true,
                'name' => blank($lead->name) ? ($subscriber->join_name ?? $subscriber->first_name) : null,
                'social' => blank($lead->social) ? $username : null,
            ], fn ($value): bool => $value !== null));
        }

        LeadNote::query()->create([
            'lead_id' => $lead->id,
            'type' => LeadNote::TYPE_NOTE,
            'channel' => 'telegram',
            'subject' => 'Анкета в @samskrte_bot',
            'body' => "Имя: ".($subscriber->join_name ?? '—')
                ."\nИнтерес: {$interest}"
                ."\nУдобное время: {$time}"
                ."\nПодписка на ".config('open_lesson_cards.channel').': да'
                ."\nСогласие на ПД и рассылку: ".$subscriber->consent_at?->format('d.m.Y H:i'),
        ]);

        $subscriber->update(['join_step' => BotSubscriber::STEP_DONE, 'joined_at' => now(), 'lead_id' => $lead->id]);

        if ($isNew) {
            try {
                app(LeadNotifier::class)->newLead($lead);
            } catch (\Throwable $e) {
                Log::warning('OpenLessonFunnel: lead notify failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
            }
        }

        $this->deliverGifts($subscriber->chat_id, '🎉 Готово, спасибо! Ваши подарки:');

        $next = $this->occasions->between(now(), now()->addDays((int) config('open_lesson_cards.list_horizon_days', 14))->endOfDay())->first();
        if ($next !== null) {
            $this->send($subscriber->chat_id, 'А ещё приходите на ближайшее открытое занятие — оно бесплатное:');
            $this->sender->sendCard($next, $subscriber->chat_id, OpenLessonCardBuilder::STAGE_MANUAL);
        }
    }

    private function deliverGifts(string $chatId, string $intro): void
    {
        $rows = $this->magnets()
            ->map(fn (SubscriberMagnet $magnet): array => [['text' => '🎁 '.mb_strimwidth((string) $magnet->title, 0, 60, '…'), 'url' => (string) $magnet->publicUrl()]])
            ->values()->all();

        if ($rows === []) {
            $this->send($chatId, 'Подарки скоро появятся — пришлю их сюда. А пока загляните в «🎙 Ближайшие занятия».');

            return;
        }

        $this->send($chatId, $intro."\n\n".$this->giftsList(), $rows);
    }

    /** @return Collection<int, SubscriberMagnet> */
    private function magnets(): Collection
    {
        return SubscriberMagnet::query()->active()->get()
            ->filter(fn (SubscriberMagnet $magnet): bool => $magnet->publicUrl() !== null)
            ->values();
    }

    private function giftsList(): string
    {
        $magnets = $this->magnets();
        if ($magnets->isEmpty()) {
            return 'Материалы для начинающих — пришлю после анкеты.';
        }

        return $magnets->map(function (SubscriberMagnet $magnet): string {
            $description = trim((string) $magnet->description);

            return '• <b>'.e((string) $magnet->title).'</b>'.($description !== '' ? ' — '.e(mb_strimwidth($description, 0, 140, '…')) : '');
        })->implode("\n");
    }

    /** Единый призыв в конце каждого раздела меню. */
    private function cta(): string
    {
        return "\n\nАнкета короткая — минута. После неё сразу пришлю подарки для начинающих.\n\nУчастие бесплатное.";
    }

    private function consent(string $button): string
    {
        $legal = (array) config('open_lesson_cards.legal');

        return "<i>Нажимая {$button}, вы принимаете "
            .'<a href="'.e((string) ($legal['privacy'] ?? '')).'">политику конфиденциальности</a>, даёте '
            .'<a href="'.e((string) ($legal['personal_data'] ?? '')).'">согласие на обработку персональных данных</a> и '
            .'<a href="'.e((string) ($legal['promo'] ?? '')).'">на получение анонсов школы</a>.</i>';
    }

    /** @return array{text: string, callback_data: string} */
    private function joinButton(): array
    {
        return ['text' => '✍️ Участвовать', 'callback_data' => self::CB_JOIN];
    }

    /**
     * Подписан ли пользователь на канал. Бот должен быть админом канала;
     * если Telegram не ответил (бот не админ, сбой) — пропускаем, а не
     * запираем человека перед подарками из-за нашей настройки.
     */
    private function isChannelMember(string $userId): bool
    {
        $response = $this->telegram->call('getChatMember', [
            'chat_id' => (string) config('open_lesson_cards.channel'),
            'user_id' => $userId,
        ]);

        if ($response['status'] !== OpenLessonTelegram::OK) {
            Log::warning('OpenLessonFunnel: getChatMember failed, letting through', ['description' => $response['description']]);

            return true;
        }

        return in_array($response['result']['status'] ?? null, ['creator', 'administrator', 'member', 'restricted'], true);
    }

    private function subscriber(string $chatId): BotSubscriber
    {
        return BotSubscriber::query()->firstOrCreate(['chat_id' => $chatId], ['source' => BotSubscriber::SOURCE_START]);
    }

    /** @param  array<int, array<int, array<string, string>>>  $rows */
    private function send(string $chatId, string $text, array $rows = []): void
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];
        if ($rows !== []) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
        }

        $this->telegram->call('sendMessage', $payload);
    }

    private function answer(string $callbackId, string $text = '', bool $alert = false): void
    {
        if ($callbackId === '') {
            return;
        }

        $this->telegram->call('answerCallbackQuery', ['callback_query_id' => $callbackId]
            + ($text !== '' ? ['text' => $text] : [])
            + ($alert ? ['show_alert' => true] : []));
    }
}
