<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LandingPage;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    // =========================================================
    // 1. МЕТОД СОХРАНЕНИЯ ЗАЯВКИ (БЕЗОПАСНЫЙ)
    // =========================================================
    public function store(Request $request)
    {
        // 1. Валидация данных
        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'contact'         => 'required|string',
            'email'           => 'required|email',
            'landing_page_id' => 'nullable|integer',
            'form_name'       => 'nullable|string',
            'utm_source'      => 'nullable|string',
            'utm_medium'      => 'nullable|string',
            'utm_campaign'    => 'nullable|string',
            'utm_content'     => 'nullable|string',
            'utm_term'        => 'nullable|string',
            'click_id'        => 'nullable|string',
            'referrer'        => 'nullable|string',
            'is_promo_agreed' => 'nullable',
            'source_article_id'   => 'nullable|integer',
            'source_article_slug' => 'nullable|string|max:255',
        ]);

        // === ИСПРАВЛЕНИЕ УЯЗВИМОСТИ ===
        // Берем ТОЛЬКО проверенные данные, отсекая любой хакерский мусор из запроса
        $data = $validated;
        
        // Преобразуем чекбокс "on" в true/false
        $data['is_promo_agreed'] = $request->has('is_promo_agreed');
        
        // Добавляем технические данные (это безопасно, так как мы берем их из сервера, а не от юзера)
        $data['ip_address'] = $request->ip();
        $data['user_agent'] = $request->userAgent();

        // === ИЗЯЩНЫЙ ХАК ===
        // Если пришло имя формы, дописываем его в utm_content
        if (!empty($data['form_name'])) {
            $existingUtm = $data['utm_content'] ?? '';
            $data['utm_content'] = '[' . $data['form_name'] . '] ' . $existingUtm;
        }

        // 2. БЕЗОПАСНО сохраняем лид в базу
        $lead = Lead::create($data);

        // 3. --- ЛОГИКА ДЛЯ ПИКСЕЛЕЙ ---
        $landing = null;
        if (!empty($data['landing_page_id'])) {
            $landing = LandingPage::find($data['landing_page_id']);
        }

        $flashData = [
            'success' => 'Ваша заявка успешно отправлена! Менеджер свяжется с вами.',
        ];

        if ($landing) {
            if (!empty($landing->yandex_metrika_id)) {
                $flashData['yandex_id'] = $landing->yandex_metrika_id;
            }
            if (!empty($landing->vk_pixel_id)) {
                $flashData['vk_id'] = $landing->vk_pixel_id;
            }
            $flashData['conversion_event'] = 'lead'; 
        }
        
        // === Логика для лидов из блога ===
if (empty($landing) && !empty($validated['source_article_id'])) {
    $marketing = \App\Models\MarketingSetting::first();
    
    if ($marketing) {
        if (!empty($marketing->blog_yandex_metrika_id)) {
            $flashData['yandex_id'] = $marketing->blog_yandex_metrika_id;
        }
        if (!empty($marketing->blog_vk_pixel_id)) {
            $flashData['vk_id'] = $marketing->blog_vk_pixel_id;
        }
        $flashData['conversion_event'] = 'lead_from_article';
    }
}

        // 4. Редирект на страницу спасибо
        return redirect()->route('thank.you')->with($flashData);
    }

    // =========================================================
    // 2. МЕТОД ЭКСПОРТА В EXCEL
    // =========================================================
    public function export()
    {
        // Отсекаем всех, кто не является администратором
            abort_unless(auth()->check() && auth()->user()->is_admin, 403, 'Доступ к выгрузке запрещен.');
        
        $fileName = 'leads_full_' . date('Y-m-d_H-i') . '.csv';
        
        $leads = Lead::with('landingPage')->latest()->get();

        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=$fileName",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $columns = [
            'ID', 'Дата', 'Лендинг', 'Имя', 'Телефон', 'Email', 'Рассылка',
            'UTM Source', 'UTM Medium', 'UTM Campaign', 'UTM Content (Форма)', // Изменил заголовок для наглядности
            'UTM Term', 'Click ID', 'IP Адрес', 'Referrer', 'User Agent'
        ];

        $callback = function() use($leads, $columns) {
            $file = fopen('php://output', 'w');
            fputs($file, "\xEF\xBB\xBF"); 
            fputcsv($file, $columns, ';');

            foreach ($leads as $lead) {
                $row = [
                    $lead->id,
                    $lead->created_at->format('d.m.Y H:i'),
                    $lead->landingPage ? $lead->landingPage->title : 'Неизвестно',
                    $lead->name,
                    $lead->contact,
                    $lead->email ?? '',
                    $lead->is_promo_agreed ? 'Да' : 'Нет',
                    $lead->utm_source ?? '',
                    $lead->utm_medium ?? '',
                    $lead->utm_campaign ?? '',
                    $lead->utm_content ?? '', // Сюда упадет [Форма: Пробное занятие]
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
}