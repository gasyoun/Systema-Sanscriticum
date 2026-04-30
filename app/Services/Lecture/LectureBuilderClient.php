<?php

declare(strict_types=1);

namespace App\Services\Lecture;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HTTP-клиент микросервиса lecture-builder.
 *
 * Контракт: Laravel и сервис делят filesystem. Laravel передаёт абсолютный
 * working_dir, сервис читает/пишет туда. Большие файлы по сети не ходят.
 */
class LectureBuilderClient
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
            timeout: (int) config('services.lecture_builder.timeout', 180),
        );
    }

    public function isHealthy(): bool
    {
        try {
            $response = $this->request()->get($this->baseUrl . '/health');
            return $response->successful() && (bool) ($response->json('ok') ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array{course?: ?string, lecturer?: ?string, youtube?: ?string, rutube?: ?string,
     *               lesson_number?: ?int, lesson_title?: ?string, title?: ?string,
     *               organization?: ?string, period?: ?string, date_display?: ?string,
     *               host?: ?string, yt_offset?: ?int, rt_offset?: ?int, au_offset?: ?int}  $meta
     */
    public function preprocess(
        string $absoluteWorkingDir,
        string $rawTranscriptRel,
        ?string $rawPdfRel,
        int $lessonNumber,
        array $meta = [],
    ): array {
        $payload = array_filter([
            'working_dir'    => $absoluteWorkingDir,
            'raw_transcript' => $rawTranscriptRel,
            'raw_pdf'        => $rawPdfRel,
            'lesson_number'  => $lessonNumber,
            'meta'           => $meta ?: null,
        ], fn ($v) => $v !== null);

        return $this->postJson('/preprocess', $payload);
    }

    public function render(
        string $absoluteWorkingDir,
        string $dataJson = 'data.json',
        string $template = 'template.html.j2',
    ): array {
        return $this->postJson('/render', [
            'working_dir' => $absoluteWorkingDir,
            'data_json'   => $dataJson,
            'template'    => $template,
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
            $trace = $body['trace'] ?? null;
            throw new RuntimeException("lecture-builder {$path}: {$error}" . ($trace ? "\n\n{$trace}" : ''));
        }

        return $body;
    }

    private function request(): PendingRequest
    {
        $req = Http::timeout($this->timeout)
            ->acceptJson()
            ->asJson();

        if ($this->token) {
            $req = $req->withHeaders(['X-Builder-Token' => $this->token]);
        }

        return $req;
    }
}
