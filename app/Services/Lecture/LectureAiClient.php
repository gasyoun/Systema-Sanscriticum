<?php

declare(strict_types=1);

namespace App\Services\Lecture;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HTTP-клиент AI-эндпоинтов микросервиса lecture-builder.
 *
 * Контракт тот же, что и у LectureBuilderClient: filesystem handoff,
 * сервис читает/пишет в working_dir.
 *
 * Каждая задача:
 *   - принимает working_dir + hint (опц. уточнение от редактора)
 *   - возвращает summary, usage, опц. backup (если apply=true)
 *   - apply=false → preview без записи (data.json не меняется)
 */
class LectureAiClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $token,
        private readonly int $timeout,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: rtrim((string) config('services.lecture_builder.url'), '/'),
            token:   config('services.lecture_builder.token'),
            timeout: (int) config('services.lecture_builder.ai_timeout', 600),
        );
    }

    public function structure(string $absoluteWorkingDir, string $hint = '', bool $apply = true): array
    {
        return $this->postJson('/ai/structure', [
            'working_dir' => $absoluteWorkingDir,
            'hint'        => $hint,
            'apply'       => $apply,
        ]);
    }

    public function correct(string $absoluteWorkingDir, string $hint = '', bool $apply = true,
                            int $maxParagraphs = 0): array
    {
        return $this->postJson('/ai/correct', [
            'working_dir'    => $absoluteWorkingDir,
            'hint'           => $hint,
            'apply'          => $apply,
            'max_paragraphs' => $maxParagraphs,
        ]);
    }

    public function placeSlides(string $absoluteWorkingDir, string $hint = '', bool $apply = true): array
    {
        return $this->postJson('/ai/place_slides', [
            'working_dir' => $absoluteWorkingDir,
            'hint'        => $hint,
            'apply'       => $apply,
        ]);
    }

    public function verifyTimecodes(string $absoluteWorkingDir, string $hint = '', bool $apply = true,
                                    ?string $ytUrl = null): array
    {
        return $this->postJson('/ai/timecodes', [
            'working_dir' => $absoluteWorkingDir,
            'hint'        => $hint,
            'apply'       => $apply,
            'yt_url'      => $ytUrl,
        ]);
    }

    private function postJson(string $path, array $payload): array
    {
        $response = $this->request()->post($this->baseUrl . $path, $payload);
        $body = $response->json();

        if (!is_array($body)) {
            throw new RuntimeException("lecture-builder вернул не-JSON ответ (HTTP {$response->status()})");
        }

        if (!$response->successful() || !($body['ok'] ?? false)) {
            $error = $body['error'] ?? ('HTTP ' . $response->status());
            throw new RuntimeException("AI {$path}: {$error}");
        }

        return $body;
    }

    private function request(): PendingRequest
    {
        $req = Http::timeout($this->timeout)->acceptJson()->asJson();
        if ($this->token) {
            $req = $req->withHeaders(['X-Builder-Token' => $this->token]);
        }
        return $req;
    }
}
