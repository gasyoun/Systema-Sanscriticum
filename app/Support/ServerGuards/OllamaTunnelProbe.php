<?php

declare(strict_types=1);

namespace App\Support\ServerGuards;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * H4845 — живость reverse-туннеля к Ollama на GPU-узле (127.0.0.1:11434 на .92).
 *
 * Туннель внешний: `autossh -R 11434:localhost:11434` поднимается С GPU-УЗЛА,
 * на .92 от него виден только сокет sshd-session. Когда он умирает, приложение
 * лишь пишет WARN «HybridRetriever: dense-нога недоступна, деградация в BM25»
 * (cURL 7/28), теневая генерация копит status=error — и ни один сторож не
 * звенит: качество поиска деградирует молча. Замер 14-09-2026: NO-LISTENER на
 * 11434 при включённых KNOWLEDGE_EMBEDDING_DRIVER=ollama, FAQ_HYBRID_RETRIEVAL
 * и BOT_OLLAMA_SHADOW; WARN-строки в том числе в рабочие часы узла.
 *
 * Проверка звенит только когда туннель КОМУ-ТО нужен (флаг-потребитель
 * включён) и только в рабочие часы узла (knowledge.tunnel_hours): узел ночью
 * спит штатно, и ночная тревога каждые сутки приучила бы её не читать.
 *
 * Потребитель — cabinet:probe (soft-находка, sticky-fingerprint по классу
 * `ollama-tunnel`): мёртвый туннель = ровно одна soft-тревога до зелёного,
 * живой туннель её снимает. Владелец и перезапуск:
 * docs/ops/OLLAMA_TUNNEL_RUNBOOK.md.
 */
final class OllamaTunnelProbe
{
    public const PREFIX = 'ollama-tunnel';

    /**
     * Включённые потребители туннеля, человеческими словами.
     *
     * FAQ_HYBRID_RETRIEVAL сам по себе туннель не зовёт: без драйвера ollama
     * EmbeddingProvider = Null (AppServiceProvider), поэтому потребитель
     * эмбеддингов — именно драйвер.
     *
     * @return list<string>
     */
    public static function dependents(): array
    {
        $deps = [];
        if ((string) config('knowledge.driver') === 'ollama') {
            $deps[] = (bool) config('features.faq_hybrid_retrieval')
                ? 'dense-нога FAQ-поиска (иначе деградация в BM25)'
                : 'индексация knowledge:index';
        }
        if ((bool) config('features.bot_ollama_shadow')) {
            $deps[] = 'теневая генерация BOT_OLLAMA_SHADOW';
        }
        if ((bool) config('features.bot_local_generation')) {
            $deps[] = 'ответы бота BOT_LOCAL_GENERATION (без узла — только детерминированные)';
        }

        return $deps;
    }

    /**
     * Рабочее окно узла «HH:MM-HH:MM» в часовом поясе приложения. Пусто или
     * нечитаемо — окно круглосуточное (fail-closed: лучше лишняя тревога, чем
     * вечно молчащая проверка из-за опечатки).
     */
    public static function withinServiceWindow(?CarbonInterface $at = null): bool
    {
        $raw = trim((string) config('knowledge.tunnel_hours', ''));
        if (preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $raw, $m) !== 1) {
            return true;
        }

        $at ??= now();
        $minute = $at->hour * 60 + $at->minute;
        $from = (int) $m[1] * 60 + (int) $m[2];
        $to = (int) $m[3] * 60 + (int) $m[4];

        return $from <= $to
            ? $minute >= $from && $minute < $to
            : $minute >= $from || $minute < $to; // окно через полночь
    }

    /**
     * null — туннель жив, не нужен или сейчас вне окна; строка — находка.
     */
    public function failure(): ?string
    {
        $deps = self::dependents();
        if ($deps === [] || ! self::withinServiceWindow()) {
            return null;
        }

        $base = rtrim((string) config('knowledge.base_url', 'http://127.0.0.1:11434'), '/');
        $attempts = max(1, (int) config('cabinet_probe.ollama_tunnel_attempts', 2));
        $pause = max(0, (int) config('cabinet_probe.ollama_tunnel_pause_seconds', 2));
        $reason = '';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::timeout(max(1, (int) config('knowledge.timeout', 5)))->get($base.'/api/tags');
                if ($response->successful()) {
                    return null;
                }
                $reason = 'узел ответил HTTP '.$response->status().' — туннель жив, Ollama за ним нет';
            } catch (Throwable $e) {
                $reason = self::describe($e->getMessage());
            }

            if ($attempt < $attempts && $pause > 0) {
                sleep($pause);
            }
        }

        return self::PREFIX.': '.$base.' недоступен — '.$reason
            .'. Зависят: '.implode('; ', $deps)
            .'. Туннель поднимается с GPU-узла Ивана; перезапуск и владелец — docs/ops/OLLAMA_TUNNEL_RUNBOOK.md';
    }

    /**
     * Класс отказа без переменных частей (мс, адреса): текст уходит в TG, и
     * смена «отказ ↔ таймаут» не должна выглядеть новой аварией.
     */
    private static function describe(string $error): string
    {
        if (str_contains($error, 'cURL error 7')) {
            return 'нет слушателя на порту (reverse-туннель мёртв, cURL 7)';
        }
        if (str_contains($error, 'cURL error 28')) {
            return 'таймаут (туннель висит или узел не отвечает, cURL 28)';
        }
        if (preg_match('/cURL error (\d+)/', $error, $m) === 1) {
            return 'ошибка соединения (cURL '.$m[1].')';
        }

        return 'ошибка соединения';
    }
}
