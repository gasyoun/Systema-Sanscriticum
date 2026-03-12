<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LandingPage; // Важно: модель лендинга подключена
use Illuminate\Http\Request;

class LeadController extends Controller
{
    // =========================================================
    // 1. МЕТОД СОХРАНЕНИЯ ЗАЯВКИ (СЮДА ПРИЛЕТАЮТ ДАННЫЕ С ФОРМЫ)
    // =========================================================
    public function store(Request $request)
    {

        // 1. Валидация данных
        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'contact'         => 'required|string',
            'email'           => 'required|email',
            'landing_page_id' => 'nullable|integer',
            // UTM метки и прочее
            'utm_source'      => 'nullable|string',
            'utm_medium'      => 'nullable|string',
            'utm_campaign'    => 'nullable|string',
            'utm_content'     => 'nullable|string',
            'utm_term'        => 'nullable|string',
            'click_id'        => 'nullable|string',
            'referrer'        => 'nullable|string',
            'is_promo_agreed' => 'nullable', // Чекбокс рассылки
        ]);

        // Подготовка данных для сохранения
        $data = $request->all();
        
        // Преобразуем чекбокс "on" в true/false
        $data['is_promo_agreed'] = $request->has('is_promo_agreed');
        
        // Добавляем технические данные
        $data['ip_address'] = $request->ip();
        $data['user_agent'] = $request->userAgent();

        // 2. Сохраняем лид в базу
        $lead = Lead::create($data);

        // 3. --- ЛОГИКА ДЛЯ ПИКСЕЛЕЙ ---
        
        // Ищем лендинг по ID
        $landing = null;
        if ($request->has('landing_page_id')) {
            $landing = LandingPage::find($request->landing_page_id);
        }

        // Подготавливаем данные для страницы "Спасибо"
        $flashData = [
            'success' => 'Ваша заявка успешно отправлена! Менеджер свяжется с вами.',
        ];

        // Если нашли лендинг, берем из него ID счетчиков
        if ($landing) {
            if (!empty($landing->yandex_metrika_id)) {
                $flashData['yandex_id'] = $landing->yandex_metrika_id;
            }
            if (!empty($landing->vk_pixel_id)) {
                $flashData['vk_id'] = $landing->vk_pixel_id;
            }
            
            // Название события конверсии (Lead)
            $flashData['conversion_event'] = 'lead'; 
        }

        // 4. Редирект на страницу спасибо с данными
        return redirect()->route('thank.you')->with($flashData);
    }

    // =========================================================
    // 2. МЕТОД ЭКСПОРТА В EXCEL (ВАШ СТАРЫЙ КОД)
    // =========================================================
    public function export()
    {
        $fileName = 'leads_full_' . date('Y-m-d_H-i') . '.csv';
        
        // Берем все лиды с подгрузкой лендинга
        $leads = Lead::with('landingPage')->latest()->get();

        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=$fileName",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $columns = [
            'ID', 
            'Дата', 
            'Лендинг', 
            'Имя', 
            'Телефон', 
            'Email',
            'Рассылка',
            'UTM Source', 
            'UTM Medium',
            'UTM Campaign', 
            'UTM Content',
            'UTM Term',
            'Click ID',
            'IP Адрес',
            'Referrer',
            'User Agent'
        ];

        $callback = function() use($leads, $columns) {
            $file = fopen('php://output', 'w');
            
            // BOM для Excel
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
                    
                    // Маркетинг
                    $lead->utm_source ?? '',
                    $lead->utm_medium ?? '',
                    $lead->utm_campaign ?? '',
                    $lead->utm_content ?? '',
                    $lead->utm_term ?? '',
                    $lead->click_id ?? '',
                    
                    // Техничка
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