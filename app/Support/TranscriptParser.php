<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Разбор JSON-расшифровки (формат Deepgram) в массив предложений с таймкодами.
 *
 * Источник — Lesson::$transcript_file. H3308: стенограммы платных уроков больше не
 * лежат на публичном диске (/storage статически раздаёт их без всякой авторизации),
 * поэтому читаем с диска `local`; для строк, ещё не перенесённых командой
 * lessons:privatize-gated-assets, остаётся fallback на `public`.
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
    public static function sentencesFromStoredFile(?string $path): array
    {
        // Абсолютный URL (например, опубликованная лекция) — не файл на диске.
        if (empty($path) || Str::startsWith($path, ['http://', 'https://', '/'])) {
            return [];
        }

        // H3308: приватный диск — основное хранилище; `public` — легаси до прогона
        // lessons:privatize-gated-assets на проде.
        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return self::parseFromDisk($disk, $path);
            }
        }

        return [];
    }

    /**
     * BC-псевдоним: имя говорило о диске `public`, чтение теперь disk-aware.
     *
     * @deprecated use sentencesFromStoredFile()
     *
     * @return list<array{formatted_time:string,start:float,end:float,text:string,safe_text:string}>
     */
    public static function sentencesFromPublicFile(?string $path): array
    {
        return self::sentencesFromStoredFile($path);
    }

    /**
     * @return list<array{formatted_time:string,start:float,end:float,text:string,safe_text:string}>
     */
    private static function parseFromDisk(string $disk, string $path): array
    {
        // Ключ кэша включает диск и mtime: при перезаливке файла кэш инвалидируется сам.
        $mtime = Storage::disk($disk)->lastModified($path);
        $cacheKey = 'transcript_sentences:'.$disk.':'.md5($path).':'.$mtime;

        return Cache::rememberForever($cacheKey, function () use ($disk, $path): array {
            $data = json_decode(Storage::disk($disk)->get($path), true);
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
