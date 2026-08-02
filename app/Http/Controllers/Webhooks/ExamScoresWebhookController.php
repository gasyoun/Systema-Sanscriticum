<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\ExamScore;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Приём баллов экзамена «Санка» из Google-таблицы преподавателя (через n8n).
 * Батч строк {email, course(slug), score_*}; матчинг студента по email
 * (регистронезависимо), курса — через Course::resolveBySlug. Несматченные
 * строки возвращаются в ответе с причиной — n8n может подсветить их в таблице.
 *
 * Ответ всегда 200 при валидном payload: бизнес-исходы (не найден студент) —
 * не повод для ретраев n8n. Идемпотентно: повтор того же листа → unchanged.
 */
final class ExamScoresWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $criteria = Certificate::EXAM_CRITERIA;

        $validated = $request->validate([
            'rows' => ['required', 'array', 'max:500'],
            'rows.*.email' => ['required', 'email'],
            'rows.*.course' => ['required', 'string', 'max:255'],
            'rows.*.score_clarity' => ['nullable', 'numeric', 'min:0', 'max:'.$criteria['score_clarity']['max']],
            'rows.*.score_letters' => ['nullable', 'numeric', 'min:0', 'max:'.$criteria['score_letters']['max']],
            'rows.*.score_flow' => ['nullable', 'numeric', 'min:0', 'max:'.$criteria['score_flow']['max']],
            'rows.*.row_ref' => ['nullable', 'string', 'max:64'],
        ]);

        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $unmatched = [];

        foreach ($validated['rows'] as $row) {
            $reject = function (string $reason) use (&$unmatched, $row): void {
                $unmatched[] = [
                    'email' => $row['email'],
                    'course' => $row['course'],
                    'row_ref' => $row['row_ref'] ?? null,
                    'reason' => $reason,
                ];
            };

            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($row['email']))])
                ->first();
            if (! $user) {
                $reject('user_not_found');

                continue;
            }

            $course = Course::resolveBySlug(trim($row['course']));
            if (! $course) {
                $reject('course_not_found');

                continue;
            }

            $scores = [
                'score_clarity' => $row['score_clarity'] ?? null,
                'score_letters' => $row['score_letters'] ?? null,
                'score_flow' => $row['score_flow'] ?? null,
            ];
            if ($scores['score_clarity'] === null && $scores['score_letters'] === null && $scores['score_flow'] === null) {
                $reject('empty_scores');

                continue;
            }

            $score = ExamScore::updateOrCreate(
                ['user_id' => $user->id, 'course_id' => $course->id],
                $scores + [
                    'source_row' => $row['row_ref'] ?? null,
                    'imported_at' => now(),
                ],
            );

            if ($score->wasRecentlyCreated) {
                $created++;
            } elseif ($score->wasChanged(['score_clarity', 'score_letters', 'score_flow'])) {
                $updated++;
            } else {
                $unchanged++;
            }
        }

        if ($unmatched !== []) {
            Log::warning('exam-scores webhook: unmatched rows', ['unmatched' => $unmatched]);
        }

        return response()->json([
            'ok' => true,
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'unmatched' => $unmatched,
        ]);
    }
}
