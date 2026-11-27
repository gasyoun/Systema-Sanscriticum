<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Consent;
use App\Models\User;
use App\Services\Consent\ConsentRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Согласие на участие в тесте ИИ-помощника занятия («Спроси Сократа»).
 *
 * Три шага, и ни один нельзя проскочить:
 *   GET  signed — страница с ТЕКСТОМ согласия (никаких действий по факту открытия:
 *                 антивирус и предпросмотр ссылок открывают её сами);
 *   POST signed — единственное место, где ставится users.lesson_ai_tester
 *                 и пишется строка в журнал consents (152-ФЗ, ст. 9);
 *   GET  signed — страница результата, чтобы F5 не отправлял форму повторно.
 *
 * Ссылки подписанные (signed, TTL из config/lesson_ai.php) и без авторизации:
 * письмо может открыть человек, вышедший из кабинета. Подпись привязывает
 * ссылку к users.id, поэтому поменять адресата в URL нельзя.
 */
class LessonAiConsentController extends Controller
{
    public function show(Request $request, User $user): View
    {
        return view('lesson-ai.consent', [
            'user' => $user,
            'confirmUrl' => $request->fullUrl(),
            'declineUrl' => URL::temporarySignedRoute(
                'lesson-ai.consent',
                now()->addDays($this->ttlDays()),
                ['user' => $user->id, 'intent' => 'no'],
            ),
            'alreadyTester' => (bool) $user->lesson_ai_tester,
            'intent' => $request->string('intent')->toString() === 'no' ? 'no' : 'yes',
            'invite' => (array) config('lesson_ai.invite'),
            'consentVersion' => (string) config('consent.documents.ai_test.version'),
        ]);
    }

    public function store(Request $request, User $user, ConsentRecorder $consents): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['yes', 'no'])],
        ]);

        if ($data['decision'] === 'yes' && ! $request->boolean('confirm')) {
            return back()->withErrors([
                'confirm' => 'Отметьте, что вы прочитали текст согласия, — без этого согласие не считается данным.',
            ]);
        }

        if ($data['decision'] === 'yes') {
            $user->forceFill(['lesson_ai_tester' => true])->save();
            $consents->given(Consent::TYPE_AI_TEST, 'lesson-ai-invite:email', $request, $user);
        } else {
            // Отказ тоже пишем: он должен быть так же доказуем, как согласие,
            // и не должен снимать уже выданный доступ (если человек был тестировщиком).
            $consents->withdrawn(Consent::TYPE_AI_TEST, 'lesson-ai-invite:email', $request, $user);
        }

        return redirect()->to(URL::temporarySignedRoute(
            'lesson-ai.consent.done',
            now()->addDays(1),
            ['user' => $user->id, 'decision' => $data['decision']],
        ));
    }

    public function done(Request $request, User $user): View
    {
        return view('lesson-ai.consent-done', [
            'user' => $user,
            'decision' => $request->string('decision')->toString() === 'no' ? 'no' : 'yes',
            'invite' => (array) config('lesson_ai.invite'),
        ]);
    }

    private function ttlDays(): int
    {
        return max(1, (int) config('lesson_ai.invite.consent_link_ttl_days', 30));
    }
}
