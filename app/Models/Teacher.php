<?php

namespace App\Models;

use App\Services\TeacherSalaryService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Teacher extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'email', 'phone', 'telegram', 'vk', 'requisites', 'bio',
        // Фото для блока «Преподаватель» на продающей странице курса.
        'photo_path',
        // Валюта выплаты через PayPal (EUR/USD/INR); null = только ₽. Остаток в кабинете всегда в ₽.
        'payout_currency',
    ];

    // Один преподаватель может вести много курсов (как ОСНОВНОЙ — teacher_id).
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    /** Курсы, где преподаватель — со-препод (pivot course_teacher, со своими условиями ЗП). */
    public function coTaughtCourses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_teacher')
            ->withPivot(['salary_type', 'salary_value'])
            ->withTimestamps();
    }

    /**
     * Имя преподавателя по неточной форме (MG 02-09-2026): «Екатерина Костина»
     * → «Костина Екатерина Александровна». Точное совпадение приоритетно, иначе
     * word-set матч: слова запроса должны покрыть ≥2 слова ФИО (фамилия+имя).
     * null — не нашлось (вызывающий решает 404).
     */
    public static function resolveByName(string $query): ?self
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');

        if ($query === '') {
            return null;
        }

        $exact = self::where('name', $query)->first();
        if ($exact !== null) {
            return $exact;
        }

        $words = collect(explode(' ', mb_strtolower($query)))
            ->filter(fn ($w) => mb_strlen($w) >= 3)->values();

        if ($words->isEmpty()) {
            return null;
        }

        return self::query()->get()
            ->first(function (self $t) use ($words) {
                $nameWords = collect(explode(' ', mb_strtolower($t->name)));

                return $words->filter(fn ($w) => $nameWords->contains(fn ($n) => str_starts_with($n, $w)))->count() >= 2;
            });
    }

    /**
     * Все курсы преподавателя (основные + со-преподаваемые), без дублей. Для
     * расчёта ЗП: по каждому берутся эффективные условия Course::salaryTermsFor().
     *
     * @return Collection<int, Course>
     */
    public function allTaughtCourses(): Collection
    {
        return $this->courses->merge($this->coTaughtCourses)->unique('id')->values();
    }

    /**
     * Группы, которые ведёт преподаватель (H1426): любая группа, среди курсов
     * которой есть курс, ведомый им (основной или со-препод), считается на лету —
     * без денормализованной таблицы/колонки (decision 6, ARCHITECTURE doc).
     *
     * @return Collection<int, Group>
     */
    public function groupsLed(): Collection
    {
        return Group::whereHas('courses', fn ($q) => $q->forTeacher($this->id))
            ->with('courses.categories')
            ->get();
    }

    // ==========================================
    // АВТОМАТИЧЕСКИЙ РАСЧЕТ ЗАРПЛАТЫ ПРЕПОДАВАТЕЛЯ
    // ==========================================
    // Связь: История выплат преподавателю
    public function payouts()
    {
        return $this->hasMany(TeacherPayout::class);
    }

    // Закрытые периоды ЗП (месяцы, по которым уже рассчитались).
    public function closedPeriods(): HasMany
    {
        return $this->hasMany(SalaryClosedPeriod::class);
    }

    // Умный расчёт: если передать даты, посчитает за период, иначе — за всё время.
    // Тонкая обёртка над TeacherSalaryService — единым источником правды по
    // начислению ЗП (корректная база: без депозитов/пробных/расходов, с учётом
    // возвратов). Сигнатура сохранена для back-compat (модалка TeacherResource).
    public function calculateEarnings($startDate = null, $endDate = null): float
    {
        return app(TeacherSalaryService::class)
            ->totalForTeacher($this, $startDate, $endDate);
    }
}
