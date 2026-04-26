<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Гарантируем, что пользователь, ранее имевший доступ к Filament
        // через хардкод email-проверку в canAccessPanel(), сохранит доступ
        // после её удаления. Идемпотентно: если такого юзера нет — no-op.
        $emails = array_filter([
            config('services.admin.email'),
            'pe4kinsmart@gmail.com',
        ]);

        if (empty($emails)) {
            return;
        }

        DB::table('users')
            ->whereIn('email', $emails)
            ->update(['is_admin' => true]);
    }

    public function down(): void
    {
        // Намеренно ничего не делаем: откат поднял бы вопрос, кому
        // вернуть права, и легко стереть флаг у настоящего админа.
    }
};
