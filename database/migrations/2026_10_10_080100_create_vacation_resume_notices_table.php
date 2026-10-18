<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H6343: дедуп каскада анонса возобновления после каникул (T−7д / T−1д / ручной
 * анонс куратора) и явка-алерта <50% на первом занятии после паузы. Один ряд =
 * одна фактически/планируемо отправленная волна по группе+фазе+опорному занятию;
 * повторный ежедневный запуск по тому же слоту — no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacation_resume_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            // t7 | t1 | manual | attendance_alert
            $table->string('phase', 32);
            $table->foreignId('schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('recipients')->default(0);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['group_id', 'phase', 'schedule_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacation_resume_notices');
    }
};
