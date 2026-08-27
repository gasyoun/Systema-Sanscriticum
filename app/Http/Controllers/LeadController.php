<?php

namespace App\Http\Controllers;

use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadNotifier;
use App\Services\Leads\LeadFlashBuilder;
use App\Services\Messaging\DeliveryChannelManager;
use App\Services\Messaging\SocialChannelParser;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LeadController extends Controller
{
    public function store(Request $request, LeadFlashBuilder $flashBuilder)
    {
        // 0. Rate limit: 1 заявка / 5 сек / IP
        $rlKey = 'lead-submit:'.$request->ip();
        if (RateLimiter::tooManyAttempts($rlKey, 1)) {
            abort(429, 'Слишком частые запросы. Подождите несколько секунд.');
        }
        RateLimiter::hit($rlKey, 5);

        $validated = $request->validate([
            // name/email необязательны: упрощённая форма (один контакт, без имени)
            // шлёт только contact. Полная форма по-прежнему отправляет все три
            // (обязательность на ней держит HTML required).
            'name' => 'nullable|string|max:255',
            'contact' => 'required|string',
            'email' => 'nullable|email',
            'social' => 'nullable|string|max:255',
            'landing_page_id' => 'nullable|integer',
            'form_name' => 'nullable|string',
            'utm_source' => 'nullable|string',
            'utm_medium' => 'nullable|string',
            'utm_campaign' => 'nullable|string',
            'utm_content' => 'nullable|string',
            'utm_term' => 'nullable|string',
            'click_id' => 'nullable|string',
            'referrer' => 'nullable|string',
            'is_promo_agreed' => 'nullable',
            'source_article_id' => 'nullable|integer',
            'source_article_slug' => 'nullable|string|max:255',
        ]);

        // Берём только провалидированные поля — отсекаем mass-assignment мусор.
        $data = $validated;
        $data['is_promo_agreed'] = $request->has('is_promo_agreed');
        $data['ip_address'] = $request->ip();
        $data['user_agent'] = $request->userAgent();

        // Упрощённая форма шлёт один контакт. Если введён email — дублируем его в
        // поле email (для дедупа и писем); телефон/ник остаётся только в contact.
        if (empty($data['email']) && ! empty($data['contact']) && filter_var($data['contact'], FILTER_VALIDATE_EMAIL)) {
            $data['email'] = $data['contact'];
        }

        // H3576 §A — атрибуция: формы лендингов передают query-строку страницы в
        // action (см. promo/blocks/*.blade.php), поэтому UTM из ссылки поста
        // (utm_source/.../click_id) доезжает до POST. Здесь — авторитетный
        // добор: пустое поле из тела дополняем из query; клиентскому телу
        // приоритет не отдаём (значение может быть только ДОБАВЛЕНО, не подменено).
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'click_id'] as $utmKey) {
            if (empty($data[$utmKey]) && filled($request->query($utmKey))) {
                $data[$utmKey] = (string) $request->query($utmKey);
            }
        }

        if (! empty($data['form_name'])) {
            $existingUtm = $data['utm_content'] ?? '';
            $data['utm_content'] = '['.$data['form_name'].'] '.$existingUtm;
        }

        // Дедуп по email, если он есть; иначе (телефон-only лид) — по contact.
        // Без этого where('email', null) в Laravel превращается в IS NULL и ловит
        // любой другой безымянный лид того же лендинга как ложный дубль.
        [$dupColumn, $dupValue] = ! empty($data['email'])
            ? ['email', $data['email']]
            : ['contact', $data['contact']];

        $landing = null;
        if (! empty($data['landing_page_id'])) {
            $landing = LandingPage::find($data['landing_page_id']);
        }

        $existing = null;
        if (! empty($data['landing_page_id'])) {
            $existing = Lead::where($dupColumn, $dupValue)
                ->where('landing_page_id', $data['landing_page_id'])
                ->first();
        } elseif (! empty($data['source_article_slug'])) {
            $existing = Lead::where($dupColumn, $dupValue)
                ->where('source_article_slug', $data['source_article_slug'])
                ->first();
        }

        if ($existing) {
            // Подписка на статусы (H3339): заявившийся раньше выдачи токена
            // всё равно должен получить кнопки — досыпаем binding сейчас.
            if ($landing && $landing->hasStatusBlock() && ! $existing->magnet_token) {
                $this->attachBinding($existing, $landing, $this->channelFromSocial($validated['social'] ?? null, $landing));
            }

            return redirect()->route('thank.you')->with(
                $this->buildDuplicateFlash($existing)
            );
        }

        $lead = Lead::create($data);

        // Lead-magnet: если у лендинга включён магнит — привязываем токен и канал.
        // H3339: тот же generic-binding (поля лида magnet_*) служит и подпиской
        // на статусы курса — выдаём его любой заявке лендинга с status_block,
        // даже без файла (файл при этом не доставляется никогда).
        if ($landing && ($landing->hasLeadMagnet() || $landing->hasStatusBlock())) {
            $this->attachBinding($lead, $landing, $this->channelFromSocial($validated['social'] ?? null, $landing));
        }

        // Письмо со ссылкой на вебинар уходит НЕ здесь, а когда лид доходит до шага
        // бота: n8n зовёт /api/webhooks/lead-step (см. LeadStepMailer 'webinar_invite').

        // Уведомление маркетологам в Telegram (no-op, если чат не настроен).
        app(LeadNotifier::class)->newLead($lead);

        return redirect()->route('thank.you')
            ->with($flashBuilder->build($lead, $landing, $validated));
    }

    /**
     * Заявка одним кликом от вошедшего ученика (лист ожидания нового потока).
     * Поля не вводятся: имя/контакт/почта берутся из профиля; дедуп тот же,
     * что у полной формы (email ?? contact + landing_page_id), поэтому повторный
     * клик не плодит дублей — человек просто снова видит подтверждение.
     */
    public function oneClick(Request $request)
    {
        // Тот же троттлинг, что у lead-формы: 1 запрос / 5 сек / IP.
        $rlKey = 'lead-submit:'.$request->ip();
        if (RateLimiter::tooManyAttempts($rlKey, 1)) {
            abort(429, 'Слишком частые запросы. Подождите несколько секунд.');
        }
        RateLimiter::hit($rlKey, 5);

        $validated = $request->validate([
            'landing_page_id' => ['required', 'integer'],
            'is_promo_agreed' => ['nullable'],
        ]);

        $user = $request->user();

        // Контакты ТОЛЬКО из профиля — форма полей не показывает.
        // Если нет Телеграма, подходит ВК или Max (рулинг MG 22-08-2026).
        $email = filled($user->email) ? $user->email : null;
        $contact = collect([
            $user->phone,
            filled($user->telegram_username) ? '@'.$user->telegram_username : null,
            filled($user->vk_id) ? 'vk:'.$user->vk_id : null,
            filled($user->max_user_id) ? 'max:'.$user->max_user_id : null,
        ])->first(fn ($value) => filled($value)) ?? $email;

        if (blank($contact)) {
            return back()->with('error', 'В профиле нет ни телефона, ни мессенджера — напишите куратору напрямую.');
        }

        [$dupColumn, $dupValue] = $email !== null ? ['email', $email] : ['contact', $contact];

        $landing = LandingPage::find($validated['landing_page_id']);
        $subscriptionLanding = $landing && ($landing->hasStatusBlock() || $landing->hasLeadMagnet());

        $existing = Lead::where($dupColumn, $dupValue)
            ->where('landing_page_id', $validated['landing_page_id'])
            ->first();

        if ($existing) {
            // H3339: досыпаем binding заявке, сделанной до появления подписки.
            if ($subscriptionLanding && ! $existing->magnet_token) {
                $this->attachBinding($existing, $landing, $this->channelFromProfile($user, $landing));
            }

            return back()->with(array_merge(
                ['success' => 'Вы уже в листе ожидания — напишем вам при открытии набора.'],
                $this->statusFlash($existing, $landing)
            ));
        }

        $lead = Lead::create([
            'landing_page_id' => $validated['landing_page_id'],
            'name' => $user->name,
            'contact' => $contact,
            'email' => $email,
            'is_promo_agreed' => $request->has('is_promo_agreed'),
            // Метка канала в CRM: заявка создана кнопкой из кабинета, не формой.
            'utm_content' => '[one-click]',
            'utm_source' => $request->input('utm_source'),
            'utm_medium' => $request->input('utm_medium'),
            'utm_campaign' => $request->input('utm_campaign'),
            'utm_term' => $request->input('utm_term'),
            'click_id' => $request->input('click_id'),
            'referrer' => $request->headers->get('referer'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'user_id' => $user->id,
        ]);

        // H3339: binding-токен (кнопки «Подключить уведомления») — каждой
        // заявке лендинга с status_block, включая учеников кабинета.
        if ($subscriptionLanding) {
            $this->attachBinding($lead, $landing, $this->channelFromProfile($user, $landing));
        }

        // Согласие на анонсы от действующего ученика — сразу в его настройки.
        // Только additive: снять галочку он может в кабинете, здесь ничего не выключаем.
        if ($request->has('is_promo_agreed')) {
            $user->forceFill([
                'wants_email_announcements' => true,
                'wants_messenger_announcements' => true,
            ])->save();
        }

        // Уведомление маркетологам в Telegram (no-op, если чат не настроен).
        app(LeadNotifier::class)->newLead($lead);

        return back()->with(array_merge(
            ['success' => 'Готово! Вы в листе ожидания — напишем вам при открытии набора.'],
            $this->statusFlash($lead, $landing)
        ));
    }

    public function export()
    {
        abort_unless(auth()->check() && auth()->user()->is_admin, 403, 'Доступ к выгрузке запрещен.');

        $fileName = 'leads_full_'.date('Y-m-d_H-i').'.csv';

        $leads = Lead::with('landingPage')->latest()->get();

        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=$fileName",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $columns = [
            'ID', 'Дата', 'Лендинг', 'Имя', 'Телефон', 'Email', 'Соц. сеть', 'Рассылка',
            'UTM Source', 'UTM Medium', 'UTM Campaign', 'UTM Content (Форма)',
            'UTM Term', 'Click ID', 'IP Адрес', 'Referrer', 'User Agent',
        ];

        $callback = function () use ($leads, $columns) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, $columns, ';');

            foreach ($leads as $lead) {
                $row = [
                    $lead->id,
                    $lead->created_at->format('d.m.Y H:i'),
                    $lead->landingPage ? $lead->landingPage->title : 'Неизвестно',
                    $lead->name,
                    $lead->contact,
                    $lead->email ?? '',
                    $lead->social ?? '',
                    $lead->is_promo_agreed ? 'Да' : 'Нет',
                    $lead->utm_source ?? '',
                    $lead->utm_medium ?? '',
                    $lead->utm_campaign ?? '',
                    $lead->utm_content ?? '',
                    $lead->utm_term ?? '',
                    $lead->click_id ?? '',
                    $lead->ip_address ?? '',
                    $lead->referrer ?? '',
                    $lead->user_agent ?? '',
                ];
                fputcsv($file, $row, ';');
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Flash для дубликата заявки. Если у оригинального Lead есть magnet_channel/token —
     * строим deep-link исходного канала, чтобы юзер вернулся туда же, где получил магнит.
     * Иначе — отдаём только базовые поля, шаблон сам деградирует на хардкод-кнопку Telegram.
     */
    private function buildDuplicateFlash(Lead $existing): array
    {
        $flash = [
            'is_duplicate' => true,
            'duplicate_email' => $existing->email,
        ];

        // Подписка на статусы (H3339): дубликат видит тот же полный блок каналов,
        // что и новая заявка — кнопки не зависят от того, каким путём он пришёл.
        if ($existing->magnet_token && ($landing = $existing->landingPage) !== null && $landing->hasStatusBlock()) {
            $flash['status_connect_links'] = app(LeadFlashBuilder::class)->statusConnectLinks($existing, $landing);

            return $flash;
        }

        if (! $existing->magnet_channel || ! $existing->magnet_token) {
            return $flash;
        }

        $manager = app(DeliveryChannelManager::class);
        if (! $manager->has($existing->magnet_channel)) {
            return $flash;
        }

        $flash['duplicate_channel'] = $existing->magnet_channel;
        $flash['duplicate_deep_link'] = $manager->get($existing->magnet_channel)
            ->buildDeepLink($existing->magnet_token);

        return $flash;
    }

    /**
     * Канал binding-токена: распознанный из social || дефолт лендинга.
     */
    private function channelFromSocial(?string $social, LandingPage $landing): string
    {
        $parsed = app(SocialChannelParser::class)->parse($social);

        // Telegram — конечный fallback: landing мог быть создан до миграции 2026_05_16_000002
        // или сохранён через Filament с null в Select (поле visible-by-toggle не загружает default при edit).
        return $parsed['channel'] ?? $landing->lead_magnet_default_channel ?? 'telegram';
    }

    /**
     * Канал для one-click заявки: мессенджер, который реально есть в профиле,
     * иначе дефолт лендинга/telegram. Кнопки всё равно показываются все —
     * канал влияет только на подсветку дефолта и авто-редирект магнита.
     */
    private function channelFromProfile(User $user, LandingPage $landing): string
    {
        return match (true) {
            filled($user->telegram_username) => 'telegram',
            filled($user->vk_id) => 'vk',
            filled($user->max_user_id) => 'max',
            default => $landing->lead_magnet_default_channel ?? 'telegram',
        };
    }

    /**
     * Привязывает к лиду уникальный magnet_token и канал доставки.
     *
     * H3339: magnet_* — generic binding (вариант A, рулинг MG): один и тот же
     * токен обслуживает и файл-магнит, и подписку на статусы курса; файл при
     * этом доставляется только лендингам с настоящим магнитом (гейт
     * hasLeadMagnet() остаётся в пути выдачи). Дедуп привязок — на стороне
     * вебхука: чат каждого канала пишется в Lead однократно.
     */
    private function attachBinding(Lead $lead, LandingPage $landing, string $channel): void
    {
        if ($lead->magnet_token) {
            return;
        }

        // Полагаемся на UNIQUE index magnet_token + retry на коллизии —
        // do/while с exists() не атомарен. Коллизии при 62^12 практически невозможны,
        // но 3 попытки страхуют любой край.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $lead->update([
                    'magnet_token' => Str::random(12),
                    'magnet_channel' => $channel,
                ]);

                return;
            } catch (QueryException $e) {
                // 1062 (MySQL) / 23000 (SQLite) — duplicate key. Любая другая ошибка пробрасывается.
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException("Не удалось сгенерировать уникальный magnet_token для Lead #{$lead->id}");
    }

    /**
     * Flash «Подключить уведомления» для success-flash лендинга (one-click).
     * Пустой массив — блок не показываем (нет status_block или binding).
     *
     * @return array<string, mixed>
     */
    private function statusFlash(Lead $lead, ?LandingPage $landing): array
    {
        if (! $landing || ! $landing->hasStatusBlock() || ! $lead->magnet_token) {
            return [];
        }

        return [
            'status_connect_links' => app(LeadFlashBuilder::class)->statusConnectLinks($lead, $landing),
        ];
    }
}
