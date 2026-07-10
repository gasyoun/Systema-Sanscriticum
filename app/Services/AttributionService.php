<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Middleware\CaptureAttribution;
use App\Models\Lead;
use App\Models\User;

/**
 * Тикет A1: подхватывает UTM/реферер, накопленные CaptureAttribution в сессии
 * с первого визита, и пишет их в только что созданный аккаунт + мэтчит
 * существующий Lead по email (growth-идея 8 роадмапа — не плодить второй
 * трекинг источника, связать с уже существующим).
 */
class AttributionService
{
    /**
     * Белый список самоотчётного «источника прихода» (H476). Ключи хранятся в
     * users.signup_source; русские подписи живут в partials/signup-source-select.
     * Дополняет UTM-атрибуцию (H267): сарафан/поиск/«давно подписан» приходят
     * без меток — их видит только сам студент.
     */
    public const SIGNUP_SOURCES = [
        'telegram',
        'youtube',
        'vk',
        'search',
        'friend',
        'article',
        'other',
    ];

    /**
     * Вызывается сразу после User::create() во всех guest-registration
     * веток (checkout/deposit/trial/paypal-claim/social-auth). Идемпотентно
     * для нового пользователя: если в сессии ничего нет — просто мэтчит лид.
     */
    public function applyToNewUser(User $user): void
    {
        $session = session(CaptureAttribution::SESSION_KEY, []);

        $attrs = array_intersect_key($session, array_flip(CaptureAttribution::UTM_FIELDS));
        // Источник (сессия/лид) хранит HTTP-реферер под ключом 'referrer'; на User
        // он ложится в столбец http_referrer (см. rename-миграцию — столбец
        // referrer затенял relation referrer()).
        if (isset($session['referrer'])) {
            $attrs['http_referrer'] = $session['referrer'];
        }

        $lead = $this->matchLead($user);
        if ($lead) {
            $attrs['lead_id'] = $lead->id;

            // Лид уже нёс UTM/реферер (LeadController) — используем его как
            // fallback, если сессия почему-то пуста (например, регистрация в
            // отдельной вкладке без query-параметров лендинга).
            foreach (CaptureAttribution::UTM_FIELDS as $field) {
                if (! isset($attrs[$field]) && filled($lead->$field)) {
                    $attrs[$field] = $lead->$field;
                }
            }
            if (! isset($attrs['http_referrer']) && filled($lead->referrer)) {
                $attrs['http_referrer'] = $lead->referrer;
            }
        }

        if ($attrs === []) {
            return;
        }

        $user->forceFill($attrs)->save();
    }

    /** Необязательное поле онбординга — не блокирует регистрацию/оплату. */
    public function applyBirthYear(User $user, mixed $birthYear): void
    {
        $birthYear = (int) $birthYear;
        if ($birthYear < 1900 || $birthYear > (int) now()->format('Y')) {
            return;
        }

        $user->forceFill(['birth_year' => $birthYear])->save();
    }

    /** Необязательное поле «откуда о нас узнали» — не блокирует регистрацию/оплату. */
    public function applySignupSource(User $user, mixed $source): void
    {
        if (! is_string($source) || ! in_array($source, self::SIGNUP_SOURCES, true)) {
            return;
        }

        $user->forceFill(['signup_source' => $source])->save();
    }

    /** Последний Lead с этим email — тот же человек, что оставлял заявку на лендинге. */
    private function matchLead(User $user): ?Lead
    {
        if (blank($user->email)) {
            return null;
        }

        return Lead::where('email', $user->email)->latest()->first();
    }
}
