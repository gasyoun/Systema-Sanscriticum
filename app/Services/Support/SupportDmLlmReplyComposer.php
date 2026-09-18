<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Services\Bot\CuratorAi;
use App\Services\Support\Faq\KnowledgeContext;

/**
 * H4404 (рулинг MG 08-09-2026 «LLM-черновики»): формулировка ответа студенту
 * внешним LLM по живому FAQ-контексту — БЕЗ классификатора категорий.
 *
 * Почему без классификатора: в пробе H3380 категорийный конвейер не выстрелил
 * ни разу (category=null на 8 из 8 живых dm_hinted), значит любая ветка,
 * стоящая за категорией, мертва в реальном трафике. Здесь вопрос студента
 * идёт в retrieval напрямую; категория в решении «отвечать/молчать» не
 * участвует вовсе.
 *
 * Формулировка — единственная работа LLM. ФАКТЫ ответа берутся только из
 * FAQ-цитаты (как в {@see SupportDmAutoReply::faqDraft()}, рулинг R3):
 * промпт запрещает цифры, ссылки и сроки, которых нет в тексте. Модель не
 * получает ни суммы, ни доступа, ни каких-либо данных LMS — потому R3-запреты
 * денег/доступов здесь держит КОД, а не конфиг: вопрос про деньги гонится в
 * отказ до всякого вызова LLM.
 *
 * H5065 (что изменилось): на вход идёт {@see KnowledgeContext} — ПОЛНЫЕ
 * разделы общей базы знаний, а не массив 280-символьных сниппетов, как было.
 * На золотом наборе нужный раздел в среднем длиннее сниппета в 3–5 раз, то
 * есть прежний промпт физически не мог содержать ответ целиком: модель
 * договаривала пропущенное, а это ровно тот класс ошибки, ради которого
 * написан весь R3. Прямая выгода второго изменения — заголовочный путь
 * («Политика и поддержка → Сертификат») остаётся в контексте, а сниппет его
 * срезал.
 */
class SupportDmLlmReplyComposer
{
    /**
     * Версия промпта. Меняется при правке формулировок — пишется в аудит.
     * h5065-v1: контекст перешёл со сниппетов на полные разделы.
     */
    public const PROMPT_VERSION = 'h5065-v1';

    public function __construct(
        private readonly CuratorAi $ai,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('features.support_dm_llm_drafts', false);
    }

    /**
     * Сформулировать ответ студенту. null — LLM недоступен/отказался/не дал
     * текста; вызывающий обязан уйти в fallback (ack), ничего не отправляя.
     *
     * @return array{draft: string, model: ?string, usage: ?array{prompt_tokens: int, completion_tokens: int}, chunk_ids: list<string>}|null
     */
    public function compose(string $questionText, KnowledgeContext $context): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $contextBlock = $this->contextBlock($context);
        if ($contextBlock === null) {
            return null;
        }

        $result = $this->formulate([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->userPrompt($questionText, $contextBlock)],
        ]);

        $draft = $result['content'];
        if ($draft === null) {
            return null;
        }

        return [
            'draft' => $draft,
            'model' => $result['model'],
            'usage' => $result['usage'],
            'chunk_ids' => $context->chunkIds(),
        ];
    }

    /**
     * H5065: чья модель формулирует.
     *
     * `features.support_dm_llm_drafts_local=true` → локальный узел
     * (`CuratorAi::localChatWithUsage`, Ollama /v1/chat/completions): вопрос
     * студента и текст справки не покидают школу, стоимость ответа — ноль, а
     * рейт-лимиты внешнего провайдера перестают быть потолком для полосы
     * ответов. Ровно то, ради чего в H3234 заводился локальный режим.
     *
     * Контракт деградации тот же, что у H3234: узел недоступен → `content=null`
     * → compose() возвращает null → полоса уходит в шаблон/ack/подсказку
     * куратору. Внешний провайдер в локальном режиме НЕ вызывается никогда —
     * иначе «локальный» режим был бы просто вторым шансом для того же
     * внешнего вызова, а приватность не была бы свойством, а обещанием.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{content: ?string, usage: ?array{prompt_tokens: int, completion_tokens: int}, model: ?string}
     */
    private function formulate(array $messages): array
    {
        if ((bool) config('features.support_dm_llm_drafts_local', false)) {
            return $this->ai->localChatWithUsage($messages);
        }

        return $this->ai->chatWithUsage($messages);
    }

    /**
     * Блок справки для промпта: ПОЛНЫЕ разделы, обрезанные по границе раздела
     * (support.faq_rag.answer_max_chars). null — контекст пуст, формулировать
     * не из чего.
     */
    private function contextBlock(KnowledgeContext $context): ?string
    {
        if ($context->isEmpty()) {
            return null;
        }

        $block = trim($context->promptBlockCapped((int) config('support.faq_rag.answer_max_chars', 12000)));

        return $block === '' ? null : $block;
    }

    private function systemPrompt(): string
    {
        return 'Ты — помощник куратора Института исследования санскрита. Студент написал в личную поддержку. '
            .'Составь вежливый ОТВЕТ студенту на русском, опираясь СТРОГО на приведённые '
            .'фрагменты справки. НЕ выдумывай цифры, ссылки, сроки и правила, которых нет '
            .'во фрагментах. Если точного ответа во фрагментах нет — вежливо скажи, что '
            .'вопрос передан куратору, и он ответит в течение рабочего дня. '
            // Домашний регистр (H1876, revenue-copy voice) — тот же голос, что и
            // в шаблонных канреплая и SupportLlmDraftComposer.
            .'Регистр ответа: обращение на «вы» со строчной буквы; тон спокойный, взрослый, конкретный. '
            .'Без эмодзи, без восклицательных знаков, без нагнетания срочности, без уменьшительных '
            .'и без англицизмов там, где есть русское слово. '
            .'Букву «ё» не используй; исключение — «всё», когда без неё текст читался бы как «все». '
            .'Верни только текст ответа, без пояснений.';
    }

    /**
     * Промпт строится из ПОЛНЫХ разделов справки (см. contextBlock). Заголовок
     * «Разделы справки», а не «Фрагменты»: это разделы целиком, и модель не
     * должна достраивать обрезанный текст.
     */
    private function userPrompt(string $questionText, string $contextBlock): string
    {
        return "Разделы справки:\n".$contextBlock
            ."\n\nВопрос студента:\n".$questionText
            ."\n\nСоставь ответ.";
    }
}
