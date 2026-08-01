<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use RuntimeException;

/** Ошибки кика/бана через @zapisi_ORSbot — текст для Filament notification. */
class ZapisiChatMemberException extends RuntimeException {}
