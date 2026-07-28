<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Разбор JSON-расшифровки (формат Deepgram) в массив предложений с таймкодами.
 *
 * Источник — файл на диске `public` (тот же формат, что у Lesson::$transcript_file).
 * Берём именно слова (results.channels[0].alternatives[0].words[]) — они на 100% полные —
 * и склеиваем в предложения, закрывая по .!? и сохраняя «хвост» без завершающего знака.
 *
 * Используется и в плеере урока (StudentController), и в блоке лендинга
 * «Стенограмма вебинара» (promo.blocks.webinar_transcript_block).
 */
final class TranscriptParser
{
    /**
     * @return list<array{formatted_time:string,start:float,end:float,text:string,safe_text:string}>
     */
    public static function sentencesFromPublicFile(?string $path): array
    {
        if (empty($path) || ! Storage::disk('public')->exists($path)) {
            return [];
        }

        // Ключ кэша включает mtime: при перезаливке файла кэш инвалидируется сам.
        $mtime = Storage::disk('public')->lastModified($path);
        $cacheKey = 'transcript_sentences:'.md5($path).':'.$mtime;

        return Cache::rememberForever($cacheKey, function () use ($path): array {
            $data = json_decode(Storage::disk('public')->get($path), true);
            $words = self::wordsFrom(is_array($data) ? $data : []);

            $sentences = [];
            $currentSentence = '';
            $sentenceStart = 0;
            $sentenceEnd = 0;

            foreach ($words as $wordData) {
                if (empty($currentSentence)) {
                    $sentenceStart = $wordData['start'] ?? 0;
                }

                $word = $wordData['punctuated_word'] ?? $wordData['word'] ?? '';
                $currentSentence .= $word.' ';
                $sentenceEnd = $wordData['end'] ?? $sentenceStart;

                // Слово кончается точкой/вопросом/восклицанием — закрываем предложение.
                if (preg_match('/[.!?]$/', trim($word))) {
                    $sentences[] = self::makeSentence($sentenceStart, $sentenceEnd, $currentSentence);
                    $currentSentence = '';
                }
            }

            // «Хвост» лекции (даже если он без завершающего знака).
            if (! empty(trim($currentSentence))) {
                $sentences[] = self::makeSentence($sentenceStart, $sentenceEnd, $currentSentence);
            }

            return $sentences;
        });
    }

    /**
     * Достаём массив слов Deepgram из разных обёрток корня:
     *  - чистый ответ Deepgram:            { results: { channels: [...] } }
     *  - экспорт n8n (массив item-ов):     [ { json: { results: {...} } } ] или [ { results: {...} } ]
     *
     * Публичный: приёмная ручка транскрипта (LessonController::storeTranscript)
     * проверяет этим же разбором, есть ли в теле хоть одно слово, — чтобы не
     * записать уроку пустой транскрипт и не выдать его за готовый к нарезке.
     *
     * @param  array<mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    public static function wordsFrom(array $data): array
    {
        // n8n заворачивает каждый item в массив, полезная нагрузка часто под ключом "json".
        $root = $data;
        if (! isset($root['results']) && isset($root[0]) && is_array($root[0])) {
            $root = $root[0]['json'] ?? $root[0];
        }

        return $root['results']['channels'][0]['alternatives'][0]['words'] ?? [];
    }

    /**
     * @return array{formatted_time:string,start:float,end:float,text:string,safe_text:string}
     */
    private static function makeSentence(float|int $start, float|int $end, string $text): array
    {
        $seconds = (int) $start;
        $formattedTime = $seconds >= 3600 ? gmdate('H:i:s', $seconds) : gmdate('i:s', $seconds);

        return [
            'formatted_time' => $formattedTime,
            'start' => (float) $start,
            'end' => (float) $end,
            'text' => trim($text),
            'safe_text' => mb_strtolower(htmlspecialchars(trim($text), ENT_QUOTES)),
        ];
    }
}
