<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Единый контракт email на путях приёма: тот же паттерн, что CHECK
 * users_email_valid / leads_email_valid (MG 04-10-2026). Стандартное
 * правило `email` (RFC) пропускает домены без точки — класс мусора,
 * от которого гвард; без этого правила форма отдаёт 500 и теряет лид.
 */
final class HouseEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || preg_match('/'.User::EMAIL_PATTERN.'/i', $value) !== 1) {
            $fail('Укажите корректный email — вида имя@домен.зона.');
        }
    }
}
