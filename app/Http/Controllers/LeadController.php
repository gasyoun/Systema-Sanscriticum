<?php

namespace App\Http\Controllers;

use App\Models\LandingPage;
use App\Models\Lead;
use App\Services\Leads\LeadFlashBuilder;
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
            'name' => 'required|string|max:255',
            'contact' => 'required|string',
            'email' => 'required|email',
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

        if (! empty($data['form_name'])) {
            $existingUtm = $data['utm_content'] ?? '';
            $data['utm_content'] = '['.$data['form_name'].'] '.$existingUtm;
        }

        $isDuplicate = false;
        if (! empty($data['landing_page_id'])) {
            $isDuplicate = Lead::where('email', $data['email'])
                ->where('landing_page_id', $data['landing_page_id'])
                ->exists();
        } elseif (! empty($data['source_article_slug'])) {
            $isDuplicate = Lead::where('email', $data['email'])
                ->where('source_article_slug', $data['source_article_slug'])
                ->exists();
        }

        if ($isDuplicate) {
            return redirect()->route('thank.you')->with([
                'is_duplicate' => true,
                'duplicate_email' => $data['email'],
            ]);
        }

        $lead = Lead::create($data);

        $landing = null;
        if (! empty($data['landing_page_id'])) {
            $landing = LandingPage::find($data['landing_page_id']);
        }

        // Lead-magnet: если у лендинга включён магнит — привязываем токен и канал.
        if ($landing && $landing->hasLeadMagnet()) {
            $this->attachMagnet($lead, $landing, $validated['social'] ?? null);
        }

        return redirect()->route('thank.you')
            ->with($flashBuilder->build($lead, $landing, $validated));
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
     * Привязывает к лиду уникальный magnet_token и канал доставки.
     * Канал = распознанный из social || дефолт лендинга.
     */
    private function attachMagnet(Lead $lead, LandingPage $landing, ?string $social): void
    {
        $parsed = app(SocialChannelParser::class)->parse($social);
        $channel = $parsed['channel'] ?? $landing->lead_magnet_default_channel;

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
}
