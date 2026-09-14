<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Заявка студента «я заплатил преподавателю напрямую» (H4627, зеркало
 * StoreBankClaimRequest): обязательная тройка для ручной сверки —
 * преподаватель-получатель, отправитель, дата; сумма+валюта и файл чека —
 * усилители сверки. Гость дополнительно даёт имя+email. Авто-доверия нет:
 * каждую заявку сверяет куратор по выписке преподавателя.
 */
final class StoreTeacherPayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            // Кому перевёл деньги — преподаватель, на чьём личном счёте пришло
            // поступление. От него зависит вычет из гонорара (received_by_teacher_id).
            'teacher_id' => ['required', Rule::exists('teachers', 'id')],
            'foreign_amount' => ['required', 'numeric', 'min:1', 'max:1000000'],
            // Счёт преподавателя (Лейтан) — евро; другие валюты не запрещаем.
            'foreign_currency' => ['required', 'in:EUR,USD,GBP'],
            // С чьего счёта ушёл перевод — для сверки с выпиской получателя.
            'sender_name' => ['required', 'string', 'max:255'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            // Банковская референция перевода (если есть в подтверждении).
            'reference' => ['nullable', 'string', 'max:100'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];

        if (! auth()->check()) {
            $rules['name'] = ['required', 'string', 'max:255'];
            $rules['email'] = ['required', 'email', 'max:255'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'teacher_id' => 'преподаватель',
            'foreign_amount' => 'сумма оплаты',
            'foreign_currency' => 'валюта',
            'sender_name' => 'отправитель перевода',
            'paid_on' => 'дата оплаты',
            'reference' => 'референция перевода',
            'proof' => 'файл подтверждения',
            'comment' => 'комментарий',
        ];
    }

    public function messages(): array
    {
        return [
            'teacher_id.required' => 'Укажите преподавателя, которому вы перевели оплату.',
            'sender_name.required' => 'Укажите имя/счёт отправителя — так куратор найдёт перевод в выписке.',
            'paid_on.required' => 'Укажите дату перевода — без неё сверка по выписке занимает часы.',
            'paid_on.before_or_equal' => 'Дата оплаты не может быть в будущем.',
        ];
    }
}
