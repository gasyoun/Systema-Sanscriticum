<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\DetectsFormBots;
use App\Mail\MiniCourseMagicLinkMail;
use App\Models\Course;
use App\Models\MagicLinkToken;
use App\Models\User;
use App\Rules\HouseEmail;
use App\Services\AttributionService;
use App\Services\Consent\ConsentRecorder;
use App\Services\MiniCourseQuickEnrollment;
use App\Services\ReferralService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Короткая запись на бесплатный мини-курс для рекламного трафика из VK-бота:
 * имя + email + согласие → кабинет создан, мини-курс оформлен, человек сразу в
 * кабинете. Раньше бот вёл на витрину, и люди уходили в попап рассылки вместо
 * записи. Под флагом features.mini_course_quick_enroll (default OFF → 404).
 *
 * Существующий email НЕ логиним (иначе любой войдёт в чужой аккаунт, зная
 * адрес): шлём одноразовую ссылку, переход по ней = вход + запись.
 */
class MiniCourseQuickEnrollController extends Controller
{
    use DetectsFormBots;

    public const MAGIC_PURPOSE = 'mini_course_enroll';

    public function __construct(private readonly MiniCourseQuickEnrollment $enrollment) {}

    public function show(): View|RedirectResponse
    {
        $course = $this->courseOrAbort();

        $user = auth()->user();
        if ($user !== null && $this->enrollment->isEnrolled($user, $course)) {
            return redirect()->route('student.course', $course->slug);
        }

        return view('mini-course.quick-enroll', ['course' => $course]);
    }

    public function store(Request $request): View|RedirectResponse
    {
        $course = $this->courseOrAbort();

        if (auth()->check()) {
            return $this->enrollAndEnter(auth()->user(), $course);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'email' => ['required', 'string', 'max:255', new HouseEmail],
            'pd_consent' => ['accepted'],
        ], [
            'name.required' => 'Как к вам обращаться?',
            'pd_consent.accepted' => 'Нужно согласие на обработку персональных данных.',
        ]);

        $email = User::normalizeEmail($validated['email']);

        // Бот получает тот же экран «проверьте почту», но без аккаунта и письма.
        if ($this->looksLikeBot($request, $email)) {
            return view('mini-course.quick-enroll-check-email', ['course' => $course]);
        }

        $existing = User::where('email', $email)->first();
        if ($existing !== null) {
            $this->sendMagicLink($existing, $course);

            return view('mini-course.quick-enroll-check-email', ['course' => $course]);
        }

        $name = trim($validated['name']);
        $user = User::create([
            'email' => $email,
            'name' => $name,
            'greeting_name' => mb_substr($name, 0, 60),
            'password' => Hash::make(Str::random(12)),
        ]);
        app(ConsentRecorder::class)->fromForm($request, 'mini_course_quick', $user);
        app(ReferralService::class)->attachReferrer($user, $request->input('ref') ?: session('ref'));
        app(AttributionService::class)->applyToNewUser($user);

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return $this->enrollAndEnter($user, $course);
    }

    /** Одноразовая ссылка для уже существующего аккаунта: вход + запись. */
    public function magic(string $token): RedirectResponse
    {
        $course = $this->courseOrAbort();

        $link = MagicLinkToken::findActive($token, self::MAGIC_PURPOSE);
        abort_if($link === null, 404);
        abort_unless($link->consume(), 404);

        Auth::login($link->user, remember: true);

        return $this->enrollAndEnter($link->user, $course);
    }

    private function enrollAndEnter(User $user, Course $course): RedirectResponse
    {
        $alreadyEnrolled = $this->enrollment->isEnrolled($user, $course);
        $this->enrollment->enroll($user, $course);

        return redirect()->route('student.course', $course->slug)
            ->with('success', $alreadyEnrolled
                ? 'Вы уже записаны на мини-курс — продолжайте с того места, где остановились.'
                : 'Вы записаны на бесплатный мини-курс! Первый урок уже открыт, следующие — раз в '.(int) config('mini_courses.drip_days', 3).' дня.')
            ->with('mini_course_enrolled', ! $alreadyEnrolled);
    }

    private function sendMagicLink(User $user, Course $course): void
    {
        $url = route('mini-course.quick.magic', MagicLinkToken::issueFor($user, self::MAGIC_PURPOSE));

        try {
            Mail::to($user->email)->send(new MiniCourseMagicLinkMail($user, $course->title, $url));
        } catch (\Throwable $e) {
            Log::warning('mini-course quick enroll: magic link mail failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function courseOrAbort(): Course
    {
        abort_unless(config('features.mini_course_quick_enroll'), 404);

        $course = $this->enrollment->course();
        abort_if($course === null, 404);

        return $course;
    }
}
