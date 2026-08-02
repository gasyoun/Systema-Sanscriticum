<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Бэкафилл снапшота ФИО для старых ручных сертификатов (до появления
     * снапшотов student_name был NULL): публичному реестру нужна сортировка
     * по имени. Идемпотентно: трогает только пустые значения.
     */
    public function up(): void
    {
        $rows = DB::table('certificates')
            ->join('users', 'users.id', '=', 'certificates.user_id')
            ->where(fn ($q) => $q->whereNull('certificates.student_name')->orWhere('certificates.student_name', ''))
            ->select('certificates.id', 'users.name')
            ->get();

        foreach ($rows as $row) {
            DB::table('certificates')->where('id', $row->id)->update(['student_name' => $row->name]);
        }
    }

    public function down(): void
    {
        // Снапшоты не откатываем: данные корректны и без миграции невосстановимы.
    }
};
