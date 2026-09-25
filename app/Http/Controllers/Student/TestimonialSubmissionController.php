<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Jobs\NotifyTestimonialSubmittedJob;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Студент сам пишет отзыв: GET/POST /dvaram/otzyv (флаг features.student_testimonials).
 *
 * Отзыв создаётся СКРЫТЫМ (is_visible=false — у колонки DEFAULT true, поэтому
 * явно) и в статусе pending; в пул (вход, /otzyvy) его переводит модератор
 * кнопкой «Одобрить». Галочка согласия на публикацию обязательна и
 * сохраняется моментом — сама таблица отзывов согласием не является.
 * Один ожидающий отзыв на студента: пока первый не разобран, второй не принимаем.
 */
class TestimonialSubmissionController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        abort_unless(config('features.student_testimonials'), 404);

        /** @var User $user */
        $user = $request->user();
        if ($user->is_admin) {
            return redirect('/admin');
        }

        return view('student.testimonial-form', [
            'pending' => $this->pendingOf($user),
            'defaultName' => (string) $user->name,
            'defaultCity' => (string) ($user->city ?? ''),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(config('features.student_testimonials'), 404);

        /** @var User $user */
        $user = $request->user();
        if ($user->is_admin) {
            return redirect('/admin');
        }

        if ($this->pendingOf($user) !== null) {
            return redirect()->route('student.testimonial.create');
        }

        $data = $request->validate([
            'author_name' => ['required', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:80'],
            'body' => ['required', 'string', 'min:30', 'max:2000'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'consent' => ['accepted'],
        ], [
            'author_name.required' => 'Напишите, как вас подписать.',
            'body.required' => 'Напишите сам отзыв.',
            'body.min' => 'Отзыв слишком короткий — хотя бы пара предложений (от 30 знаков).',
            'body.max' => 'Отзыв длиннее 2000 знаков — сократите, пожалуйста.',
            'consent.accepted' => 'Без согласия на публикацию мы не сможем показать отзыв на сайте.',
        ]);

        $now = now();
        $testimonial = new Testimonial;
        $testimonial->forceFill([
            'user_id' => $user->id,
            'author_name' => trim(strip_tags($data['author_name'])),
            'city' => filled($data['city'] ?? null) ? trim(strip_tags($data['city'])) : null,
            'body' => trim(strip_tags($data['body'])),
            'rating' => $data['rating'] ?? null,
            'is_visible' => false,
            'is_featured' => false,
            'show_on_login' => false,
            'moderation_status' => Testimonial::STATUS_PENDING,
            'publish_consent_at' => $now,
            'submitted_at' => $now,
        ])->save();

        // Уведомление — побочный эффект: его сбой не должен терять отзыв
        // (на драйвере sync джоба исполняется прямо здесь).
        try {
            NotifyTestimonialSubmittedJob::dispatch($testimonial->id);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Отзыв студента сохранён, но уведомление админам не ушло', [
                'testimonial_id' => $testimonial->id,
            ]);
        }

        return redirect()->route('student.testimonial.create')
            ->with('status', 'Спасибо! Мы опубликуем отзыв после проверки.');
    }

    private function pendingOf(User $user): ?Testimonial
    {
        return Testimonial::query()
            ->where('user_id', $user->id)
            ->pending()
            ->latest('id')
            ->first();
    }
}
