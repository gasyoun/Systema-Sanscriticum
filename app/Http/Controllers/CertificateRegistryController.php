<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Публичный реестр выданных документов (сертификаты и справки) — /sertifikat
 * (старый адрес /sertifikaty оставлен 301-редиректом).
 * Три представления: хронологически (default), алфавитно, по группам; фильтры
 * по курсу и году. Полные ФИО — решение владельца (как бумажные реестры вузов);
 * строка ведёт на страницу верификации /verify/{number}. Баллы экзамена в
 * списке не показываются — только на verify.
 */
class CertificateRegistryController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'sort' => ['nullable', 'in:name,date,group'],
            'course' => ['nullable', 'string', 'max:255', 'exists:courses,slug'],
            'year' => ['nullable', 'integer', 'min:2020', 'max:'.(now()->year + 1)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $sort = $validated['sort'] ?? 'date';

        $query = Certificate::query()
            ->select([
                'certificates.id', 'certificates.user_id', 'certificates.course_id',
                'certificates.student_name', 'certificates.course_title',
                'certificates.document_type', 'certificates.number',
                'certificates.issued_at', 'certificates.group_id',
            ])
            ->with(['user:id,name', 'course:id,title', 'group:id,name']);

        if (! empty($validated['course'])) {
            $course = Course::resolveBySlug($validated['course']);
            $query->where('certificates.course_id', $course?->id ?? 0);
        }

        if (! empty($validated['year'])) {
            $query->whereYear('certificates.issued_at', (int) $validated['year']);
        }

        match ($sort) {
            // student_name у старых строк бэкафилится миграцией; фолбэк COALESCE
            // на users.name — на случай ручных строк без снапшота.
            'name' => $query
                ->leftJoin('users', 'users.id', '=', 'certificates.user_id')
                ->orderByRaw("COALESCE(NULLIF(certificates.student_name, ''), users.name)"),
            'group' => $query
                ->leftJoin('groups', 'groups.id', '=', 'certificates.group_id')
                ->orderByRaw('groups.name IS NULL') // группы сначала, «—» в конец
                ->orderBy('groups.name')
                ->orderBy('certificates.student_name'),
            default => $query
                ->orderByDesc('certificates.issued_at')
                ->orderByDesc('certificates.id'),
        };

        $certificates = $query->paginate(50)->withQueryString();

        $courses = Course::query()
            ->whereIn('id', Certificate::query()->select('course_id'))
            ->orderBy('title')
            ->get(['id', 'slug', 'title']);

        // Годы для фильтра — портируемо между MySQL (прод) и SQLite (тесты).
        $years = Certificate::query()
            ->whereNotNull('issued_at')
            ->pluck('issued_at')
            ->map(fn ($d) => Carbon::parse($d)->year)
            ->unique()
            ->sortDesc()
            ->values();

        return view('certificate.registry', [
            'certificates' => $certificates,
            'courses' => $courses,
            'years' => $years,
            'sort' => $sort,
            'filters' => [
                'course' => $validated['course'] ?? null,
                'year' => $validated['year'] ?? null,
            ],
        ]);
    }
}
