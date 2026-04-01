<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LandingPage;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    // =========================================================
    // 1. МЕТОД СОХРАНЕНИЯ ЗАЯВКИ
    // =========================================================
    public function store(Request $request)
    {
        // 1. Валидация данных
        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'contact'         => 'required|string',
            'email'           => 'required|email',
            'landing_page_id' => 'nullable|integer',
            'form_name'       => 'nullable|string', // <-- НОВОЕ: Название формы
            // UTM метки и прочее
            'utm_source'      => 'nullable|string',
            'utm_medium'      => 'nullable|string',
            'utm_campaign'    => 'nullable|string',
            'utm_content'     => 'nullable|string',
            'utm_term'        => 'nullable|string',
            'click_id'        => 'nullable|string',
            'referrer'        => 'nullable|string',
            'is_promo_agreed' => 'nullable', 
        ]);

        // Подготовка данных для сохранения
        $data = $request->all();
        
        // Преобразуем чекбокс "on" в true/false
        $data['is_promo_agreed'] = $request->has('is_promo_agreed');
        
        // Добавляем технические данные
        $data['ip_address'] = $request->ip();
        $data['user_agent'] = $request->userAgent();

        // === ИЗЯЩНЫЙ ХАК ===
        // Если пришло имя формы, дописываем его в utm_content, чтобы не ломать БД
        if (!empty($validated['form_name'])) {
            $existingUtm = $data['utm_content'] ?? '';
            $data['utm_content'] = '[' . $validated['form_name'] . '] ' . $existingUtm;
        }

        // 2. Сохраняем лид в базу
        $lead = Lead::create($data);

        // 3. --- ЛОГИКА ДЛЯ ПИКСЕЛЕЙ ---
        $landing = null;
        if ($request->has('landing_page_id')) {
            $landing = LandingPage::find($request->landing_page_id);
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

        // 4. Редирект на страницу спасибо
        return redirect()->route('thank.you')->with($flashData);
    }

    // =========================================================
    // 2. МЕТОД ЭКСПОРТА В EXCEL
    // =========================================================
    public function export()
    {
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