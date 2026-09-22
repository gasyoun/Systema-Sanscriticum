<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SurveyEvent;
use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;
use App\Models\User;
use App\Services\Prana\PranaService;
use App\Services\Prana\PranaSettings;
use App\Services\Survey\SurveyFunnelRecorder;
use App\Support\FormulaGuard;
use App\Support\RoleGate;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Публичные анкеты /anketa/{slug} (движок опросов, рулинг MG 24-08-2026 —
 * вариант Б: нативно вместо Яндекс Форм). Вопросы в config/surveys.php;
 * страницы самогейтятся флагом SURVEYS_ENABLED. POST троттлится и защищён
 * ханипотом; награда «прана 500 ₽» начисляется сразу при совпадении контакта
 * с учёткой, иначе строка ждёт куратора.
 *
 * H5098: воронка пишется в survey_events (opened/started/page/completed),
 * session_key — анонимная куки survey_funnel (UUID, без ПДн); куратор
 * смотрит только агрегаты (funnel()), строки — сырьё для диагностики.
 */
class SurveyPageController extends Controller
{
    public const SESSION_COOKIE = 'survey_funnel';

    public function show(Request $request, string $slug): Response
    {
        $definition = $this->definition($slug);

        $sessionKey = $this->sessionKey($request);
        if ($sessionKey === null) {
            $sessionKey = (string) Str::uuid();
        }
        app(SurveyFunnelRecorder::class)->opened($slug, $sessionKey, auth()->id());

        return response()->view('survey.form', [
            'slug' => $slug,
            'definition' => $definition,
            'done' => $request->boolean('done'),
            'auth_email' => auth()->user()?->email,
        ])->withCookie(Cookie::create(self::SESSION_COOKIE, $sessionKey, 60 * 24 * 365, '/', null, null, true, false, 'lax'));
    }

    /** Лёгкий приёмник телеметрии формы: started / page. Без ПДн, 204. */
    public function event(Request $request, string $slug): Response
    {
        $this->definition($slug);

        $validated = Validator::make($request->all(), [
            'event' => ['required', 'in:'.SurveyEvent::STARTED.','.SurveyEvent::PAGE],
            'page' => ['nullable', 'integer', 'min:1', 'max:30'],
        ])->validate();

        $sessionKey = $this->sessionKey($request) ?? (string) Str::uuid();
        $recorder = app(SurveyFunnelRecorder::class);

        $userId = auth()->id();
        if ($validated['event'] === SurveyEvent::STARTED) {
            $recorder->started($slug, $sessionKey, $userId);
        } else {
            $recorder->page($slug, $sessionKey, (int) ($validated['page'] ?? 1));
        }

        return response()->noContent()
            ->withCookie(Cookie::create(self::SESSION_COOKIE, $sessionKey, 60 * 24 * 365, '/', null, null, true, false, 'lax'));
    }

    public function store(Request $request, string $slug): RedirectResponse
    {
        $definition = $this->definition($slug);

        // Ханипот: боты заполняют скрытое поле. Отвечаем как успех — не учим.
        if (filled($request->input('website'))) {
            return redirect()->route('survey.show', ['slug' => $slug, 'done' => 1]);
        }

        $validated = Validator::make(
            $request->all(),
            $this->rulesFor($definition),
            [],
            $this->namesFor($definition),
        )->validate();

        $answers = [];
        foreach ($definition['questions'] as $question) {
            $id = $question['id'];
            $value = match ($question['type']) {
                'checkboxes' => array_values(array_map('strval', (array) $request->input($id, []))),
                'scale' => (int) $request->input($id, 0),
                default => trim((string) $request->input($id, '')),
            };
            if (($question['numeric'] ?? false) && $value !== '' && $value !== []) {
                $value = (int) $value;
            }
            if ($value !== [] && $value !== '') {
                $answers[$id] = $value;
            }
        }

        $rewardChoice = $definition['reward_enabled'] ? (string) $request->input('reward_choice') : null;
        $contact = ($definition['reward_enabled'] && $rewardChoice !== 'none')
            ? (filled(trim((string) $request->input('contact'))) ? trim((string) $request->input('contact')) : null)
            : null;

        $response = SurveyResponse::create([
            'survey_slug' => $slug,
            'user_id' => auth()->id(),
            'answers' => $answers,
            'contact' => $contact,
            'reward_choice' => $definition['reward_enabled'] ? $rewardChoice : null,
            'ip_hash' => hash('sha256', (string) $request->ip().'|'.(string) config('app.key')),
        ]);

        $this->tryAutoReward($response);

        app(SurveyFunnelRecorder::class)->completed(
            $slug,
            $response,
            $this->sessionKey($request),
        );

        return redirect()->route('survey.show', ['slug' => $slug, 'done' => 1]);
    }

    /**
     * Агрегат воронки для куратора (админ/менеджер). Только количества —
     * ни контактов, ни текстов ответов, ни user_id в выдаче (H5098).
     */
    public function funnel(string $slug): Response
    {
        abort_unless(RoleGate::any(Roles::ADMIN, Roles::MANAGER), 403);

        $definition = config("surveys.definitions.$slug");
        abort_if(! is_array($definition), 404);

        $events = SurveyEvent::query()->where('survey_slug', $slug);

        $invitationCounts = SurveyInvitation::query()
            ->where('survey_slug', $slug)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Уникальные сессии на шаг воронки (sent — на приглашение, completed — на ответ).
        $uniqueByEvent = (clone $events)
            ->whereIn('event', [SurveyEvent::OPENED, SurveyEvent::STARTED])
            ->selectRaw('event, count(distinct session_key) as total')
            ->groupBy('event')
            ->pluck('total', 'event');
        $counts = [
            'sent' => (clone $events)->where('event', SurveyEvent::SENT)->count(),
            'opened' => (int) ($uniqueByEvent[SurveyEvent::OPENED] ?? 0),
            'started' => (int) ($uniqueByEvent[SurveyEvent::STARTED] ?? 0),
            'completed' => (clone $events)->where('event', SurveyEvent::COMPLETED)->count(),
        ];

        // Доходили ли до страниц многостраничной анкеты: уникальные сессии на страницу.
        $pageCounts = (clone $events)
            ->where('event', SurveyEvent::PAGE)
            ->selectRaw('page_index, count(distinct session_key) as total')
            ->groupBy('page_index')
            ->orderBy('page_index')
            ->pluck('total', 'page_index');

        // Дневная динамика за 14 дней: сколько событий каждого типа.
        $daily = (clone $events)
            ->where('created_at', '>=', now()->subDays(14)->startOfDay())
            ->selectRaw('date(created_at) as day, event, count(distinct coalesce(session_key, cast(survey_response_id as text), cast(survey_invitation_id as text), id)) as total')
            ->groupBy('day', 'event')
            ->orderBy('day')
            ->get()
            ->groupBy('day')
            ->map(fn ($group) => $group->pluck('total', 'event'));

        return response()->view('survey.funnel', [
            'slug' => $slug,
            'definition' => $definition,
            'invitationCounts' => $invitationCounts,
            'counts' => $counts,
            'pageCounts' => $pageCounts,
            'daily' => $daily,
        ]);
    }

    /** Анонимный ключ сессии воронки из куки (без ПДн) или null. */
    private function sessionKey(Request $request): ?string
    {
        $key = (string) $request->cookie(self::SESSION_COOKIE, '');

        return preg_match('/^[a-f0-9\-]{16,64}$/', $key) ? $key : null;
    }

    /** CSV-выгрузка для куратора: колонки — вопросы из конфига, BOM для Excel. */
    public function exportCsv(string $slug): Response
    {
        abort_unless(RoleGate::any(Roles::ADMIN, Roles::MANAGER), 403);

        $definition = config("surveys.definitions.$slug");
        abort_if(! is_array($definition), 404);

        $labels = [];
        foreach ($definition['questions'] as $question) {
            $labels[$question['id']] = $question['label'];
        }
        if ($definition['reward_enabled']) {
            $labels['reward_choice'] = 'Награда';
            $labels['contact_export'] = 'Контакт';
            $labels['reward_status'] = 'Награда начислена';
        }

        $rows = SurveyResponse::query()
            ->where('survey_slug', $slug)
            ->orderBy('created_at')
            ->get();

        return response()->streamDownload(function () use ($labels, $rows) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_merge(['ID', 'Дата'], array_values($labels)), ';');

            foreach ($rows as $row) {
                $line = [$row->id, $row->created_at?->format('d.m.Y H:i')];
                foreach (array_keys($labels) as $key) {
                    $value = match ($key) {
                        'reward_choice' => match ($row->reward_choice) {
                            'prana' => 'прана 500 ₽',
                            'intro' => 'бесплатное вводное',
                            'none' => 'без награды',
                            default => '',
                        },
                        'contact_export' => (string) $row->contact,
                        'reward_status' => $row->reward_sent_at ? 'да' : ($row->reward_choice !== null && $row->reward_choice !== 'none' ? 'ЖДЁТ' : ''),
                        default => isset($row->answers[$key])
                            ? implode('; ', array_map('strval', (array) $row->answers[$key]))
                            : '',
                    };
                    $line[] = $value;
                }
                // H5086: ответы анкеты — гостевые строки, нейтрализуем формулы.
                fputcsv($out, FormulaGuard::row($line), ';');
            }

            fclose($out);
        }, 'survey-'.$slug.'-'.now()->format('Ymd-Hi').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function definition(string $slug): array
    {
        abort_unless((bool) config('surveys.enabled'), 404);

        $definition = config("surveys.definitions.$slug");
        abort_if(! is_array($definition), 404);

        return $definition;
    }

    /** Правила валидации: обязательность из конфига + жёсткие длины/типы. */
    private function rulesFor(array $definition): array
    {
        $rules = ['website' => ['prohibited']];

        foreach ($definition['questions'] as $question) {
            $rules[$question['id']] = match ($question['type']) {
                'checkboxes' => array_merge(
                    (($question['required'] ?? false) ? ['required', 'array'] : ['nullable', 'array']),
                    ['max:20'],
                ),
                'scale' => array_merge(
                    (($question['required'] ?? false) ? ['required'] : ['nullable']),
                    ['integer', 'min:'.($question['min'] ?? 1), 'max:'.($question['max'] ?? 5)],
                ),
                'text' => array_merge(
                    (($question['required'] ?? false) ? ['required'] : ['nullable']),
                    ($question['numeric'] ?? false)
                        ? ['integer', 'min:1900', 'max:'.((int) date('Y'))]
                        : ['string', 'max:200'],
                ),
                'textarea' => array_merge(
                    (($question['required'] ?? false) ? ['required'] : ['nullable']),
                    ['string', 'max:2000'],
                ),
                default => array_merge(
                    (($question['required'] ?? false) ? ['required'] : ['nullable']),
                    ['string', 'max:300', $this->optionOf($question)],
                ),
            };
        }

        if ($definition['reward_enabled']) {
            $rules['reward_choice'] = ['required', 'in:prana,intro,none'];
            $rules['contact'] = [
                'nullable',
                'required_unless:reward_choice,none',
                'string',
                'max:200',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $value = trim((string) $value);
                    if ($value !== '' && ! str_contains($value, '@')) {
                        $fail('Укажите email или @telegram.');
                    }
                },
            ];
        }

        return $rules;
    }

    /** Радио/строка обязаны совпадать с одним из вариантов конфига — ничего не досочиняем. */
    private function optionOf(array $question): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($question) {
            if (filled($value) && ! in_array((string) $value, $question['options'] ?? [], true)) {
                $fail('Выберите вариант из списка.');
            }
        };
    }

    /** Человекочитаемые имена полей для сообщений об ошибках. */
    private function namesFor(array $definition): array
    {
        $names = [];
        foreach ($definition['questions'] as $question) {
            $names[$question['id']] = $question['label'];
        }
        $names['reward_choice'] = 'Награда';
        $names['contact'] = 'Контакт для награды';

        return $names;
    }

    private function tryAutoReward(SurveyResponse $response): void
    {
        if ($response->reward_choice !== 'prana' || blank($response->contact)) {
            return;
        }

        $user = User::query()->where('email', mb_strtolower($response->contact))->first();
        if (! $user instanceof User) {
            return;
        }

        // Одна награда на человека за опрос: повторная отправка той же анкеты
        // не начисляет прану второй раз. DB-идемпотентность prana_transactions
        // (user_id, reason, source_type, source_id) здесь не работает — вызов
        // шёл без morph-source, а NULL-столбцы MySQL unique-индексом не
        // ограничиваются (H3546).
        $alreadyRewarded = SurveyResponse::query()
            ->where('survey_slug', $response->survey_slug)
            ->where('reward_user_id', $user->id)
            ->whereNotNull('reward_sent_at')
            ->exists();
        if ($alreadyRewarded) {
            return;
        }

        $rubles = max(1, (int) config('surveys.reward_prana_rubles', 500));
        $amount = max(1, (int) round($rubles * PranaSettings::rate()));

        if (app(PranaService::class)->award($user, 'survey_reward', source: $response, amount: $amount, meta: [
            'survey' => $response->survey_slug,
        ])) {
            $response->forceFill(['reward_user_id' => $user->id, 'reward_sent_at' => now()])->save();
        }
    }
}
