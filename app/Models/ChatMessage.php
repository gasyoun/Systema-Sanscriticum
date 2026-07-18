<?php

namespace App\Models;

use App\Support\SupportText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'support_conversation_id',
        'user_id',
        'role',
        'answered_by',
        'text',
        'is_read',
        'ai_state',
        'source',
    ];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    /**
     * Безопасный HTML текста для web-чата (helpdesk). Сообщения бота приходят с
     * Telegram-форматированием (<b>, <i>…); здесь экранируем всё, затем возвращаем
     * как реальный HTML только простой whitelist тегов без атрибутов — чтобы
     * «<b>Ваши группы</b>» рендерилось жирным, а не показывалось буквально.
     */
    public function htmlForWeb(): string
    {
        return SupportText::safeHtml($this->text);
    }

    // Сообщение принадлежит студенту
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Кто из кураторов/админов отправил ответ (для роли curator).
    public function answeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }
}
