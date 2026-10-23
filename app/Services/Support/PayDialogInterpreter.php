<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Course;
use App\Models\Group;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Bot\CuratorAi;
use Illuminate\Support\Facades\Log;

/**
 * Что студент хочет оплатить или куда записаться — по ВСЕМУ разговору
 * (22-10-2026, features.support_pay_dialog_llm).
 *
 * Регулярки {@see PayChoiceOptions} ловят слова; здесь локальная модель
 * (Ollama, {@see CuratorAi::localChatWithUsage()} — данные не уходят наружу)
 * читает переписку целиком: студент, бот, куратор. «Давайте тот, что по
 * понедельникам», «а можно сразу всё?», «нет, второй» понимаются из того,
 * что было сказано выше.
 *
 * Модель только ВЫБИРАЕТ: вариант из каталога, который мы ей дали, и блок.
 * Сумму, ссылку и текст студенту собирает код из Tariff. Чужой id, блок без
 * активного тарифа, уже оплаченный блок, низкая уверенность, сбой узла или
 * не-JSON — null, и работает прежний путь на регулярках.
 *
 * @phpstan-import-type Option from PayChoiceOptions
 */
final class PayDialogInterpreter
{
    public const INTENTS = ['pay', 'enroll', 'pay_problem', 'human', 'other'];

    private const MISSING = ['what', 'block', 'both'];

    private const MAX_CATALOG = 20;

    public function __construct(
        private readonly CuratorAi $ai,
        private readonly PayChoiceOptions $choices,
        private readonly EnrollmentGroupResolver $groups,
        private readonly SupportPayLinkResolver $payLinks,
    ) {}

    /**
     * @param  list<string>  $studentTexts  входящие студента, новые первыми (для вариантов кабинета)
     * @return array{intent: string, option: ?array, missing: ?string, confidence: float, model: ?string}|null
     */
    public function interpret(TelegramSupportMessage $incoming, ?User $user, array $studentTexts): ?array
    {
        $catalog = $this->catalog($user, $studentTexts);
        $transcript = $this->transcript($incoming);

        if ($transcript === '') {
            return null;
        }

        $reply = $this->ai->localChatWithUsage([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => "КАТАЛОГ:\n".($catalog === [] ? '(пусто)' : implode("\n", array_column($catalog, 'line')))
                ."\n\nПЕРЕПИСКА (старые сверху, последнее — сообщение, на которое отвечаем):\n".$transcript],
        ], (int) config('support.pay_llm.timeout', 60), 0.0, think: false, maxTokens: 300);

        $data = $this->json($reply['content'] ?? null);
        if ($data === null) {
            Log::info('PayDialogInterpreter: модель не дала JSON', ['incoming_id' => $incoming->id, 'model' => $reply['model'] ?? null]);

            return null;
        }

        // Номер блока, которого студент не называл, модель придумывает
        // («следующий» → 3 вместо 2, пилот 23-10-2026) — берём следующий неоплаченный.
        if (! $this->choices->namesBlock((string) $incoming->text)) {
            $data['block'] = null;
        }

        return $this->validate($data, $catalog, $user, $reply['model'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array{course_id: int, group_id: ?int, line: string}>  $catalog
     * @return array{intent: string, option: ?array, missing: ?string, confidence: float, model: ?string}|null
     */
    private function validate(array $data, array $catalog, ?User $user, ?string $model): ?array
    {
        $intent = is_string($data['intent'] ?? null) ? $data['intent'] : '';
        $confidence = is_numeric($data['confidence'] ?? null) ? (float) $data['confidence'] : 0.0;

        if (! in_array($intent, self::INTENTS, true)) {
            return $this->reject('intent', $data);
        }

        // Уверенность нужна там, где решение действует: ссылка или человек.
        // «Не понял, что оплатить» безопасно и при низкой — бот просто спросит.
        $sure = $confidence >= (float) config('support.pay_llm.min_confidence', 0.7);
        if ($intent === 'human' && ! $sure) {
            return $this->reject('уверенность', $data);
        }

        $key = is_string($data['option'] ?? null) ? trim($data['option']) : '';
        $block = $data['block'] ?? null;
        $block = $block === 'full' ? PayChoiceOptions::FULL : (is_numeric($block) && (int) $block > 0 ? (int) $block : null);
        $missing = in_array($data['missing'] ?? null, self::MISSING, true) ? $data['missing'] : null;

        $option = null;
        if ($key !== '' && $key !== 'null') {
            // Выдумала вариант — не верим всему ответу.
            if (! isset($catalog[$key])) {
                return $this->reject('вариант не из каталога', $data);
            }

            $target = ['course_id' => $catalog[$key]['course_id'], 'group_id' => $catalog[$key]['group_id']];

            if ($block !== null) {
                $option = [...$target, 'block' => $block];
                if ($this->choices->tariff($option) === null || $this->choices->alreadyPaid($user, $option)) {
                    return $this->reject('нет тарифа или блок оплачен', $data);
                }
            } else {
                // Группа выбрана, блок не назван — следующий неоплаченный, как у правил.
                $default = $this->choices->resolve($user, '', ['target' => $target]);
                $option = $default['kind'] === 'link' ? $default['option'] : null;
            }
        }

        if (! $sure) {
            $option = null;
        }

        if (in_array($intent, ['pay', 'enroll', 'pay_problem'], true) && $option === null) {
            $missing ??= $key === '' || $key === 'null' ? 'what' : 'block';
        }

        return [
            'intent' => $intent,
            'option' => $option,
            'missing' => $option === null ? $missing : null,
            'confidence' => $confidence,
            'model' => $model,
        ];
    }

    /**
     * Каталог с короткими id: варианты кабинета студента (c…/g…) и группы,
     * в которые сейчас можно записаться (g…). Для каждого — что продаётся.
     *
     * @param  list<string>  $studentTexts
     * @return array<string, array{course_id: int, group_id: ?int, line: string}>
     */
    private function catalog(?User $user, array $studentTexts): array
    {
        $catalog = [];

        foreach ($this->choices->for($user, $studentTexts) as $option) {
            $key = $option['group_id'] !== null ? 'g'.$option['group_id'] : 'c'.$option['course_id'];
            $catalog[$key] = [
                'course_id' => $option['course_id'],
                'group_id' => $option['group_id'],
                'note' => 'у студента к оплате: '.$this->choices->label($option, $user),
            ];
        }

        // Не все группы школы (85 строк — модель тонула в каталоге, пилот
        // 23-10-2026), а только те, что студент назвал номером, днём или курсом.
        $pool = $this->groups->candidates();
        foreach (array_slice($studentTexts, 0, 6) as $text) {
            $named = $this->groups->matching($pool, (string) $text);
            $course = $this->payLinks->courseNamedIn((string) $text);
            if ($course !== null) {
                $named = $named->merge($pool->filter(fn (Group $g) => $g->courses->contains('id', $course->id)));
            }

            foreach ($named as $group) {
                $course = $this->groups->courseOf($group);
                if ($course !== null && count($catalog) < self::MAX_CATALOG) {
                    $catalog['g'.$group->id] ??= ['course_id' => (int) $course->id, 'group_id' => (int) $group->id, 'note' => null];
                }
            }
        }

        $out = [];
        foreach ($catalog as $key => $row) {
            $line = $this->describe($key, $row['course_id'], $row['group_id'], $user);
            if ($line === null) {
                continue;
            }

            $out[$key] = [
                'course_id' => $row['course_id'],
                'group_id' => $row['group_id'],
                'line' => $line.($row['note'] !== null ? '; '.$row['note'] : ''),
            ];
        }

        return $out;
    }

    private function describe(string $key, int $courseId, ?int $groupId, ?User $user): ?string
    {
        $course = Course::query()->with(['tariffs' => fn ($q) => $q->where('is_active', true)])->find($courseId);
        if ($course === null) {
            return null;
        }

        $blocks = $course->tariffs->where('type', 'block')->whereNull('block_half')
            ->pluck('block_number')->map(fn ($n) => (int) $n)
            ->reject(fn (int $n) => $this->choices->alreadyPaid($user, ['course_id' => $courseId, 'group_id' => null, 'block' => $n]))
            ->sort()->values()->all();
        $full = $course->tariffs->contains(fn ($t) => $t->type === 'full' && $t->membership_months === null);

        if ($blocks === [] && ! $full) {
            return null;
        }

        $group = $groupId !== null ? Group::query()->with(['schedules' => fn ($q) => $q->where('start', '>=', now())->orderBy('start')->limit(1)])->find($groupId) : null;
        $slot = $group?->schedules->first()?->start?->locale('ru')->isoFormat('dddd HH:mm');

        return sprintf(
            '%s: %s«%s»%s%s; блоки к оплате: %s; весь курс: %s',
            $key,
            $group !== null ? "группа «{$group->name}», курс " : 'курс ',
            $course->title,
            $group !== null ? ", статус {$group->status}" : '',
            $slot ? ", ближайшее занятие {$slot}" : '',
            $blocks === [] ? 'нет' : implode(', ', $blocks),
            $full ? 'да' : 'нет',
        );
    }

    /** Последние сообщения чата за 48 ч: кто говорит, когда, что. */
    private function transcript(TelegramSupportMessage $incoming): string
    {
        $messages = TelegramSupportMessage::query()
            ->where('telegram_chat_id', $incoming->telegram_chat_id)
            ->where('sent_at', '>=', now()->subHours(48))
            ->where('id', '<=', $incoming->id)
            ->orderByDesc('id')
            ->limit(max(2, (int) config('support.pay_llm.history', 20)))
            ->get()
            ->reverse();

        return $messages
            ->filter(fn (TelegramSupportMessage $m) => trim((string) $m->text) !== '')
            ->map(function (TelegramSupportMessage $m): string {
                $who = $m->direction === 'incoming' ? 'студент' : ($m->responder_type === 'ai' ? 'бот' : 'куратор');
                $text = (string) preg_replace('/https?:\/\/\S+/u', '[ссылка]', (string) $m->text);

                return sprintf('[%s %s] %s', $m->sent_at?->format('d.m H:i') ?? '', $who, mb_substr(trim($text), 0, 400));
            })
            ->implode("\n");
    }

    /** @param  array<string, mixed>  $data */
    private function reject(string $reason, array $data): null
    {
        Log::info('PayDialogInterpreter: ответ модели отброшен — '.$reason, ['reply' => $data]);

        return null;
    }

    /** @return array<string, mixed>|null */
    private function json(?string $content): ?array
    {
        if ($content === null) {
            return null;
        }

        $content = (string) preg_replace('/<think>.*?<\/think>/su', '', $content);
        if (preg_match('/\{.*\}/su', $content, $m) !== 1) {
            return null;
        }

        $data = json_decode($m[0], true);

        return is_array($data) ? $data : null;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
/no_think
Ты разбираешь переписку школы санскрита со студентом в Telegram. Твоя задача — понять, что студент хочет СЕЙЧАС, в последнем сообщении, с учётом всего разговора выше (что спрашивал бот, что отвечал куратор, что студент говорил раньше).

Верни ТОЛЬКО JSON без пояснений:
{"intent": "...", "option": "...", "block": ..., "missing": "...", "confidence": 0.0}

intent:
- "pay" — хочет оплатить курс/блок или получить ссылку на оплату;
- "enroll" — хочет записаться в группу (и готов платить);
- "pay_problem" — пытался оплатить, но не получается (ошибка, не проходит, кабинет не пускает);
- "human" — возврат денег, рассрочка, скидка, спор о сумме, оплата из-за рубежа, жалоба — это решает человек;
- "other" — последнее сообщение не про оплату и не про запись.

option — id варианта из КАТАЛОГА (например "g155" или "c459"), который имеет в виду студент; null, если непонятно. Выбирай ТОЛЬКО из каталога, не придумывай. Если бот переспросил, перечислив несколько групп, «вторая» значит вторая из перечисленных ботом. День недели и время, номер группы («гр.63»), название курса, «тот же, что сейчас» — всё это способы указать вариант. Новый вопрос об оплате («хочу оплатить», «как оплатить?»), в котором вариант не назван, — option null и missing "what", даже если раньше в переписке студент что-то выбирал или уже получал ссылку: прошлый выбор не ответ на новый вопрос.

block — номер блока (целое), "full" если студент хочет оплатить весь курс сразу, null если не назван и не следует из разговора (тогда возьмём следующий неоплаченный блок).

missing — чего не хватает, чтобы дать ссылку: "what" (непонятно что), "block" (непонятно какой блок), "both", или null.

confidence — насколько ты уверен (0..1). Сомневаешься — ставь меньше 0.7.
PROMPT;
    }
}
