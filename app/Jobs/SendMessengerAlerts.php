<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendMessengerAlerts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $user;
    public $text;
    public $sendToTelegram;
    public $sendToVk;
    public $imagePath; // <-- Поменяли название

    public function __construct(User $user, $text, $sendToTelegram = true, $sendToVk = true, $imagePath = null)
    {
        $this->user = $user;
        $this->text = $text;
        $this->sendToTelegram = $sendToTelegram;
        $this->sendToVk = $sendToVk;
        $this->imagePath = $imagePath;
    }

    public function handle(): void
    {
        // === 1. БАЗОВАЯ ОЧИСТКА ДЛЯ ВСЕХ МЕССЕНДЖЕРОВ ===
        $text = $this->text;
        
        // Превращаем закрывающие теги блоков в ДВОЙНОЙ перенос строки (абзац)
        $text = str_replace(['</p>', '</h1>', '</h2>', '</h3>', '</h4>', '</ul>', '</ol>'], "\n\n", $text);
        
        // Превращаем одинарные переносы и списки в ОДИНАРНЫЙ перенос
        $text = str_replace(['<br>', '<br/>', '<br />'], "\n", $text);
        $text = str_replace(['<li>', '<li >'], "• ", $text); // Делаем красивые маркеры для списков
        $text = str_replace('</li>', "\n", $text);
        
        // Убираем неразрывные пробелы и декодируем спецсимволы (типа &quot;)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = str_replace("\xC2\xA0", " ", $text); 


        // === 2. ФОРМАТИРОВАНИЕ СПЕЦИАЛЬНО ДЛЯ TELEGRAM ===
        // Разрешенные теги ТГ (остальное вырезаем):
        $tgText = strip_tags($text, '<b><strong><i><em><u><ins><s><strike><del><a><code><pre>');
        // Зачищаем лишние пустые строки (оставляем максимум две подряд)
        $tgText = preg_replace("/[\r\n]{3,}/", "\n\n", trim($tgText));


        // === 3. ФОРМАТИРОВАНИЕ СПЕЦИАЛЬНО ДЛЯ VK ===
        $vkText = $text;
        // МАГИЯ ССЫЛОК: Находим <a href="URL">ТЕКСТ</a> и превращаем в "ТЕКСТ (URL)"
        $vkText = preg_replace('/<a\s+(?:[^>]*?\s+)?href=["\'](https?:\/\/[^"\']+)["\'][^>]*>(.*?)<\/a>/is', '$2 ($1)', $vkText);
        // ЖЕСТКО сносим вообще все оставшиеся теги (ВК их не переварит)
        $vkText = strip_tags($vkText);
        $vkText = preg_replace("/[\r\n]{3,}/", "\n\n", trim($vkText));


        // === 4. ОТПРАВКА ===
        if ($this->sendToTelegram && $this->user->telegram_id) {
            $this->user->sendTelegramMessage($tgText, $this->imagePath); // Передаем путь
        }

        if ($this->sendToVk && $this->user->vk_id) {
            $this->user->sendVkMessage($vkText);
        }
    }
}